<?php

namespace App\Security;

use App\Commercial\CommercialEffects;
use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class GrantAuthority
{
    public function __construct(private readonly PrincipalContext $principals) {}

    public const STAFF_ACTIONS = [
        'staff.view', 'staff.invite', 'staff.attest', 'staff.activate', 'staff.revoke',
        'grant.manage', 'service.manage', 'business.manage',
    ];

    /** Owner-authorized Founder role; current/fresh checks still guard every effect. */
    public function canPrepareCommercialMarket(Request $request): bool
    {
        return $request->user() && DB::connection('pgsql_authority')->table('internal_personnel')
            ->where('user_id', $request->user()->id)->where('kind', 'founder')->where('status', 'active')->exists();
    }

    public function prepareCommercialMarket(Request $request, array $input): object
    {
        $this->assertCommercialExecutor($request);
        $db = DB::connection('pgsql_authority');

        return $db->transaction(function () use ($request, $input, $db): object {
            $this->requireCommercialFounder($request);
            if ($input['intent_id'] !== $request->session()->get('commercial_market_preparation_intent')) {
                throw new AuthorizationException('Server-issued preparation intent required.');
            }
            $db->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['sa01.prepare.'.$input['intent_id']]);
            $this->requireCommercialFounder($request);
            $fingerprint = hash('sha256', json_encode([$request->user()->id,
                $request->session()->get('a001_generation'), $input['administrator'], $input['label'],
                $input['reason'], $input['expires_at']], JSON_THROW_ON_ERROR));
            $prior = $db->table('security_commercial_market_approvals')->where('id', $input['intent_id'])->first();
            if ($prior) {
                if (! hash_equals($prior->fingerprint, $fingerprint)) {
                    throw new ConflictHttpException('Preparation intent payload changed.');
                }
                $this->requireCommercialAdministrationFor($prior);

                return $prior;
            }
            $admin = User::on('pgsql_authority')->where('public_id', $input['administrator'])->first();
            if (! $admin || $admin->id === $request->user()->id) {
                throw new AuthorizationException('Distinct eligible administrator required.');
            }
            $lifecycle = $this->commercialGrantee($admin, CommercialEffects::ADMINISTRATOR);
            if (! $db->scalar('SELECT ?::timestamptz > clock_timestamp()', [$input['expires_at']])) {
                throw new AuthorizationException('Future approval expiry required.');
            }
            $targetId = (string) Str::uuid7();
            $grantId = $this->writeCommercialGrant($request, $admin, CommercialEffects::ADMINISTRATOR,
                $targetId, null, null, $input['reason'], (int) $lifecycle->generation, $input['expires_at']);
            $db->table('security_commercial_market_approvals')->insert([
                'id' => $input['intent_id'], 'target_id' => $targetId, 'founder_id' => $request->user()->id,
                'founder_generation' => $request->session()->get('a001_generation'),
                'administrator_id' => $admin->id, 'administrator_generation' => $lifecycle->generation,
                'label' => $input['label'], 'reason' => $input['reason'], 'expires_at' => $input['expires_at'],
                'fingerprint' => $fingerprint, 'grant_id' => $grantId,
            ]);
            $this->requireCommercialFounder($request);
            $approval = $db->table('security_commercial_market_approvals')->where('id', $input['intent_id'])->first();
            $this->requireCommercialAdministrationFor($approval);

            return $approval;
        });
    }

    public function canManageCommercialPreparation(Request $request): bool
    {
        if ($this->canPrepareCommercialMarket($request)) {
            return true;
        }

        return $request->user() && DB::connection('pgsql_authority')->table('security_action_grants')
            ->where('subject_user_id', $request->user()->id)->where('subject_generation', $request->session()->get('a001_generation'))
            ->where('scope_kind', 'commercial')->where('action', CommercialEffects::ADMINISTRATOR)
            ->whereNull('workspace_id')->whereNull('commercial_version_id')->where('status', 'active')->whereNull('revoked_at')
            ->whereRaw('(expires_at IS NULL OR expires_at > clock_timestamp())')->exists();
    }

    public function requireCommercialFounder(Request $request): void
    {
        $this->assertCommercialExecutor($request);
        if (! DB::connection('pgsql_authority')->table('internal_personnel')->where('user_id', $request->user()->id)
            ->where('kind', 'founder')->where('status', 'active')->sharedLock()->first()) {
            throw new AuthorizationException('Current active Founder approval required.');
        }
    }

    private function requireCommercialAdministrationFor(object $approval): void
    {
        $db = DB::connection('pgsql_authority');
        if (! $db->table('security_action_grants as g')->join('principal_lifecycles as p', 'p.user_id', '=', 'g.subject_user_id')
            ->where('g.id', $approval->grant_id)->where('g.subject_user_id', $approval->administrator_id)
            ->where('g.subject_generation', $approval->administrator_generation)->whereColumn('p.generation', 'g.subject_generation')
            ->where('p.status', 'current')->where('g.action', CommercialEffects::ADMINISTRATOR)
            ->where('g.commercial_target_id', $approval->target_id)->whereNull('g.workspace_id')->whereNull('g.commercial_version_id')
            ->where('g.status', 'active')->whereNull('g.revoked_at')->whereRaw('g.expires_at > clock_timestamp()')->sharedLock()->first()) {
            throw new AuthorizationException('Approval authority expired or was revoked; retry cannot restore it.');
        }
    }

    public function authorizePreparedMarketOperator(Request $request, string $approvalId, User $operator, string $reason): void
    {
        $this->assertCommercialExecutor($request);
        DB::connection('pgsql_authority')->transaction(function () use ($request, $approvalId, $operator, $reason): void {
            $db = DB::connection('pgsql_authority');
            $db->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['sa01.operator.'.$approvalId]);
            $approval = $db->table('security_commercial_market_approvals')->where('id', $approvalId)->first();
            if (! $approval) {
                throw new AuthorizationException('Approved exact market required.');
            }
            $this->requireCommercialAdministration($request, $approval->target_id, null, null);
            if ($operator->id === (int) $approval->founder_id) {
                throw new AuthorizationException('Preparation does not grant Founder operational authority.');
            }
            if ($db->scalar('SELECT builderad_commercial_preparation_consumed(?::uuid)', [$approval->target_id])) {
                throw new ConflictHttpException('Market already recorded; preparation cannot be reused.');
            }
            foreach (['ConfigureCommercialMarket', 'ReadCommercialMarketRegistry'] as $effect) {
                $this->issueCommercialGrant($request, $operator, $effect, $approval->target_id, null, null, $reason, $approval->expires_at);
            }
        });
    }

    private const SENSITIVE_STAFF_ACTIONS = ['grant.manage', 'service.manage'];

    /** Existing fresh responsible-Owner evidence issues a narrow grant; mutation itself is grant-based. */
    public function requestBusinessContextGrant(Request $request, int $workspaceId): void
    {
        DB::selectOne('SELECT builderad_v2_legacy_context_grant(?,?)', [$workspaceId, SessionAuthority::sessionKey($request)]);
    }

    public const SOURCE_ACTIONS = ['source.register', 'source.manage', 'source.read', 'source.audit.recover',
        'provider.connect', 'provider.read', 'provider.revoke'];

    /** Explicit fresh Owner request only. Source facts never issue their own grants. */
    public function requestSourceGrant(Request $request, int $workspaceId, array $input): void
    {
        if (DB::connection()->transactionLevel() !== 1 ||
            ! in_array($input['action'], self::SOURCE_ACTIONS, true)) {
            throw new AuthorizationException('Guarded exact source grant request required.');
        }
        DB::selectOne('SELECT builderad_v2_legacy_source_grant_guard(?::uuid)', [$input['source_id']]);
        $evidence = fn () => DB::selectOne('SELECT * FROM builderad_source_owner_evidence(?, ?)', [
            $workspaceId, SessionAuthority::sessionKey($request),
        ]);
        $owner = $evidence();
        if (! $owner || (int) $owner->actor_id !== $request->user()->id ||
            (int) $owner->generation !== (int) $request->session()->get('a001_generation', 0)) {
            throw new AuthorizationException('Exact current responsible Owner required.');
        }
        DB::connection('pgsql_authority')->transaction(function () use ($request, $workspaceId, $input, $owner, $evidence): void {
            $db = DB::connection('pgsql_authority');
            // The parent already holds this source admission before its Workspace/identity locks.
            // The child also holds admission until commit; no adoption can interleave its DML.
            $db->selectOne('SELECT builderad_v2_native_source_authority_enter(?::uuid)', [$input['source_id']]);
            $key = json_encode([$owner->actor_id, $owner->generation, $workspaceId, $input['source_id'],
                $input['resource'], $input['operation'], $input['purpose'], $input['action']], JSON_THROW_ON_ERROR);
            $db->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['bb1.grant.'.$key]);
            $query = $db->table('security_action_grants')->where('subject_user_id', $owner->actor_id)
                ->where('subject_generation', $owner->generation)->where('scope_kind', 'source')
                ->where('workspace_id', $workspaceId)->where('source_target_id', $input['source_id'])
                ->where('source_resource', $input['resource'])->where('source_operation', $input['operation'])
                ->where('source_purpose', $input['purpose'])->where('action', $input['action'])->where('status', 'active');
            $existing = (clone $query)->lockForUpdate()->first();
            if ($input['decision'] === 'revoke') {
                if ($existing) {
                    $query->update(['status' => 'revoked', 'revoked_at' => DB::raw('clock_timestamp()'),
                        'version' => (int) $existing->version + 1, 'updated_at' => DB::raw('clock_timestamp()')]);
                }
            } elseif ($existing) {
                if ($existing->revoked_at !== null || ($existing->expires_at !== null &&
                    ! $db->scalar('SELECT ?::timestamptz > clock_timestamp()', [$existing->expires_at]))) {
                    throw new AuthorizationException('Expired source grant must be explicitly revoked before reissue.');
                }
            } else {
                if ($input['expires_at'] !== null && ! $db->scalar('SELECT ?::timestamptz > clock_timestamp()', [$input['expires_at']])) {
                    throw new AuthorizationException('Future grant expiry required.');
                }
                $db->table('security_action_grants')->insert([
                    'subject_user_id' => $owner->actor_id, 'subject_generation' => $owner->generation,
                    'granted_by' => $owner->actor_id, 'source' => 'source_owner_request', 'scope_kind' => 'source',
                    'workspace_id' => $workspaceId, 'source_target_id' => $input['source_id'],
                    'source_resource' => $input['resource'], 'source_operation' => $input['operation'],
                    'source_purpose' => $input['purpose'], 'action' => $input['action'], 'reason' => $input['reason'],
                    'status' => 'active', 'version' => 1, 'expires_at' => $input['expires_at'],
                    'created_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()'),
                ]);
            }
            if (($input['decision'] === 'revoke' && $existing) || ($input['decision'] === 'authorize' && ! $existing)) {
                $db->table('security_audit_events')->insert([
                    'actor_user_id' => $request->user()->id, 'subject_user_id' => $owner->actor_id, 'workspace_id' => $workspaceId,
                    'event' => 'source_grant_'.$input['decision'], 'reason' => $input['action'].':'.$input['source_id'],
                    'prior_status' => $existing?->status, 'new_status' => $input['decision'] === 'revoke' ? 'revoked' : 'active',
                    'new_generation' => $owner->generation, 'occurred_at' => DB::raw('clock_timestamp()'),
                ]);
            }
            // Locks above can wait. Database clock and signed Owner evidence fence actual grant commit.
            $current = $evidence();
            if (! $current || (int) $current->generation !== (int) $owner->generation ||
                ($input['decision'] === 'authorize' && $input['expires_at'] !== null &&
                    ! $db->scalar('SELECT ?::timestamptz > clock_timestamp()', [$input['expires_at']]))) {
                throw new AuthorizationException('Source grant evidence expired before effect.');
            }
        });
    }

    /** A-003 is the sole writer, including commercial administration. No STAFF_ACTION inheritance. */
    public function issueCommercialGrant(Request $request, User $target, string $effect,
        string $targetId, ?int $workspaceId, ?string $versionId, string $reason, ?string $expiresAt = null): int
    {
        $this->assertCommercialExecutor($request);

        return DB::connection('pgsql_authority')->transaction(fn () => $this->issueCommercialGrantLocally(
            $request, $target, $effect, $targetId, $workspaceId, $versionId, $reason, $expiresAt));
    }

    private function issueCommercialGrantLocally(Request $request, User $target, string $effect,
        string $targetId, ?int $workspaceId, ?string $versionId, string $reason, ?string $expiresAt): int
    {
        $db = DB::connection('pgsql_authority');
        $this->assertCommercialExecutor($request);
        if ($request->user()->id === $target->id || ! in_array($effect, CommercialEffects::OPERATIONAL, true)) {
            throw new AuthorizationException('Self-grant or effect expansion denied.');
        }
        $marketEffects = ['ReadCommercialMarketRegistry', 'ConfigureCommercialMarket', 'EnableCommercialMarket', 'DisableCommercialMarket'];
        $resultEffects = ['ReadCommercialRegistrationReceipt', 'ReadCommercialAuditDeliveryState', 'RetryOrRecoverExistingAuditDelivery'];
        if ((in_array($effect, $marketEffects, true) && ($workspaceId !== null || $versionId !== null)) ||
            ($effect === 'RecordOrVerifyApprovedMarketReference' && ($workspaceId !== null || $versionId === null)) ||
            ($effect === 'AssignCommercialMarket' && ($workspaceId === null || $versionId === null)) ||
            (in_array($effect, $resultEffects, true) && $versionId !== null) ||
            (! in_array($effect, [...$marketEffects, ...$resultEffects, 'RecordOrVerifyApprovedMarketReference', 'AssignCommercialMarket'], true) &&
                ($workspaceId === null || $versionId !== null))) {
            throw new AuthorizationException('Exact effect target shape required.');
        }
        $this->requireCommercialAdministration($request, $targetId, $workspaceId, $versionId);
        $lifecycle = $this->commercialGrantee($target, $effect);
        $existing = $db->table('security_action_grants')->where('subject_user_id', $target->id)
            ->where('subject_generation', $lifecycle->generation)->where('scope_kind', 'commercial')
            ->where('action', $effect)->where('commercial_target_id', $targetId)
            ->where('workspace_id', $workspaceId)->where('commercial_version_id', $versionId)
            ->where('status', 'active')->lockForUpdate()->first();
        if ($existing) {
            if ($existing->expires_at !== null && $existing->expires_at <= now()) {
                throw new AuthorizationException('Expired authority cannot be revived implicitly.');
            }

            $this->assertCommercialExecutor($request);
            $this->requireCommercialAdministration($request, $targetId, $workspaceId, $versionId);

            return (int) $existing->id;
        }

        $this->assertCommercialExecutor($request);
        $this->requireCommercialAdministration($request, $targetId, $workspaceId, $versionId);
        $grantId = $this->writeCommercialGrant($request, $target, $effect, $targetId, $workspaceId,
            $versionId, $reason, (int) $lifecycle->generation, $expiresAt);
        // Recheck on the authority DB clock after grantee/uniqueness/audit waits.
        $this->assertCommercialExecutor($request);
        $this->requireCommercialAdministration($request, $targetId, $workspaceId, $versionId);

        return $grantId;
    }

    public function revokeCommercialGrant(Request $request, int $grantId): void
    {
        $this->assertCommercialExecutor($request);
        DB::connection('pgsql_authority')->transaction(fn () => $this->revokeCommercialGrantLocally($request, $grantId));
    }

    private function revokeCommercialGrantLocally(Request $request, int $grantId): void
    {
        $this->assertCommercialExecutor($request);
        $db = DB::connection('pgsql_authority');
        $grant = $db->table('security_action_grants')->where('id', $grantId)
            ->where('scope_kind', 'commercial')->lockForUpdate()->first();
        if (! $grant || ! in_array($grant->action, CommercialEffects::OPERATIONAL, true) ||
            (int) $grant->subject_user_id === $request->user()->id) {
            throw new AuthorizationException('Operational commercial revocation target denied.');
        }
        $this->requireCommercialAdministration($request, $grant->commercial_target_id,
            $grant->workspace_id, $grant->commercial_version_id);
        $this->writeCommercialRevocation($request, $grant);
        $this->assertCommercialExecutor($request);
        $this->requireCommercialAdministration($request, $grant->commercial_target_id,
            $grant->workspace_id, $grant->commercial_version_id);
    }

    /** Only an exact trusted Owner mandate may assign/revoke exceptional administration. */
    public function executeCommercialOwnerMandate(Request $request, string $mandateId): int
    {
        $this->assertCommercialExecutor($request);

        return DB::connection('pgsql_authority')->transaction(fn () => $this->executeCommercialOwnerMandateLocally($request, $mandateId));
    }

    private function executeCommercialOwnerMandateLocally(Request $request, string $mandateId): int
    {
        $this->assertCommercialExecutor($request);
        $mandate = config('commercial_authority.owner_mandates.'.$mandateId);
        if (! is_array($mandate) || ($mandate['executor'] ?? null) !== $request->user()->public_id ||
            ! in_array($mandate['action'] ?? null, ['assign', 'revoke'], true) ||
            ! isset($mandate['expires_at'], $mandate['approval_reference'], $mandate['grantee'], $mandate['target_id'],
                $mandate['executor_generation'], $mandate['grantee_generation']) ||
            $mandate['expires_at'] <= now()->toIso8601String() ||
            (int) $mandate['executor_generation'] !== (int) $request->session()->get('a001_generation', 0)) {
            throw new AuthorizationException('Exact current Owner-approved exceptional mandate required.');
        }
        $db = DB::connection('pgsql_authority');
        $db->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['sa01.mandate.'.$mandateId]);
        $target = User::on('pgsql_authority')->where('public_id', $mandate['grantee'])->first();
        if (! $target || $target->id === $request->user()->id) {
            throw new AuthorizationException('Distinct eligible executor and grantee required.');
        }
        $lifecycle = $this->commercialGrantee($target, CommercialEffects::ADMINISTRATOR);
        if ((int) $lifecycle->generation !== (int) $mandate['grantee_generation']) {
            throw new AuthorizationException('Exact grantee generation required.');
        }
        $fingerprint = hash('sha256', json_encode($mandate, JSON_THROW_ON_ERROR));
        $prior = $db->table('security_commercial_mandate_executions')->where('mandate_id', $mandateId)->first();
        if ($prior) {
            if (! hash_equals($prior->fingerprint, $fingerprint)) {
                throw new AuthorizationException('Owner mandate identity conflict.');
            }
            $this->requireCurrentCommercialMandate($request, $mandate);

            return (int) $prior->grant_id;
        }
        if ($mandate['action'] === 'assign') {
            $this->requireCurrentCommercialMandate($request, $mandate);
            $grantId = $this->writeCommercialGrant($request, $target, CommercialEffects::ADMINISTRATOR,
                $mandate['target_id'], $mandate['workspace_id'] ?? null, $mandate['version_id'] ?? null,
                $mandate['approval_reference'], (int) $lifecycle->generation, $mandate['grant_expires_at'] ?? null);
        } else {
            $grant = $db->table('security_action_grants')->where('id', $mandate['grant_id'] ?? 0)
                ->where('action', CommercialEffects::ADMINISTRATOR)->where('scope_kind', 'commercial')
                ->where('subject_user_id', $target->id)->where('subject_generation', $lifecycle->generation)
                ->where('commercial_target_id', $mandate['target_id'])
                ->where('workspace_id', $mandate['workspace_id'] ?? null)
                ->where('commercial_version_id', $mandate['version_id'] ?? null)->lockForUpdate()->first();
            if (! $grant) {
                throw new AuthorizationException('Exact exceptional revocation target required.');
            }
            $this->requireCurrentCommercialMandate($request, $mandate);
            $this->writeCommercialRevocation($request, $grant);
            $grantId = (int) $grant->id;
        }
        $db->table('security_commercial_mandate_executions')->insert([
            'mandate_id' => $mandateId, 'fingerprint' => $fingerprint, 'grant_id' => $grantId,
        ]);
        $this->requireCurrentCommercialMandate($request, $mandate);

        return $grantId;
    }

    private function assertCommercialExecutor(Request $request): void
    {
        $db = DB::connection('pgsql_authority');
        if ($db->transactionLevel() < 1 || ! $request->is('internal/*') || ! $request->user()?->hasVerifiedEmail()) {
            throw new AuthorizationException('Protected internal A-003 transaction required.');
        }
        app(SessionAuthority::class)->assertCurrentAuthority($request, $request->user(),
            (int) $request->session()->get('a001_generation', 0));
        if (! app(SessionAuthority::class)->hasFreshAuthentication($request, $request->user())) {
            throw new AuthorizationException('Fresh phishing-resistant internal authentication required.');
        }
    }

    private function requireCurrentCommercialMandate(Request $request, array $mandate): void
    {
        $this->assertCommercialExecutor($request);
        if (! DB::connection('pgsql_authority')->scalar('SELECT ?::timestamptz > clock_timestamp()', [$mandate['expires_at']])) {
            throw new AuthorizationException('Owner mandate expired before the authoritative effect.');
        }
    }

    private function requireCommercialAdministration(Request $request, string $targetId, ?int $workspaceId, ?string $versionId): void
    {
        if ($this->canPrepareCommercialMarket($request)) {
            $this->requireCommercialFounder($request);

            return;
        }
        $grant = DB::connection('pgsql_authority')->table('security_action_grants')
            ->where('subject_user_id', $request->user()->id)->where('subject_generation', $request->session()->get('a001_generation'))
            ->where('scope_kind', 'commercial')->where('action', CommercialEffects::ADMINISTRATOR)
            ->where('commercial_target_id', $targetId)->where('workspace_id', $workspaceId)
            ->where('commercial_version_id', $versionId)->where('status', 'active')->whereNull('revoked_at')
            ->whereRaw('(expires_at IS NULL OR expires_at > clock_timestamp())')->sharedLock()->first();
        if (! $grant) {
            throw new AuthorizationException('Exact bounded commercial administration grant required.');
        }
    }

    private function commercialGrantee(User $target, string $effect): object
    {
        $db = DB::connection('pgsql_authority');
        $class = $effect === 'ReadCommercialContextAdvertiser' ? 'advertiser' : 'staff';
        $lifecycle = $db->table('principal_lifecycles')->where('user_id', $target->id)
            ->where('principal_class', $class)->where('status', 'current')->sharedLock()->first();
        if (! $lifecycle || ! $target->hasVerifiedEmail() || ($class === 'staff' &&
            (! $db->table('internal_personnel')->where('user_id', $target->id)->where('status', 'active')->sharedLock()->first() ||
                $target->passkeys()->count() < 1))) {
            throw new AuthorizationException('Qualified current explicit commercial grantee required.');
        }

        return $lifecycle;
    }

    private function writeCommercialGrant(Request $request, User $target, string $effect, string $targetId,
        ?int $workspaceId, ?string $versionId, string $reason, int $generation, ?string $expiresAt): int
    {
        $grantId = DB::connection('pgsql_authority')->table('security_action_grants')->insertGetId([
            'subject_user_id' => $target->id, 'granted_by' => $request->user()->id, 'source' => 'human',
            'action' => $effect, 'scope_kind' => 'commercial', 'commercial_target_id' => $targetId,
            'commercial_version_id' => $versionId, 'workspace_id' => $workspaceId,
            'subject_generation' => $generation, 'reason' => $reason, 'expires_at' => $expiresAt,
            'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->auditStaffGrant($request->user()->id, $target->id,
            $effect.':'.$targetId, 'active', $generation);

        return $grantId;
    }

    private function writeCommercialRevocation(Request $request, object $grant): void
    {
        if ($grant->status === 'revoked') {
            return;
        }
        DB::connection('pgsql_authority')->table('security_action_grants')->where('id', $grant->id)->update([
            'status' => 'revoked', 'revoked_at' => now(), 'version' => (int) $grant->version + 1, 'updated_at' => now(),
        ]);
        $this->auditStaffGrant($request->user()->id, $grant->subject_user_id,
            $grant->action.':'.$grant->commercial_target_id, 'revoked', (int) $grant->subject_generation);
    }

    /** @return array<int, array{public_id: string, name: string, slug: string}> */
    public function reassessableOwnerWorkspaces(Request $request): array
    {
        return array_map(
            static fn (object $workspace): array => [
                'public_id' => $workspace->public_id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            DB::select('SELECT * FROM builderad_owner_reassessment_candidates(?, ?)', [
                $request->user()->id, SessionAuthority::sessionKey($request),
            ]),
        );
    }

    public function reassessOwnerWorkspaceGrants(Request $request, string $workspacePublicId): void
    {
        if (DB::connection()->transactionLevel() !== 1) {
            throw new LogicException('Owner grant reassessment requires the guarded outer transaction.');
        }
        $this->principals->activate($request->user());
        $evidence = DB::selectOne('SELECT * FROM builderad_owner_reassessment_evidence(?, ?, ?::uuid)', [
            $request->user()->id,
            SessionAuthority::sessionKey($request),
            $workspacePublicId,
        ]);
        if (! $evidence) {
            throw new AuthorizationException('Current Owner reassessment evidence is required.');
        }

        DB::connection('pgsql_authority')->transaction(function () use ($request, $evidence): void {
            $generation = (int) $request->session()->get('a001_generation', 0);
            $this->issueReassessedOwnerGrant($request, (int) $evidence->workspace_id,
                $generation, (int) $evidence->prior_generation,
                'workspace.view', $evidence->view_expires_at, true);
            if (in_array($evidence->restore_service_scope, [true, 1, '1', 't'], true)) {
                $this->issueReassessedOwnerGrant($request, (int) $evidence->workspace_id,
                    $generation, (int) $evidence->prior_generation,
                    'service.scope', $evidence->scope_expires_at, false);
            }
        });
    }

    private function issueReassessedOwnerGrant(Request $request, int $workspaceId, int $generation,
        int $priorGeneration, string $action, ?string $expiresAt, bool $required): void
    {
        $query = DB::connection('pgsql_authority')->table('security_action_grants')
            ->where('subject_user_id', $request->user()->id)
            ->where('workspace_id', $workspaceId)
            ->where('subject_generation', $generation)
            ->where('action', $action);
        $existing = (clone $query)->lockForUpdate()->first();
        if ($existing) {
            if ($existing->status !== 'active' || $existing->revoked_at !== null ||
                ($existing->expires_at !== null && $existing->expires_at <= now())) {
                if ($required) {
                    throw new AuthorizationException('Revoked Workspace grant cannot be restored.');
                }

                return;
            }

            return;
        }

        $inserted = DB::connection('pgsql_authority')->table('security_action_grants')->insertOrIgnore([
            'subject_user_id' => $request->user()->id,
            'granted_by' => null,
            'source' => 'system_owner_reassessment',
            'action' => $action,
            'scope_kind' => 'workspace',
            'workspace_id' => $workspaceId,
            'subject_generation' => $generation,
            'reason' => 'verified_owner_continuity_after_email_change',
            'status' => 'active',
            'version' => 1,
            'expires_at' => $expiresAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $inserted) {
            $current = (clone $query)->lockForUpdate()->first();
            if (! $current || $current->status !== 'active' || $current->revoked_at !== null) {
                throw new AuthorizationException('Workspace grant conflict denied.');
            }

            return;
        }

        DB::connection('pgsql_authority')->table('security_audit_events')->insert([
            'actor_user_id' => $request->user()->id,
            'subject_user_id' => $request->user()->id,
            'workspace_id' => $workspaceId,
            'event' => 'workspace_owner_grant_reassessed',
            'reason' => $action,
            'prior_status' => 'active',
            'new_status' => 'active',
            'prior_generation' => $priorGeneration,
            'new_generation' => $generation,
            'occurred_at' => now(),
        ]);
    }

    public function issueFounderBootstrapGrants(User $founder, int $generation): void
    {
        foreach (self::STAFF_ACTIONS as $action) {
            DB::connection('pgsql_authority')->table('security_action_grants')->insert([
                'subject_user_id' => $founder->id,
                'granted_by' => null,
                'source' => 'founder_bootstrap',
                'action' => $action,
                'scope_kind' => 'platform',
                'workspace_id' => null,
                'subject_generation' => $generation,
                'reason' => 'trusted_local_founder_bootstrap',
                'status' => 'active',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function issueStaffGrant(Request $request, User $target, string $action, string $reason): void
    {
        $manager = $request->user();
        $this->assertGrantManager($request);
        if (! in_array($action, self::STAFF_ACTIONS, true) || $manager->id === $target->id) {
            throw new AuthorizationException('The requested staff grant is not permitted.');
        }
        $lifecycle = DB::connection('pgsql_authority')->table('principal_lifecycles')->where('user_id', $target->id)
            ->sharedLock()->first();
        $personnel = DB::connection('pgsql_authority')->table('internal_personnel')->where('user_id', $target->id)
            ->lockForUpdate()->first();
        if (! $personnel || $personnel->kind !== 'employee' || $personnel->status !== 'active' ||
            ! $lifecycle || $lifecycle->principal_class !== 'staff' || $lifecycle->status !== 'current') {
            throw new AuthorizationException('An active ordinary employee is required.');
        }
        if (in_array($action, self::SENSITIVE_STAFF_ACTIONS, true) &&
            ! $this->isFounder($manager)) {
            throw new AuthorizationException('Only founder authority may issue sensitive staff grants.');
        }
        if (in_array($action, self::SENSITIVE_STAFF_ACTIONS, true) &&
            ($target->passkeys()->count() < 2 || ! $personnel->factor_independence_attested_at)) {
            throw new AuthorizationException('Independent primary and backup authenticators are required.');
        }
        $existing = DB::connection('pgsql_authority')->table('security_action_grants')->where('subject_user_id', $target->id)
            ->where('action', $action)->where('scope_kind', 'platform')
            ->where('subject_generation', $lifecycle->generation)
            ->where('status', 'active')->lockForUpdate()->first();
        if ($existing) {
            return;
        }
        DB::connection('pgsql_authority')->table('security_action_grants')->insert([
            'subject_user_id' => $target->id,
            'granted_by' => $manager->id,
            'source' => 'human',
            'action' => $action,
            'scope_kind' => 'platform',
            'workspace_id' => null,
            'subject_generation' => $lifecycle->generation,
            'reason' => $reason,
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->auditStaffGrant($manager->id, $target->id, $action, 'active', (int) $lifecycle->generation);
    }

    public function revokeStaffGrant(Request $request, int $grantId): void
    {
        $this->assertGrantManager($request);
        $grant = DB::connection('pgsql_authority')->table('security_action_grants')->where('id', $grantId)
            ->where('scope_kind', 'platform')->where('source', 'human')
            ->lockForUpdate()->first();
        if (! $grant) {
            abort(404);
        }
        if (in_array($grant->action, self::SENSITIVE_STAFF_ACTIONS, true) &&
            ! $this->isFounder($request->user())) {
            throw new AuthorizationException('Only founder authority may revoke sensitive staff grants.');
        }
        if ($grant->status === 'revoked') {
            return;
        }
        DB::connection('pgsql_authority')->table('security_action_grants')->where('id', $grantId)->update([
            'status' => 'revoked', 'revoked_at' => now(),
            'version' => (int) $grant->version + 1, 'updated_at' => now(),
        ]);
        $this->auditStaffGrant($request->user()->id, $grant->subject_user_id,
            $grant->action, 'revoked', (int) $grant->subject_generation);
    }

    private function assertGrantManager(Request $request): void
    {
        if (! $this->has($request->user(),
            (int) $request->session()->get('a001_generation', 0), 'grant.manage')) {
            throw new AuthorizationException('Explicit grant administration authority is required.');
        }
    }

    private function isFounder(User $user): bool
    {
        return DB::connection('pgsql_authority')->table('internal_personnel')->where('user_id', $user->id)
            ->where('kind', 'founder')->where('status', 'active')
            ->sharedLock()->exists();
    }

    private function auditStaffGrant(int $actorId, int $subjectId, string $action, string $status, int $generation): void
    {
        DB::connection('pgsql_authority')->table('security_audit_events')->insert([
            'actor_user_id' => $actorId,
            'subject_user_id' => $subjectId,
            'event' => 'staff_grant_'.$status,
            'reason' => $action,
            'prior_status' => $status === 'active' ? null : 'active',
            'new_status' => $status,
            'prior_generation' => $generation,
            'new_generation' => $generation,
            'occurred_at' => now(),
        ]);
    }

    public function issueServiceWorkspaceView(Request $request, string $servicePublicId, string $workspacePublicId, string $reason): void
    {
        $this->assertServiceManager($request);
        $service = DB::connection('pgsql_authority')->table('service_identities')->where('public_id', $servicePublicId)
            ->where('status', 'enabled')->sharedLock()->first();
        $workspaceId = DB::scalar('SELECT builderad_workspace_id_by_public(?)', [$workspacePublicId]);
        if (! $service || ! $workspaceId) {
            throw new AuthorizationException('An enabled service and active Workspace are required.');
        }
        $scope = DB::connection('pgsql_authority')->table('service_workspace_scopes')
            ->where('service_identity_id', $service->id)
            ->where('workspace_id', $workspaceId)->where('status', 'active')
            ->sharedLock()->first();
        if (! $scope) {
            throw new AuthorizationException('Responsible Owner approval is required before a service grant.');
        }
        $existing = DB::connection('pgsql_authority')->table('service_action_grants')
            ->where('service_identity_id', $service->id)
            ->where('workspace_id', $workspaceId)
            ->where('action', 'workspace.view')->where('status', 'active')
            ->lockForUpdate()->first();
        if ($existing) {
            return;
        }
        DB::connection('pgsql_authority')->table('service_action_grants')->insert([
            'service_identity_id' => $service->id,
            'workspace_id' => $workspaceId,
            'action' => 'workspace.view',
            'granted_by' => $request->user()->id,
            'reason' => $reason,
            'status' => 'active',
            'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->auditServiceGrant($request, $service->id, (int) $workspaceId,
            'service_grant_issued', $reason, null, 'active', null, 1);
    }

    public function revokeServiceGrant(Request $request, int $grantId): void
    {
        $this->assertServiceManager($request);
        $grant = DB::connection('pgsql_authority')->table('service_action_grants')->where('id', $grantId)
            ->lockForUpdate()->first();
        if (! $grant) {
            abort(404);
        }
        if ($grant->status === 'revoked') {
            return;
        }
        $version = (int) $grant->version + 1;
        DB::connection('pgsql_authority')->table('service_action_grants')->where('id', $grantId)->update([
            'status' => 'revoked', 'revoked_at' => now(),
            'version' => $version, 'updated_at' => now(),
        ]);
        $this->auditServiceGrant($request, $grant->service_identity_id, $grant->workspace_id,
            'service_grant_revoked', 'explicit_service_grant_revocation',
            'active', 'revoked', (int) $grant->version, $version);
    }

    private function assertServiceManager(Request $request): void
    {
        if (! $this->has($request->user(),
            (int) $request->session()->get('a001_generation', 0), 'service.manage')) {
            throw new AuthorizationException('Explicit service grant management authority required.');
        }
    }

    private function auditServiceGrant(Request $request, int $serviceId, int $workspaceId,
        string $event, string $reason, ?string $before, string $after,
        ?int $priorVersion, int $newVersion): void
    {
        DB::connection('pgsql_authority')->table('service_audit_events')->insert([
            'actor_user_id' => $request->user()->id,
            'service_identity_id' => $serviceId,
            'workspace_id' => $workspaceId,
            'event' => $event,
            'reason' => $reason,
            'prior_status' => $before,
            'new_status' => $after,
            'prior_version' => $priorVersion,
            'new_version' => $newVersion,
            'occurred_at' => now(),
        ]);
    }

    public function issueAdvertiserWorkspaceCreation(User $user, int $generation): void
    {
        DB::connection('pgsql_authority')->table('security_action_grants')->insert([
            'subject_user_id' => $user->id,
            'granted_by' => null,
            'source' => 'system_registration',
            'action' => 'workspace.create',
            'scope_kind' => 'platform',
            'workspace_id' => null,
            'subject_generation' => $generation,
            'reason' => 'verified_advertiser_registration',
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Native platform/Workspace preflight only; Business rights use the protected scope verifier. */
    public function has(User $actor, int $generation, string $action, string $scope = 'platform', ?int $workspaceId = null): bool
    {
        if (! in_array($scope, ['platform', 'workspace'], true)) {
            return false;
        }
        if (DB::connection('pgsql_authority')->transactionLevel() === 0) {
            $runtime = DB::connection();
            if ($runtime->transactionLevel() === 0) {
                return $runtime->transaction(fn () => $this->has($actor, $generation, $action, $scope, $workspaceId), 1);
            }
            $context = $runtime->selectOne('SELECT actor_user_id FROM builderad_signed_context()');
            if (! $context) {
                $this->principals->activate($actor);
            } elseif ((int) $context->actor_user_id !== (int) $actor->id) {
                return false;
            }
        }
        $connection = DB::connection('pgsql_authority')->transactionLevel() > 0
            ? DB::connection('pgsql_authority') : DB::connection();
        $lifecycle = $connection->table('principal_lifecycles')->where('user_id', $actor->id)
            ->where('status', 'current')->where('generation', $generation)->sharedLock()->first();
        if (! $lifecycle || ! $actor->hasVerifiedEmail()) {
            return false;
        }
        if ($lifecycle->principal_class === 'advertiser') {
            $allowedAdvertiserAction = ($action === 'workspace.create' && $scope === 'platform' && $workspaceId === null) ||
                (in_array($action, ['workspace.view', 'service.scope', 'business.context.manage'], true) && $scope === 'workspace' && $workspaceId !== null);
            if (! $allowedAdvertiserAction) {
                return false;
            }
        } elseif ($lifecycle->principal_class !== 'staff' ||
            ! $connection->table('internal_personnel')->where('user_id', $actor->id)
                ->where('status', 'active')->sharedLock()->first()) {
            return false;
        }

        $query = $connection->table('security_action_grants')
            ->where('subject_user_id', $actor->id)
            ->where('subject_generation', $generation)
            ->where('action', $action)
            ->where('scope_kind', $scope)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        if ($scope === 'workspace') {
            $query->where('workspace_id', $workspaceId);
        } else {
            $query->whereNull('workspace_id');
        }

        return (bool) $query->sharedLock()->first();
    }
}
