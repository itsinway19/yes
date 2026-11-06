<?php

namespace App\Sources;

use App\Models\Workspace;
use App\Security\SessionAuthority;
use App\Security\SourceAuditCustody;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

final class SourceRegistry
{
    public function command(Request $request, Workspace $workspace, array $input): array
    {
        $this->requireTransaction();
        $payload = array_diff_key($input, array_flip(['command_id', 'source_id', 'effect']));
        $result = DB::scalar('SELECT builderad_source_command(?, ?, ?::uuid, ?::uuid, ?, ?::jsonb)', [
            $workspace->id, SessionAuthority::sessionKey($request), $input['command_id'],
            $input['source_id'], $input['effect'], json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        return json_decode($result, true, flags: JSON_THROW_ON_ERROR);
    }

    public function projection(Request $request, Workspace $workspace): array
    {
        $this->requireTransaction();
        DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$workspace->id, SessionAuthority::sessionKey($request)]);
        $capabilities = DB::table('source_capabilities as c')->join('source_registry as s', function ($join): void {
            $join->on('s.id', '=', 'c.source_id')->on('s.workspace_id', '=', 'c.workspace_id');
        })->where('c.workspace_id', $workspace->id)->orderBy('s.created_at')->get([
            's.id', 's.family', 's.namespace', 's.declared_external_id', 's.label', 's.version', 's.status',
            'c.resource', 'c.operation', 'c.purpose',
        ]);
        $items = [];
        $isOwner = DB::table('workspace_ownerships')->where('workspace_id', $workspace->id)
            ->where('responsible_owner_user_id', $request->user()->id)->exists();
        $fresh = app(SessionAuthority::class)->hasFreshAuthentication($request, $request->user())
            && (bool) DB::scalar("SELECT EXISTS (SELECT 1 FROM security_sessions WHERE user_id=? AND session_key_hash=?
                AND authenticated_at BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp())", [
                $request->user()->id, SessionAuthority::sessionKey($request),
            ]);
        $manageGrants = DB::table('security_action_grants')->where('subject_user_id', $request->user()->id)
            ->where('subject_generation', $request->session()->get('a001_generation'))
            ->where('scope_kind', 'source')->where('workspace_id', $workspace->id)->where('action', 'source.manage')
            ->where('status', 'active')->whereNull('revoked_at')->whereRaw('(expires_at IS NULL OR expires_at > clock_timestamp())')
            ->orderBy('source_resource')->orderBy('source_operation')->orderBy('source_purpose')
            ->get(['source_target_id', 'source_resource', 'source_operation', 'source_purpose']);
        foreach ($capabilities as $c) {
            DB::selectOne('SELECT builderad_source_read_scope(?, ?, ?::uuid, ?, ?, ?)', [
                $workspace->id, SessionAuthority::sessionKey($request), $c->id, $c->resource, $c->operation, $c->purpose,
            ]);
            $has = fn (string $action): bool => (bool) DB::scalar('SELECT builderad_source_has(?, ?::uuid, ?, ?, ?, ?)', [
                $action, $c->id, $workspace->id, $c->resource, $c->operation, $c->purpose,
            ]);
            $nativeWritable = (bool) DB::scalar('SELECT builderad_v2_native_source_writable(?,?::uuid,?,?,?)', [
                $workspace->id, $c->id, $c->resource, $c->operation, $c->purpose,
            ]);
            // BB1 has no independently authorized provider verifier; all provider axes remain unknown.
            $axes = array_fill_keys(EffectiveCapability::AXES, 'UNKNOWN');
            $effective = app(EffectiveCapability::class)->derive($c->status, $has('source.read'), [], new DateTimeImmutable);
            $deliveries = DB::table('source_audit_obligations as o')->join('source_audit_delivery as d', 'd.obligation_id', '=', 'o.id')
                ->join('source_command_receipts as r', function ($join): void {
                    $join->on('r.workspace_id', '=', 'o.workspace_id')->on('r.command_id', '=', 'o.command_id')
                        ->on('r.source_id', '=', 'o.source_id');
                })
                ->where('o.workspace_id', $workspace->id)->where('o.source_id', $c->id)
                ->orderBy('o.recorded_at')->get(['o.id', 'o.command_id', 'r.resource', 'r.operation', 'r.purpose', 'r.result', 'd.state', 'd.attempts'])
                ->map(function ($delivery) use ($workspace, $c, $isOwner, $fresh): array {
                    $authorized = (bool) DB::scalar('SELECT builderad_source_has(?, ?::uuid, ?, ?, ?, ?)', [
                        'source.audit.recover', $c->id, $workspace->id, $delivery->resource, $delivery->operation, $delivery->purpose,
                    ]);
                    $reason = $delivery->state === 'ACKNOWLEDGED' ? 'already_acknowledged'
                        : (! $isOwner ? 'responsible_owner_required' : (! $fresh ? 'fresh_authentication_required'
                            : (! $authorized ? 'exact_recovery_authority_required' : null)));
                    $result = json_decode($delivery->result, true, flags: JSON_THROW_ON_ERROR);
                    unset($delivery->result);

                    return [...(array) $delivery, 'receipt_version' => $result['version'],
                        'can_recover' => $reason === null, 'recovery_reason' => $reason];
                });
            // Choices are current exact A-003 grants, never a browser/provider capability catalog.
            // RLS may hide a previously declared tuple; the canonical writer still rejects duplicates.
            $scopeChoices = $manageGrants->filter(fn ($g) => $nativeWritable && $isOwner && $fresh && $c->status !== 'REVOKED'
                && $g->source_target_id === $c->id
                && ! $capabilities->contains(fn ($existing) => $existing->id === $c->id
                    && $existing->resource === $g->source_resource && $existing->operation === $g->source_operation
                    && $existing->purpose === $g->source_purpose)
                && DB::scalar('SELECT builderad_source_has(?, ?::uuid, ?, ?, ?, ?)', [
                    'source.manage', $c->id, $workspace->id, $g->source_resource, $g->source_operation, $g->source_purpose,
                ]))->map(fn ($g) => ['resource' => $g->source_resource, 'operation' => $g->source_operation,
                    'purpose' => $g->source_purpose])->values();
            $items[] = [...(array) $c, 'identity_verification' => 'UNVERIFIED', 'axes' => $axes,
                'capability' => $effective, 'can_manage' => $nativeWritable && $isOwner && $fresh && $has('source.manage') && $c->status !== 'REVOKED',
                'scope_choices' => $scopeChoices, 'audit_delivery' => $deliveries,
                'next_repair' => $c->status === 'REVOKED' ? 'source_revoked' : 'provider_verification_needed'];
        }

        $registration = DB::table('security_action_grants')->where('subject_user_id', $request->user()->id)
            ->where('subject_generation', $request->session()->get('a001_generation'))
            ->where('scope_kind', 'source')->where('workspace_id', $workspace->id)->where('action', 'source.register')
            ->where('status', 'active')->whereNull('revoked_at')->whereRaw('(expires_at IS NULL OR expires_at > clock_timestamp())')
            ->get(['source_target_id', 'source_resource', 'source_operation', 'source_purpose'])
            ->filter(fn ($g) => DB::scalar('SELECT builderad_source_registration_available(?, ?::uuid, ?, ?, ?)', [
                $workspace->id, $g->source_target_id, $g->source_resource, $g->source_operation, $g->source_purpose,
            ]))->values();

        return ['state' => $items ? 'SCOPED_SOURCES' : 'NO_VISIBLE_SOURCE_AUTHORITY', 'items' => $items,
            'registration_authorities' => $registration];
    }

    /** Called in a new Workspace transaction, after the original source effect has committed. */
    public function recover(Request $request, Workspace $workspace, string $obligation): array
    {
        return $this->recoverOwned($request, $workspace->id, null, $obligation);
    }

    public function recoverBusiness(Request $request, int $business, string $obligation): array
    {
        if ($business < 1) {
            throw new LogicException('An exact Business Source locator is required.');
        }

        return $this->recoverOwned($request, null, $business, $obligation);
    }

    private function recoverOwned(Request $request, ?int $workspaceId, ?int $businessId, string $obligation): array
    {
        $this->requireTransaction();
        DB::selectOne('SELECT builderad_source_begin_recovery(?, ?, ?::uuid)', [
            $workspaceId, SessionAuthority::sessionKey($request), $obligation,
        ]);
        $o = DB::table('source_audit_obligations')->where('workspace_id', $workspaceId)->where('id', $obligation)->first();
        $source = DB::table('source_registry')->where('id', $o?->source_id)->where('workspace_id', $workspaceId);
        if ($businessId !== null) {
            $source->where('business_id', $businessId)->where('custody_kind', 'business_native');
        }
        if (! $o || ! $source->exists()) {
            abort(403);
        }
        $receipt = DB::table('source_command_receipts')->where('workspace_id', $workspaceId)->where('source_id', $o->source_id)->where('command_id', $o->command_id)->first();
        if (! $receipt || ! DB::scalar('SELECT builderad_source_has(?, ?::uuid, ?, ?, ?, ?)', [
            'source.audit.recover', $o->source_id, $workspaceId, $receipt->resource, $receipt->operation, $receipt->purpose,
        ])) {
            abort(403);
        }
        // Validate/lock current actor and effect before independent custody, using the same final fence.
        $ack = null;
        try {
            $ack = app(SourceAuditCustody::class)->accept($o->id, $o->fingerprint, $o->envelope);
        } catch (\Throwable $error) {
            report($error);
        }
        $state = DB::scalar('SELECT builderad_source_record_delivery(?, ?, ?::uuid, ?::uuid)', [
            $workspaceId, SessionAuthority::sessionKey($request), $obligation, $ack,
        ]);

        return ['obligation_id' => $obligation, 'delivery_state' => $state];
    }

    private function requireTransaction(): void
    {
        if (DB::connection()->transactionLevel() !== 1) {
            throw new LogicException('Source registry requires the owned Workspace transaction.');
        }
    }
}
