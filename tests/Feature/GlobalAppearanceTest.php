<?php

namespace Tests\Feature;

use App\Appearance\GlobalAppearance;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApplicationContractAssertions;
use Tests\TestCase;

class GlobalAppearanceTest extends TestCase
{
    use ApplicationContractAssertions;

    private const PATH = '/internal/administration/appearance';

    public function test_founder_reads_defaults_publishes_atomically_and_restores_defaults_with_revision_control(): void
    {
        $this->actingAsInternal($founder = $this->staff('founder'));
        $resource = $this->getJson(self::PATH)->assertOk()->assertJsonPath('data.revision', 0)->json();
        $this->assertApplicationContract('GlobalAppearanceResource', $resource);
        $this->assertSame(GlobalAppearance::DEFAULTS['primary'], $resource['data']['primary']);
        $this->getJson(self::PATH, ['X-Builderad-Witness' => '1'])->assertOk()->assertJsonMissingPath('data');
        $payload = ['revision' => 0, 'primary' => '#FFFF00', 'secondary' => '#123456'];
        $result = $this->postJson(self::PATH, $payload)->assertOk()->assertJsonPath('appearance.primary', '#ffff00')->assertJsonPath('appearance.revision', 1)->json();
        $this->assertApplicationContract('AppearancePublication', $result);
        $this->postJson(self::PATH, [...$payload, 'secondary' => '#abcdef'])->assertConflict()->assertJsonMissingPath('saved');
        $this->assertSame('#123456', app(GlobalAppearance::class)->current()['secondary']);
        $this->assertSame(1, DB::table('security_audit_events')->where('event', 'global_appearance_published')->count());
        $this->postJson(self::PATH, ['revision' => 1, ...GlobalAppearance::DEFAULTS])->assertOk()->assertJsonPath('appearance.revision', 2);
        $this->assertSame($founder->id, (int) DB::table('global_appearance')->sole()->published_by);
    }

