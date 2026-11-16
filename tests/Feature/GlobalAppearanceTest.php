<?php

namespace Tests\Feature;

use App\Appearance\GlobalAppearance;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AppearanceCatalogEvidence;
use Tests\Support\ApplicationContractAssertions;
use Tests\TestCase;

class GlobalAppearanceTest extends TestCase
{
    use ApplicationContractAssertions;

    private const PATH = '/internal/administration/appearance';

    private function images(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/brand-images.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function sealFixtureCatalog(): void
    {
        // Only the guarded disposable fixture owns this synthetic predecessor.
        DB::connection('pgsql_migrations')->unprepared(<<<'SQL'
DO $fixture$
DECLARE seal bytea;
BEGIN
 SET LOCAL ROLE builderad_scope_executor;
 seal:=builderad_private.catalog_digest();
 RESET ROLE;
 EXECUTE format('CREATE OR REPLACE FUNCTION builderad_private.installation_digest() RETURNS bytea LANGUAGE sql IMMUTABLE SECURITY INVOKER SET search_path=pg_catalog,builderad_private,pg_temp AS %L', 'SELECT decode('''||encode(seal,'hex')||''',''hex'')');
END $fixture$;
SQL);
    }

    public function test_pre_image_schema_keeps_color_publication_and_rejects_images_without_any_write(): void
    {
        $this->actingAsInternal($this->staff('founder'));
        $db = DB::connection('pgsql_migrations');
        $db->statement('ALTER TABLE global_appearance DROP COLUMN logo_sha256, DROP COLUMN favicon_sha256');
        $db->statement('DROP TABLE global_appearance_images');
        $this->sealFixtureCatalog();
        try {
            $this->postJson(self::PATH, ['revision' => 0, ...GlobalAppearance::DEFAULTS])->assertOk()->assertJsonPath('appearance.revision', 1);
            $this->postJson(self::PATH, ['revision' => 1, 'primary' => '#123456', 'secondary' => '#654321', ...$this->images()])->assertUnprocessable()->assertJsonValidationErrors('logo');
            $this->assertSame(1, app(GlobalAppearance::class)->current()['revision']);
            $this->assertSame(GlobalAppearance::DEFAULTS['primary'], app(GlobalAppearance::class)->current()['primary']);
        } finally {
            $db->unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000002_publish_brand_images/images.sql')));
        }
    }

    public function test_image_migration_refuses_to_reseal_an_unqualified_predecessor(): void
    {
        $db = DB::connection('pgsql_migrations');
        $db->beginTransaction();
        try {
            $db->statement('ALTER TABLE global_appearance DROP COLUMN logo_sha256, DROP COLUMN favicon_sha256');
            $db->statement('DROP TABLE global_appearance_images');
            try {
                $db->unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000002_publish_brand_images/images.sql')));
                $this->fail('Unsealed predecessor must be rejected');
            } catch (QueryException $error) {
                $this->assertStringContainsString('requires a sealed predecessor catalog', $error->getMessage());
            }
        } finally {
            $db->rollBack();
        }
    }

    public function test_canonical_baseline_catalog_evidence_for_the_existing_preview(): void
    {
        $db = DB::connection('pgsql_migrations');
        $db->beginTransaction();
        try {
            $db->statement('ALTER TABLE global_appearance DROP COLUMN logo_sha256, DROP COLUMN favicon_sha256');
            $db->statement('DROP TABLE global_appearance_images');
            preg_match_all("/business_native_baseline\/([^']+\\.sql)/", file_get_contents(database_path('migrations/0001_01_01_000000_business_native_baseline.php')), $inputs);
            $source = implode("\n", array_map(fn ($name) => file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/'.$name)), $inputs[1]));
            $this->assertGreaterThan(0, preg_match_all('/CREATE\s+(?:OR\s+REPLACE\s+)?FUNCTION\s+public\.builderad_v2_customer_discovery_practice\s*\([\s\S]*?\bAS\s+(\$[A-Za-z_0-9]*\$)(.*?)\1;/si', $source, $definitions));
            $definition = $definitions[0][array_key_last($definitions[0])];
            $definition = preg_replace('/^CREATE\s+(?:OR\s+REPLACE\s+)?FUNCTION/i', 'CREATE OR REPLACE FUNCTION', $definition);
            $db->unprepared('GRANT CREATE ON SCHEMA public TO builderad_scope_executor; SET LOCAL ROLE builderad_scope_executor;');
            $db->unprepared($definition);
            $db->unprepared('RESET ROLE; REVOKE CREATE ON SCHEMA public FROM builderad_scope_executor;');
            $image = AppearanceCatalogEvidence::read($db);
            $this->assertNotEmpty($image['functions']);
            if (getenv('GOALS_SPA_RUN')) {
                file_put_contents(getenv('GOALS_SPA_RUN').'/canonical-baseline-catalog.json', json_encode($image, JSON_THROW_ON_ERROR));
            }
        } finally {
            $db->rollBack();
        }
    }

    public function test_published_images_replace_header_and_favicon_and_public_bytes_contain_no_identity(): void
    {
        $this->actingAsInternal($this->staff('founder'));
        $images = $this->images();
        $result = $this->postJson(self::PATH, ['revision' => 0, ...GlobalAppearance::DEFAULTS, ...$images])->assertOk()->json();
        $this->assertApplicationContract('AppearancePublication', $result);
        $published = $result['appearance'];
        $this->assertSame(80, $published['logo']['width']);
        $this->assertSame(32, $published['favicon']['height']);
        // The real anonymous page is exercised in the browser proof. Do not carry
        // the internal fixture's document receipt across the public boundary.
        $this->view('public.brand')->assertSee('data-published-brand-logo', false)->assertSee($published['logo']['url'], false);
        $this->view('public.appearance-assets')->assertSee($published['favicon']['url'], false);
        $this->get('/internal/administration/appearance')->assertOk()->assertSee('name="builderad-logo"', false)->assertSee($published['logo']['url'], false);
        $response = $this->get($published['logo']['url'])->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeaderMissing('X-Builderad-Identity')->assertHeaderMissing('Set-Cookie');
        $this->assertSame(base64_decode(explode(',', $images['logo'], 2)[1]), $response->getContent());
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
        $this->get($published['logo']['url'], ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
        $this->get('/brand/assets/'.str_repeat('0', 64).'.png')->assertNotFound();
        $this->assertDatabaseCount('global_appearance_images', 2);
    }

    public function test_color_only_clients_preserve_images_and_explicit_defaults_keep_old_urls_valid(): void
    {
        $this->actingAsInternal($this->staff('founder'));
        $first = $this->postJson(self::PATH, ['revision' => 0, ...GlobalAppearance::DEFAULTS, ...$this->images()])->assertOk()->json('appearance');
        $second = $this->postJson(self::PATH, ['revision' => 1, 'primary' => '#123456', 'secondary' => '#654321'])->assertOk()->json('appearance');
        $this->assertSame($first['logo'], $second['logo']);
        $this->assertSame($first['favicon'], $second['favicon']);
        $this->postJson(self::PATH, ['revision' => 1, ...GlobalAppearance::DEFAULTS, 'logo' => null])->assertConflict();
        $this->assertSame($first['logo'], app(GlobalAppearance::class)->current()['logo']);
        $this->postJson(self::PATH, ['revision' => 2, ...GlobalAppearance::DEFAULTS, 'logo' => null, 'favicon' => null])
            ->assertOk()->assertJsonPath('appearance.logo', null)->assertJsonPath('appearance.favicon', null);
        $this->get($first['logo']['url'])->assertOk();
        $this->get($first['favicon']['url'])->assertOk();
        $this->assertDatabaseCount('global_appearance_images', 2);
    }

    public function test_image_validation_and_missing_founder_authority_never_store_files(): void
    {
        $payload = ['revision' => 0, ...GlobalAppearance::DEFAULTS, ...$this->images()];
        $this->actingAsInternal($this->staff('employee'))->postJson(self::PATH, $payload)->assertForbidden();
        $this->actingAsInternal($founder = $this->staff('founder'));
        foreach (['https://example.test/logo.png', 'data:image/svg+xml;base64,'.base64_encode('<svg onload="alert(1)"/>'), 'data:image/png;base64,'.base64_encode('<?php echo 1;'), 'data:image/png;base64,'.str_repeat('A', 699052), ['content' => 'PNG']] as $invalid) {
            $this->postJson(self::PATH, [...$payload, 'logo' => $invalid])->assertUnprocessable();
        }
        $this->postJson(self::PATH, [...$payload, 'favicon' => $this->images()['logo']])->assertUnprocessable();
        DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $founder->id)->update(['status' => 'revoked']);
        $this->postJson(self::PATH, $payload)->assertForbidden();
        $this->assertDatabaseCount('global_appearance_images', 0);
        $this->assertDatabaseCount('global_appearance', 0);
    }

    public function test_brand_image_permissions_are_public_read_and_restricted_immutable_insert(): void
    {
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            $this->assertFalse((bool) DB::scalar("SELECT has_table_privilege('builderad_app','public.global_appearance_images',?)", [$privilege]));
        }
        $this->assertTrue((bool) DB::scalar("SELECT has_table_privilege('builderad_authority','public.global_appearance_images','INSERT')"));
        $this->assertFalse((bool) DB::scalar("SELECT has_table_privilege('builderad_authority','public.global_appearance_images','UPDATE,DELETE')"));
    }

    public function test_forward_image_upgrade_preserves_existing_published_colors_and_revision(): void
    {
        $db = DB::connection('pgsql_migrations');
        $db->beginTransaction();
        try {
            $db->statement('ALTER TABLE global_appearance DROP COLUMN logo_sha256, DROP COLUMN favicon_sha256');
            $db->statement('DROP TABLE global_appearance_images');
            $db->table('global_appearance')->insert(['id' => 1, 'revision' => 4, 'primary_color' => '#123456', 'secondary_color' => '#654321']);
            $this->sealFixtureCatalog();
            $db->unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000002_publish_brand_images/images.sql')));
            $row = $db->table('global_appearance')->sole();
            $this->assertSame(4, (int) $row->revision);
            $this->assertSame('#123456', $row->primary_color);
            $this->assertSame('#654321', $row->secondary_color);
            $this->assertNull($row->logo_sha256);
            $this->assertNull($row->favicon_sha256);
        } finally {
            $db->rollBack();
        }
    }

    public function test_expired_freshness_has_a_bound_reauthentication_contract_only_while_founder_authority_survives(): void
    {
        $this->actingAsInternal($founder = $this->staff('founder'));
        $db = DB::connection('pgsql_migrations');
        $db->table('security_sessions')->where('user_id', $founder->id)->update(['authenticated_at' => now()->subMinutes(6)]);
        $payload = ['revision' => 0, ...GlobalAppearance::DEFAULTS];
        $reply = $this->postJson(self::PATH, $payload)->assertForbidden()
            ->assertJsonPath('code', 'fresh_authentication_required')
            ->assertJsonPath('reauthenticate', '/internal/identity/switch');
        $binding = json_decode($reply->headers->get('X-Builderad-Identity'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($founder->id, $binding['binding']['principal']);
        $this->getJson(self::PATH)->assertOk();
        $this->get('/internal/identity/switch')->assertOk();
        $this->assertDatabaseCount('global_appearance', 0);
        $db->table('security_action_grants')->where('subject_user_id', $founder->id)->where('action', 'grant.manage')->update(['status' => 'revoked']);
        $this->postJson(self::PATH, $payload)->assertForbidden()->assertJsonMissingPath('code');
        $this->assertDatabaseCount('global_appearance', 0);
    }

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
            $this->actingAs($advertiser)->get('/workspaces')->assertNotFound();
            $this->get('/businesses')->assertOk()->assertViewIs('spa')
                ->assertSee('--ba-brand-primary:#ffffff;', false)->assertSee('--ba-secondary-text:#ffffff;', false)
                ->assertSee('--ba-brand-secondary:#012345;', false);
        }
        $this->getJson('/businesses')->assertOk()->assertJsonMissingPath('appearance')->assertJsonMissingPath('data.appearance');
    }

    public function test_missing_relation_defaults_allow_unmigrated_preview(): void
    {
        $db = DB::connection('pgsql_migrations');
        $user = User::factory()->create();
        $db->statement('ALTER TABLE global_appearance RENAME TO global_appearance_upgrade_fixture');
        try {
            $this->assertSame(0, app(GlobalAppearance::class)->current()['revision']);
            $this->assertSame($user->email, $db->table('users')->where('id', $user->id)->value('email'));
        } finally {
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
            $this->postJson(self::PATH, ['revision' => 0, ...GlobalAppearance::DEFAULTS, ...$this->images()])->assertStatus(500);
            $this->assertDatabaseCount('global_appearance', 0);
            $this->assertDatabaseCount('global_appearance_images', 0);
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
