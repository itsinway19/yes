<?php

namespace App\Providers\Ads\Snapchat;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderQueuePolicy;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReadBudgetExhausted;
use App\Providers\Ads\ReportWindow;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Ramsey\Uuid\Uuid;

/** Bounded Snapchat report reads. Only complete native campaign ranges become acceptance progress. */
final class SnapchatReadJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    private int $number = 0;

    private string $rootCommand;

    private ?DateTimeImmutable $notBefore = null;

    private bool $asynchronous = false;

    private function __construct(private int $actorId, private int $actorGeneration,
        #[\SensitiveParameter] private string $sessionId, private string $workspacePublicId,
        private ProviderScope $scope, private ReportWindow $window, private string $stream, private string $command,
        private int $generation, private int $accessVersion, private string $version,
        private DateTimeImmutable $deadline, private string $configuration, bool $asynchronous = false)
    {
        $this->rootCommand = $command;
        $this->asynchronous = $asynchronous;
        $this->onQueue((new ProviderQueuePolicy)->queue($scope));
        $this->afterCommit();
    }

    public static function capture(Request $request, ProviderScope $scope, ReportWindow $window,
        string $stream, string $command, int $generation, int $accessVersion, ?DateTimeImmutable $deadline = null, bool $asynchronous = false): self
    {
        if (! $request->user() || DB::connection()->transactionLevel() !== 1 || $scope->provider !== Provider::Snapchat
            || $scope->operation !== 'report.read' || ! Str::isUuid($command) || $generation < 0
            || ! preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $stream)) {
            throw new LogicException('Snapchat work requires an exact authorized report command.');
        }
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $deadline ??= $now->modify('+5 minutes');
        if ($deadline <= $now || $deadline > $now->modify('+5 minutes')) {
            throw new LogicException('Snapchat work requires a bounded current execution deadline.');
        }
        $settings = SnapchatReadSettings::configured();
        app(ProviderRequestGuard::class)->requireCurrent($request, $scope, 'provider.read', $accessVersion);

        return new self($request->user()->getAuthIdentifier(), (int) $request->session()->get('a001_generation', 0),
            $request->session()->getId(), DB::table('organization_workspaces')->where('id', $scope->workspaceId)->value('public_id'),
            $scope, $window, $stream, $command, $generation, $accessVersion, $settings->version,
            $deadline, $settings->binding(), $asynchronous);
    }

    public function middleware(): array
    {
        return (new ProviderQueuePolicy)->middleware($this->scope, 'snapchat.read:'.$this->stream, $this->timeout, 2, 75);
    }

    public function backoff(): array
    {
        return [5, 15];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return $this->deadline;
    }

    public function handle(PrincipalContext $principals, SnapchatObservationReader $reader, SnapchatReadExecution $execution): void
    {
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        if ($now >= $this->deadline) {
            throw new ReadBudgetExhausted('deadline_exhausted');
        }
        if ($this->notBefore !== null && $now < $this->notBefore) {
            $this->release(max(1, $this->notBefore->getTimestamp() - $now->getTimestamp()));

            return;
        }
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Snapchat worker must own its transaction.');
        }
        $settings = SnapchatReadSettings::configured();
        if ($settings->version !== $this->version || $settings->binding() !== $this->configuration) {
            throw new AuthorizationException('Snapchat queued API configuration changed.');
        }
        $actor = User::findOrFail($this->actorId);
        $request = Request::create('/workspaces/'.$this->workspacePublicId);
        $request->setUserResolver(fn () => $actor);
        $session = new Store('bb2-snapchat-worker', new ArraySessionHandler(120));
        $session->setId($this->sessionId);
        $session->put('a001_generation', $this->actorGeneration);
        $request->setLaravelSession($session);
        DB::transaction(function () use ($request, $actor, $principals, $reader, $execution): void {
            $principals->activate($actor);
            DB::selectOne('SELECT builderad_select_workspace_context(?)', [$this->scope->workspaceId]);
            if ((int) DB::table('principal_lifecycles')->where('user_id', $this->actorId)->value('generation') !== $this->actorGeneration
                || DB::table('organization_workspaces')->where('id', $this->scope->workspaceId)->value('public_id') !== $this->workspacePublicId) {
                throw new AuthorizationException('Snapchat queued actor or Workspace changed.');
            }
            SnapchatCacheBinding::context(app()->runningUnitTests() ? Cache::store() : Cache::store('redis'), $request, $this->scope, $this->accessVersion, hash('sha256', $this->version));
            $fingerprintFields = [$this->window->jsonSerialize(), $this->version,
                $this->rootCommand, $this->number, $this->deadline->format(DATE_ATOM)];
            if ($this->asynchronous) {
                $fingerprintFields[] = 'EXPLICIT_ASYNC_METADATA';
            }
            $fingerprint = hash('sha256', json_encode($fingerprintFields, JSON_THROW_ON_ERROR));
            $result = $execution->execute($request, $this->scope, $this->stream, $this->command,
                $this->accessVersion, $this->generation, $fingerprint, function () use ($request, $reader): array {
                    $at = CarbonImmutable::now()->toDateTimeImmutable();
                    $budget = new ProviderReadBudget($this->scope, $this->stream, 8, 0, 8, 2000,
                        min($at->modify('+30 seconds'), $this->deadline), 1, 5);
                    $attempt = $this->asynchronous
                        ? $reader->asyncAttempt($request, $this->scope, $this->window, $budget,
                            $this->version, $this->stream, $this->rootCommand, $this->command, $this->generation, $this->accessVersion, $this->deadline)
                        : $reader->attempt($request, $this->scope, $this->window, $budget,
                            $this->version, $this->stream, $this->command, $this->generation, $this->accessVersion);
                    $next = SnapchatRetryPlan::next($attempt, $this->number, CarbonImmutable::now()->toDateTimeImmutable(), $this->deadline);

                    return [...$attempt->envelope(), 'next_at' => $next?->format(DATE_ATOM)];
                });
            // Durable native decision is committed with its observations before queue delivery is attempted.
            DB::afterCommit(fn () => $this->schedule($result));
        });
    }

    private function schedule(array $result): void
    {
        if (($result['next_at'] ?? null) === null || CarbonImmutable::now() >= $this->deadline) {
            return;
        }
        $next = clone $this;
        // Queue delivery binds a runtime worker/container; only command data belongs in the next payload.
        $next->job = null;
        $next->number++;
        $next->command = Uuid::uuid5(Uuid::NAMESPACE_URL, 'builderad.snapchat.'.$this->scope->key($this->rootCommand).'.'.$next->number)->toString();
        $next->generation = $result['receipt']['generation'];
        $next->notBefore = new DateTimeImmutable($result['next_at']);
        $next->delay($next->notBefore);
        // At-least-once delivery: acknowledge the parent only after dispatch succeeds.
        // A crash may duplicate delivery, but the stable child command's durable receipt
        // prevents another HTTP read or journal acceptance. No cache lease can lose work.
        Bus::dispatch($next);
    }

    public function __debugInfo(): array
    {
        return ['queue' => $this->queue, 'scope' => $this->scope->jsonSerialize(), 'attempt' => $this->number,
            'session' => '[REDACTED]'];
    }
}
