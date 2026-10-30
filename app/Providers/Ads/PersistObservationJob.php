<?php

namespace App\Providers\Ads;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\SessionAuthority;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Encrypted, provider-local acceptance work. This job performs no remote provider action. */
final class PersistObservationJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    // Local execution safety values, never provider quotas or token lifetimes.
    public int $tries = 3;

    public int $timeout = 20;

    private function __construct(
        private int $actorId,
        private int $actorGeneration,
        #[\SensitiveParameter] private string $sessionId,
        private string $workspacePublicId,
        private ObservationBatch $batch,
        private string $stream,
        private string $command,
        private int $generation,
        private int $accessVersion,
        private array $provenance,
        #[\SensitiveParameter] private ?array $nativeProgress,
        private DateTimeImmutable $deadline,
    ) {
        $this->onQueue((new ProviderQueuePolicy)->queue($batch->scope));
        $this->afterCommit();
    }

    /** Capturing work requires exact current authority inside the original signed Workspace transaction. */
    public static function capture(Request $request, ObservationBatch $batch, string $stream, string $command,
        int $generation, int $accessVersion, array $provenance, ?NativeProgress $progress = null): self
    {
        if (DB::connection()->transactionLevel() !== 1 || ! $request->user()) {
            throw new LogicException('Provider work must be captured inside its authorized Workspace transaction.');
        }
        $s = $batch->scope;
        DB::scalar('SELECT builderad_provider_credential(?, ?, ?::uuid, ?, ?, ?, ?)', [
            $s->workspaceId, SessionAuthority::sessionKey($request), $s->sourceId, $s->provider->value,
            $s->accountId, $s->operation, $s->purpose]);
        $currentVersion = DB::table('provider_access')->where('workspace_id', $s->workspaceId)->where('source_id', $s->sourceId)
            ->where('provider', $s->provider->value)->where('resource', $s->accountId)->where('operation', $s->operation)
            ->where('purpose', $s->purpose)->value('version');
        if ((int) $currentVersion !== $accessVersion || ($progress !== null && $batch->coverage !== 'COMPLETE')) {
            throw new LogicException('Queued acceptance must bind the verified access generation and safe coverage.');
        }

        return new self($request->user()->getAuthIdentifier(), (int) $request->session()->get('a001_generation', 0),
            $request->session()->getId(), DB::table('workspaces')->where('id', $s->workspaceId)->value('public_id'),
            $batch, $stream, $command, $generation, $accessVersion, $provenance, $progress?->envelope(),
            new DateTimeImmutable('+5 minutes'));
    }

    public function middleware(): array
    {
        return (new ProviderQueuePolicy)->middleware($this->batch->scope, 'journal:'.$this->stream, $this->timeout, 2, 45);
    }

    public function backoff(): array
    {
        return [1, 5, 15];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return $this->deadline;
    }

    public function handle(PrincipalContext $principals, ObservationJournal $journal): void
    {
        if (new DateTimeImmutable >= $this->deadline) {
            throw new ReadBudgetExhausted('deadline_exhausted');
        }
        $actor = User::findOrFail($this->actorId);
        $request = Request::create('/workspaces/'.$this->workspacePublicId);
        $request->setUserResolver(fn () => $actor);
        $session = new Store('bb2-worker', new ArraySessionHandler(120));
        $session->setId($this->sessionId);
        $session->put('a001_generation', $this->actorGeneration);
        $request->setLaravelSession($session);
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Queued provider work must own its outer transaction.');
        }
        // Use the existing signed selector and source-actor gates, as in independent source workers.
        // Background acceptance must not touch/extend the human session's idle lifetime.
        DB::transaction(function () use ($request, $journal, $principals, $actor): void {
            $principals->activate($actor);
            DB::selectOne('SELECT builderad_v2_legacy_enter(?::uuid)', [$this->workspacePublicId]);
            DB::selectOne('SELECT builderad_select_workspace_context(?)', [$this->batch->scope->workspaceId]);
            if ((int) DB::table('principal_lifecycles')->where('user_id', $this->actorId)->value('generation') !== $this->actorGeneration
                || DB::table('workspaces')->where('id', $this->batch->scope->workspaceId)->value('public_id') !== $this->workspacePublicId) {
                throw new AuthorizationException('Queued provider authority changed.');
            }
            $progress = $this->nativeProgress === null ? null : new NativeProgress(
                $this->nativeProgress['kind'], $this->nativeProgress['basis'], $this->nativeProgress['payload']);
            $journal->accept($request, $this->batch, $this->stream, $this->command, $this->generation,
                $this->accessVersion, $this->provenance, $progress);
        });
    }

    public function __debugInfo(): array
    {
        return ['queue' => $this->queue, 'scope' => $this->batch->scope->jsonSerialize(), 'session' => '[REDACTED]', 'progress' => '[REDACTED]'];
    }
}
