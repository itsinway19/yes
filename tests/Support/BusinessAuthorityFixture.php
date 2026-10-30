<?php

namespace Tests\Support;

use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit synthetic qualification graph; never installed as a runtime root-issuer policy. */
final class BusinessAuthorityFixture
{
    public static function insert(string $table, array $values, int $actor): object
    {
        app(DisposableTestDatabaseGuard::class)->assertSafe();
        if (! in_array($table, ['authority_qualification_receipts', 'security_action_grants', 'business_workspace_links',
            'client_data_boundaries', 'resource_right_fences', 'boundary_subjects', 'data_use_scopes', 'business_data_use_bindings'], true)) {
            throw new \LogicException('Unregistered synthetic fixture relation.');
        }
        $owner = DB::connection('pgsql_migrations');

        return $owner->transaction(function () use ($owner, $table, $values, $actor): object {
            $owner->statement('SET LOCAL ROLE builderad_scope_executor');
            $owner->selectOne("SELECT builderad_private.start_command(?,'synthetic-session',?::uuid,'test.synthetic.fixture','{}'::jsonb,'{\"fixture\":true}'::jsonb)", [$actor, (string) Str::uuid()]);
            $json = json_encode($values, JSON_THROW_ON_ERROR);
            $owner->selectOne("SELECT builderad_private.permit('public.{$table}'::regclass,'insert',NULL,to_jsonb(r)) FROM jsonb_populate_record(NULL::public.{$table},?::jsonb) r", [$json]);
            $row = $owner->selectOne("INSERT INTO public.{$table} SELECT r.* FROM jsonb_populate_record(NULL::public.{$table},?::jsonb) r RETURNING *", [$json]);
            $owner->selectOne('SELECT builderad_private.finish_command()');

            return $row;
        });
    }

    public static function root(int|string $business, int $actor, string $action, bool $delegable = true, string $kind = 'business', ?string $validUntil = null): object
    {
        $owner = DB::connection('pgsql_migrations');
        $q = self::insert('authority_qualification_receipts', [
            'qualification_id' => (string) Str::uuid7(), 'policy_id' => 'test.synthetic.qualified_root', 'policy_version' => 1,
            'issuer_kind' => 'qualified_policy', 'issuer_grant_id' => null, 'subject_user_id' => $actor,
            'target_kind' => $kind, 'target_ref' => $kind === 'business' ? $owner->table('businesses')->where('id', $business)->value('public_id') : $business,
            'allowed_actions' => '{'.$action.'}', 'evidence_ref' => 'test:synthetic-qualified-root',
            'evidence_digest' => '\\x'.hash('sha256', 'synthetic-qualified-root'), 'status' => 'active', 'revision' => 1,
            'valid_from' => now()->subSecond()->toIso8601String(), 'valid_until' => $validUntil,
            'provenance' => 'SYNTHETIC TEST ONLY; not evidence of an authorized production issuer',
        ], $actor);

        return self::insert('security_action_grants', [
            'id' => (int) $owner->scalar("SELECT nextval('security_action_grants_id_seq')"),
            'subject_user_id' => $actor, 'granted_by' => null, 'source' => 'qualified_policy', 'action' => $action,
            'scope_kind' => $kind, match ($kind) {
                'business' => 'business_id', 'client_data' => 'boundary_id', 'data_use' => 'data_use_scope_id'
            } => $business,
            'subject_generation' => $owner->table('principal_lifecycles')->where('user_id', $actor)->value('generation'),
            'reason' => 'SYNTHETIC TEST ROOT', 'status' => 'active', 'version' => 1,
            'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(), 'runtime_lock_marker' => false,
            'qualification_id' => $q->qualification_id, 'parent_grant_id' => null, 'delegable' => $delegable,
            'delegation_realm_kind' => 'direct', 'delegation_workspace_id' => null,
        ], $actor);
    }