    public function test_advertisers_employees_and_founders_without_grants_cannot_publish(): void
    {
        $payload = ['revision' => 0, ...GlobalAppearance::DEFAULTS];
        $advertiser = User::factory()->create();
        // An advertiser document is rejected at the existing internal identity boundary first.
        $this->actingAs($advertiser)->postJson(self::PATH, $payload)->assertConflict()->assertJsonPath('identityConflict', true);
        $this->assertDatabaseCount('global_appearance', 0);
        $this->actingAsInternal($this->staff('employee'))->getJson(self::PATH)->assertForbidden();
        $this->postJson(self::PATH, $payload)->assertForbidden();
        $founder = $this->staff('founder');
        DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $founder->id)->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->actingAsInternal($founder)->postJson(self::PATH, $payload)->assertForbidden();
        $this->assertDatabaseCount('global_appearance', 0);
    }

    public function test_validation_identity_and_fresh_auth_failures_do_not_publish(): void
    {
        $this->actingAsInternal($founder = $this->staff('founder'));
        $valid = ['revision' => 0, ...GlobalAppearance::DEFAULTS];
        foreach ([['primary' => '#abc'], ['primary' => 'red'], ['primary' => '#fff000;display:none'], ['primary' => ['#123456']], ['secondary' => '<script>'], ['revision' => -1], ['revision' => 2147483647], ['css' => 'body{}']] as $invalid) {
            $this->postJson(self::PATH, [...$valid, ...$invalid])->assertUnprocessable();
        }
        $this->postJson(self::PATH, $valid, ['X-Builderad-Document' => 'invalid'])->assertConflict();
        DB::connection('pgsql_migrations')->table('security_sessions')->where('user_id', $founder->id)->update(['authenticated_at' => now()->subHour()]);
        $this->postJson(self::PATH, $valid)->assertForbidden();
        $this->assertDatabaseCount('global_appearance', 0);
    }

    public function test_publication_changes_fresh_documents_for_all_advertisers_without_changing_authority_records(): void
    {
        $this->actingAsInternal($founder = $this->staff('founder'));
        $db = DB::connection('pgsql_migrations');
        $before = $db->table('principal_lifecycles')->orderBy('user_id')->get()->toJson();
        $grants = $db->table('security_action_grants')->orderBy('id')->get()->toJson();
        $this->postJson(self::PATH, ['revision' => 0, 'primary' => '#ffffff', 'secondary' => '#012345'])->assertOk();
        $this->assertSame($before, $db->table('principal_lifecycles')->orderBy('user_id')->get()->toJson());
        $this->assertSame($grants, $db->table('security_action_grants')->orderBy('id')->get()->toJson());
        foreach ([User::factory()->create(), User::factory()->create()] as $advertiser) {
            $this->actingAs($advertiser)->get('/workspaces')->assertOk()->assertViewIs('spa')
                ->assertSee('--ba-brand-primary:#ffffff;', false)->assertSee('--ba-secondary-text:#ffffff;', false)
                ->assertSee('--ba-brand-secondary:#012345;', false);
        }
        $this->getJson('/workspaces')->assertOk()->assertJsonMissingPath('appearance')->assertJsonMissingPath('data.appearance');
    }

    public function test_missing_relation_defaults_allow_unmigrated_preview_and_additive_migration_preserves_accounts(): void
    {
        $db = DB::connection('pgsql_migrations');
        $user = User::factory()->create();
        $migration = require database_path('migrations/0001_01_01_000400_create_global_appearance.php');
        $db->statement('ALTER TABLE global_appearance RENAME TO global_appearance_upgrade_fixture');
        try {
            $this->assertSame(0, app(GlobalAppearance::class)->current()['revision']);
            DB::usingConnection('pgsql_migrations', fn () => $migration->up());
            $this->assertSame(0, app(GlobalAppearance::class)->current()['revision']);
            $this->assertSame($user->email, $db->table('users')->where('id', $user->id)->value('email'));
        } finally {
            $db->statement('DROP TABLE IF EXISTS global_appearance');
            $db->statement('ALTER TABLE global_appearance_upgrade_fixture RENAME TO global_appearance');
        }
    }

    public function test_runtime_role_can_read_but_cannot_write_published_appearance(): void
    {
        $this->assertSame(0, DB::table('global_appearance')->count());
        try {
            DB::table('global_appearance')->insert(['id' => 1, 'revision' => 0, 'primary_color' => '#000000', 'secondary_color' => '#000000']);
            $this->fail('Runtime role must not publish directly.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->getCode());
        }
    }

    public function test_failed_audit_rolls_back_the_entire_publication(): void
    {
        $this->actingAsInternal($this->staff('founder'));
        $db = DB::connection('pgsql_migrations');
        $db->unprepared(<<<'SQL'
CREATE FUNCTION appearance_test_audit_failure() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.event = 'global_appearance_published' THEN RAISE EXCEPTION 'Synthetic audit failure'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER appearance_test_audit_failure BEFORE INSERT ON security_audit_events
 FOR EACH ROW EXECUTE FUNCTION appearance_test_audit_failure();
SQL);
        try {
            $this->postJson(self::PATH, ['revision' => 0, ...GlobalAppearance::DEFAULTS])->assertStatus(500);
            $this->assertDatabaseCount('global_appearance', 0);
            $this->assertSame(0, $db->table('security_audit_events')->where('event', 'global_appearance_published')->count());
        } finally {
            $db->unprepared('DROP TRIGGER appearance_test_audit_failure ON security_audit_events; DROP FUNCTION appearance_test_audit_failure()');
        }
    }

    private function staff(string $kind): User
    {
        $user = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $db->table('principal_lifecycles')->where('user_id', $user->id)->update(['principal_class' => 'staff']);
        $db->table('internal_personnel')->insert(['user_id' => $user->id, 'kind' => $kind, 'status' => 'active', 'attested_by' => $user->id,
            'attested_at' => now(), 'factor_independence_attested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1, 2] as $key) {
            $db->table('passkeys')->insert(['user_id' => $user->id, 'name' => 'fixture', 'credential_id' => $user->id.'-'.$key,
                'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        $db->table('security_action_grants')->insert(['subject_user_id' => $user->id, 'source' => 'founder_bootstrap', 'action' => 'grant.manage',
            'scope_kind' => 'platform', 'subject_generation' => 1, 'reason' => 'isolated appearance fixture', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }
}
