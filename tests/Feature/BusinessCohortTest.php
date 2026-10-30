<?php

namespace Tests\Feature;

use App\Foundation\Business\BusinessScope;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BusinessCohortTest extends TestCase
{
    private function fixture(): array
    {
        $u = User::factory()->create();
        $this->actingAs($u);
        $r = Request::create('/api/businesses');
        $r->setUserResolver(fn () => $u);
        $r->setLaravelSession(app('session')->driver());
        $a = app(BusinessScope::class)->create($r, (string) Str::uuid(), 'Recovery customer', null);
        $db = DB::connection('pgsql_migrations');
        $id = $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        $cohort = $db->table('migration_cohort_members')->where('business_id', $id)->value('cohort_id');

        return [$r, $a, $cohort];
    }

    private function transition(string $command, string $cohort, string $mode, int $epoch, string $requested): array
    {
        return json_decode(DB::connection('pgsql_migrations')->scalar('SELECT builderad_v2_cohort_transition(?::uuid,?::uuid,?,?,?,NULL,decode(?,\'hex\'))',
            [$command, $cohort, $mode, $epoch, $requested, hash('sha256', 'test:qualified-isolated-checkpoint')]), true, flags: JSON_THROW_ON_ERROR);
    }

    private function denied(callable $operation, string $state = '42501'): void
    {
        try {
            $operation();
            $this->fail('Forbidden maintenance action succeeded.');
        } catch (QueryException $error) {
            $this->assertSame($state, $error->errorInfo[0]);
        }
    }

    public function test_new_foundation_hold_and_recovery_are_persistent_epoch_cas_and_never_legacy_downgrade(): void
    {
        [$r, $a, $cohort] = $this->fixture();
        $command = (string) Str::uuid();
        $hold = $this->transition($command, $cohort, 'V2_ACTIVE', 0, 'RECOVERY_HOLD');
        $this->assertSame(1, $hold['epoch']);
        $this->assertSame('RECOVERY_HOLD', $hold['mode']);
        $this->assertSame($hold, $this->transition($command, $cohort, 'V2_ACTIVE', 0, 'RECOVERY_HOLD'));
        $this->denied(fn () => app(BusinessScope::class)->read($r, $a['business_public_id'], ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $a['grant_refs']['business.context.read']]]]));
        $this->denied(fn () => $this->transition((string) Str::uuid(), $cohort, 'RECOVERY_HOLD', 1, 'LEGACY_ACTIVE'));
        $this->denied(fn () => $this->transition((string) Str::uuid(), $cohort, 'RECOVERY_HOLD', 0, 'V2_ACTIVE'), '40001');
        $live = $this->transition((string) Str::uuid(), $cohort, 'RECOVERY_HOLD', 1, 'V2_ACTIVE');
        $this->assertSame(2, $live['epoch']);
        $this->assertSame('Recovery customer', app(BusinessScope::class)->read($r, $a['business_public_id'], ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $a['grant_refs']['business.context.read']]]])['name']);
        $this->assertSame(2, DB::connection('pgsql_migrations')->table('business_command_receipts')->where('action', 'maintenance.cohort.transition')->count());
    }

    public function test_app_and_authority_have_neither_maintenance_execute_nor_new_history_reads(): void
    {
        [$r, $a, $cohort] = $this->fixture();
        foreach (['pgsql', 'pgsql_authority'] as $connection) {
            $this->denied(fn () => DB::connection($connection)->scalar("SELECT builderad_v2_cohort_transition(?::uuid,?::uuid,'V2_ACTIVE',0,'RECOVERY_HOLD',NULL,decode(?,'hex'))",
                [(string) Str::uuid(), $cohort, hash('sha256', 'test')]));
            $this->denied(fn () => DB::connection($connection)->select('SELECT * FROM legacy_scope_mappings'));
            $this->denied(fn () => DB::connection($connection)->select('SELECT * FROM material_lineage_manifests'));
        }
    }
}