    public static function boundary(int $actor): array
    {
        $owner = DB::connection('pgsql_migrations');
        $id = (string) Str::uuid7();
        $q = self::insert('authority_qualification_receipts', [
            'qualification_id' => (string) Str::uuid7(), 'policy_id' => 'test.synthetic.boundary_root', 'policy_version' => 1,
            'issuer_kind' => 'qualified_policy', 'issuer_grant_id' => null, 'subject_user_id' => $actor,
            'target_kind' => 'client_data', 'target_ref' => $id, 'allowed_actions' => '{client_data.manage}',
            'evidence_ref' => 'test:synthetic-boundary', 'evidence_digest' => '\\x'.hash('sha256', 'synthetic-boundary'),
            'status' => 'active', 'revision' => 1, 'valid_from' => now()->subSecond()->toIso8601String(), 'valid_until' => null,
            'provenance' => 'SYNTHETIC TEST ONLY; not an operating root policy',
        ], $actor);
        self::insert('client_data_boundaries', ['boundary_id' => $id, 'status' => 'active', 'revision' => 1,
            'descriptor' => ['parties' => [], 'resources' => []], 'descriptor_schema_version' => 1, 'qualification_ref' => $q->qualification_id], $actor);
        $f = self::insert('resource_right_fences', ['fence_id' => (string) Str::uuid7(), 'kind' => 'client_boundary',
            'source_id' => null, 'boundary_id' => $id, 'rights_epoch' => 0, 'adoption_state' => 'v2_adopted'], $actor);
        $g = self::insert('security_action_grants', ['id' => (int) $owner->scalar("SELECT nextval('security_action_grants_id_seq')"),
            'subject_user_id' => $actor, 'granted_by' => null, 'source' => 'qualified_policy', 'action' => 'client_data.manage',
            'scope_kind' => 'client_data', 'boundary_id' => $id, 'subject_generation' => $owner->table('principal_lifecycles')->where('user_id', $actor)->value('generation'),
            'reason' => 'SYNTHETIC BOUNDARY', 'status' => 'active', 'version' => 1, 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            'runtime_lock_marker' => false, 'qualification_id' => $q->qualification_id, 'parent_grant_id' => null,
            'delegable' => true, 'delegation_realm_kind' => 'direct', 'delegation_workspace_id' => null], $actor);

        return ['boundary_id' => $id, 'grant_id' => $g->id, 'fence_id' => $f->fence_id];
    }

    /** Schedule a real short validity window before a test participant starts its protected transaction. */
    public static function expireQualificationSoon(string $id, int $actor): string
    {
        app(DisposableTestDatabaseGuard::class)->assertSafe();
        $db = DB::connection('pgsql_migrations');

        return $db->transaction(function () use ($db, $id, $actor): string {
            $db->statement('SET LOCAL ROLE builderad_scope_executor');
            $db->selectOne("SELECT builderad_private.start_command(?,'synthetic-session',?::uuid,'test.synthetic.qualification_expiry','{}'::jsonb,'{\"fixture\":true}'::jsonb)", [$actor, (string) Str::uuid()]);
            $before = $db->scalar('SELECT to_jsonb(q)::text FROM authority_qualification_receipts q WHERE qualification_id=?::uuid', [$id]);
            $after = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
            $after['valid_until'] = $db->scalar("SELECT (clock_timestamp()+interval '2 seconds')::text");
            $after['revision']++;
            $db->selectOne("SELECT builderad_private.permit('public.authority_qualification_receipts','update',?::jsonb,?::jsonb)", [$before, json_encode($after, JSON_THROW_ON_ERROR)]);
            $db->update('UPDATE authority_qualification_receipts SET valid_until=?::timestamptz,revision=revision+1 WHERE qualification_id=?::uuid', [$after['valid_until'], $id]);
            $db->selectOne('SELECT builderad_private.finish_command()');

            return $after['valid_until'];
        });
    }

    public static function revokeQualification(string $id, int $actor): void
    {
        app(DisposableTestDatabaseGuard::class)->assertSafe();
        $db = DB::connection('pgsql_migrations');
        $db->transaction(function () use ($db, $id, $actor): void {
            $db->statement('SET LOCAL ROLE builderad_scope_executor');
            $db->selectOne("SELECT builderad_private.start_command(?,'synthetic-session',?::uuid,'test.synthetic.qualification_revoke','{}'::jsonb,'{\"fixture\":true}'::jsonb)", [$actor, (string) Str::uuid()]);
            $before = $db->scalar('SELECT to_jsonb(q)::text FROM authority_qualification_receipts q WHERE qualification_id=?::uuid', [$id]);
            $after = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
            $after['status'] = 'revoked';
            $after['revision']++;
            $db->selectOne("SELECT builderad_private.permit('public.authority_qualification_receipts','update',?::jsonb,?::jsonb)", [$before, json_encode($after, JSON_THROW_ON_ERROR)]);
            $db->update("UPDATE authority_qualification_receipts SET status='revoked',revision=revision+1 WHERE qualification_id=?::uuid", [$id]);
            $db->selectOne('SELECT builderad_private.finish_command()');
        });
    }
}
