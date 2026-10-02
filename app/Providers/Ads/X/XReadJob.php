<?php

namespace App\Providers\Ads\X;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Providers\Ads\ObservationBatch;
use App\Providers\Ads\ObservationJournal;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderQueuePolicy;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ReadBudgetExhausted;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Ramsey\Uuid\Uuid;

/** One bounded synchronous batch. Committed replay uses an encrypted receipt before any native HTTP. */
final class XReadJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    // Broker redelivery replays the committed native scheduling decision before HTTP.
    public int $tries = 3;

    private int $number = 0;

    private ?DateTimeImmutable $notBefore = null;

    private string $rootCommand;

    private ?array $ticket = null;

    public int $timeout = 60;

    private function __construct(private int $actorId, private int $actorGeneration,
        #[\SensitiveParameter] private string $sessionId, private string $workspacePublicId,
        private XCampaignReport $report, private string $stream, private string $command,
        private int $generation, private int $accessVersion, private DateTimeImmutable $deadline, private string $configuration)
    {
        $this->rootCommand = $command;
        $this->onQueue((new ProviderQueuePolicy)->queue($report->scope));
        $this->afterCommit();
    }

    public static function capture(Request $request, XCampaignReport $report, string $stream, string $command,
        int $generation, int $accessVersion, ?DateTimeImmutable $deadline = null): self
    {
        if (! $request->user() || ! $request->hasSession() || DB::connection()->transactionLevel() !== 1
            || ! Str::isUuid($command) || $generation < 0 || ! preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $stream)) {
            throw new LogicException('X work requires an exact authorized report command.');
        }
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $deadline ??= $now->modify('+5 minutes');
        if ($deadline <= $now || $deadline > $now->modify('+5 minutes')) {
            throw new LogicException('X work requires a bounded current execution deadline.');
        }
        $settings = XReadSettings::configured();
        app(ProviderRequestGuard::class)->requireCurrent($request, $report->scope, 'provider.read', $accessVersion);

        return new self($request->user()->getAuthIdentifier(), (int) $request->session()->get('a001_generation', 0),
            $request->session()->getId(), DB::table('workspaces')->where('id', $report->scope->workspaceId)->value('public_id'),
            $report, $stream, $command, $generation, $accessVersion, $deadline, $settings->binding());
    }

    public function middleware(): array
    {
        return (new ProviderQueuePolicy)->middleware($this->report->scope, 'x.read:'.$this->stream, $this->timeout, 2, 75);
    }

    public function retryUntil(): DateTimeImmutable
    {
        return $this->deadline;
    }

    public function backoff(): array
    {
        return [5, 15];
    }

    public function handle(PrincipalContext $principals, Factory $http, XReadExecution $execution,
        ObservationJournal $journal, ProviderAccessStore $custody, ProviderRequestGuard $requests): void
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
            throw new LogicException('X worker must own its transaction.');
        }
        $settings = XReadSettings::configured();
        if (! hash_equals($this->configuration, $settings->binding())) {
            throw new AuthorizationException('X queued signing configuration changed.');
        }
        $actor = User::findOrFail($this->actorId);
        $request = Request::create('/workspaces/'.$this->workspacePublicId);
        $request->setUserResolver(fn () => $actor);
        $session = new Store('bb2-x-worker', new ArraySessionHandler(120));
        $session->setId($this->sessionId);
        $session->put('a001_generation', $this->actorGeneration);
        $request->setLaravelSession($session);
        DB::transaction(function () use ($request, $actor, $principals, $http, $execution, $journal, $custody, $requests, $settings): void {
            $principals->activate($actor);
            DB::selectOne('SELECT builderad_select_workspace_context(?)', [$this->report->scope->workspaceId]);
            if ((int) DB::table('principal_lifecycles')->where('user_id', $this->actorId)->value('generation') !== $this->actorGeneration
                || DB::table('workspaces')->where('id', $this->report->scope->workspaceId)->value('public_id') !== $this->workspacePublicId) {
                throw new AuthorizationException('X queued actor or Workspace changed.');
            }
            $fingerprint = hash('sha256', json_encode([$this->report->binding(), $this->configuration,
                $this->deadline->format(DATE_ATOM), $this->rootCommand, $this->number, $this->ticket], JSON_THROW_ON_ERROR));
            $result = $execution->execute($request, $this->report->scope, $this->stream, $this->command,
                $this->accessVersion, $this->generation, $fingerprint, function () use ($request, $http, $journal, $custody, $requests, $settings): array {
                    $at = CarbonImmutable::now()->toDateTimeImmutable();

                    try {
                        if ($this->report->asynchronous) {
                            return $this->advanceAsync($request, $http, $journal, $custody, $requests, $settings, $at);
                        }
                        $receipt = (new XObservationReader($http, $settings->signer(), $journal, $custody, $requests))->read($request, $this->report,
                            new ProviderReadBudget($this->report->scope, $this->stream, 2, 0, 2, 40,
                                min($at->modify('+30 seconds'), $this->deadline), 1, 5),
                            $this->stream, $this->command, $this->generation, $this->accessVersion);

                        return ['receipt' => $receipt, 'failure' => null, 'retry_after_seconds' => null, 'next_at' => null];
                    } catch (XRequestFailed $failure) {
                        // Failed attempts carry zero observations and no native report progress.
                        $receipt = $journal->accept($request, new ObservationBatch($this->report->scope, [], 'FAILED', null, $failure->reason),
                            $this->stream, $this->command, $this->generation, $this->accessVersion,
                            ['api_version' => '12', 'report' => 'X synchronous read scheduling failure',
                                'documentation_basis' => 'https://docs.x.com/x-ads-api/fundamentals/rate-limiting']);
                        $next = XRetryPlan::next($failure->reason, $failure->retryAfterSeconds, $this->number,
                            CarbonImmutable::now()->toDateTimeImmutable(), $this->deadline);
                        if ($this->report->asynchronous && $this->ticket === null && ! in_array($failure->reason, ['x_rate_limited', 'x_native_cooldown'], true)) {
                            // An ambiguous create result is never automatically resubmitted: X provides no native idempotency key.
                            $next = null;
                        }

                        return ['receipt' => $receipt, 'failure' => $failure->reason, 'retry_after_seconds' => $failure->retryAfterSeconds,
                            'next_at' => $next?->format(DATE_ATOM), 'ticket' => $this->ticket];
                    }
                });
            DB::afterCommit(fn () => $this->schedule($result));
        });
    }

    private function schedule(array $result): void
    {
        if (($result['next_at'] ?? null) === null || CarbonImmutable::now() >= $this->deadline) {
            return;
        }
        $next = clone $this;
        $next->job = null;
        $next->number++;
        $next->command = Uuid::uuid5(Uuid::NAMESPACE_URL, 'builderad.x.'.$this->report->scope->key($this->rootCommand).'.'.$next->number)->toString();
        $next->generation = $result['receipt']['generation'];
        $next->ticket = $result['ticket'] ?? null;
        $next->notBefore = new DateTimeImmutable($result['next_at']);
        $next->delay($next->notBefore);
        Bus::dispatch($next);
    }

    private function advanceAsync(Request $request, Factory $http, ObservationJournal $journal, ProviderAccessStore $custody,
        ProviderRequestGuard $requests, XReadSettings $settings, DateTimeImmutable $at): array
    {
        $guard = $requests->bind($request, $this->report->scope, 'provider.read', $this->accessVersion);
        $guard($this->report->scope, 'provider.read');
        (new XAccessStatus)->requireReport($request, $this->report->scope);
        $credentials = $custody->credentials($request, $this->report->scope);
        $cooldown = new XReadCooldown(app()->runningUnitTests() ? Cache::store() : Cache::store('redis'), $this->report->scope, $credentials);
        $read = fn (?array $ticket, ?\Closure $created = null) => (new XAsyncReport($http, $settings->signer(), $guard, $cooldown))->advance($this->report, $credentials,
            new ProviderReadBudget($this->report->scope, $this->stream, 3, 0, 3, 40, min($at->modify('+45 seconds'), $this->deadline), 1, 5), $ticket, $created);
        $advance = $this->ticket !== null ? $read($this->ticket) : (new XAsyncAcquisition(app()->runningUnitTests() ? Cache::store() : Cache::store('redis')))->run(
            [$this->report->binding(), $this->stream, $this->rootCommand, $this->accessVersion, $this->configuration,
                $this->actorId, $this->actorGeneration, hash('sha256', $this->sessionId)], $this->deadline, $read);
        $guard($this->report->scope, 'provider.read');
        $result = $advance['result'];
        $next = null;
        $failure = null;
        if ($result === null) {
            $candidate = CarbonImmutable::now()->addSeconds(min(30, 5 * (2 ** min($this->number, 3))));
            if ($this->number < 10 && $candidate < $this->deadline) {
                $next = $candidate->format(DATE_ATOM);
            } else {
                $failure = 'x_async_processing_timeout';
            }
        }
        $receipt = $journal->accept($request, $result?->batch ?? new ObservationBatch($this->report->scope, [],
            $failure === null ? 'PENDING' : 'FAILED', null, $failure), $this->stream, $this->command, $this->generation, $this->accessVersion,
            $result?->readProvenance ?? ['api_version' => '12', 'report' => 'X asynchronous job processing; no observations accepted',
                'documentation_basis' => 'https://docs.x.com/x-ads-api/analytics']);

        return ['receipt' => $receipt, 'failure' => $failure, 'retry_after_seconds' => null, 'next_at' => $next, 'ticket' => $advance['ticket']];
    }

    public function __debugInfo(): array
    {
        return ['queue' => $this->queue, 'scope' => $this->report->scope->jsonSerialize(), 'session' => '[REDACTED]'];
    }
}
