<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Models\Workspace;
use App\Security\GrantAuthority;
use App\Security\ServiceWorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeclaredTargetMarketsSecurityTest extends TestCase
{
    private function business(string $slug = 'security-declaration'): object
    {
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Security declaration', 'slug' => $slug])->assertRedirect();

        return DB::connection('pgsql_migrations')->table('workspaces')->where('slug', $slug)->first();
    }

    private function snapshot(object $workspace): array
    {
        $db = DB::connection('pgsql_migrations');

        return ['workspace' => (array) $db->table('workspaces')->find($workspace->id),
            'markets' => $db->table('business_target_markets')->orderBy('workspace_id')->orderBy('country_code')->get()->toArray(),
            'events' => $db->table('business_target_market_events')->orderBy('id')->get()->toArray()];
    }

    private function denied(callable $operation, string $connection = 'pgsql'): void
    {
        try {
            DB::connection($connection)->transaction($operation);
            $this->fail('An unauthorized SQL effect succeeded.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->getCode(), $error->getMessage());
        }
    }

    public function test_direct_runtime_dml_and_unsigned_writer_denied(): void
    {
        $workspace = $this->business();
        $before = $this->snapshot($workspace);
        foreach (['country_references', 'business_target_markets', 'business_target_market_events'] as $table) {
            foreach (['DELETE FROM '.$table, 'TRUNCATE '.$table] as $sql) {
                $this->denied(fn () => DB::statement($sql));
            }
        }
        $this->denied(fn () => DB::statement('INSERT INTO country_references VALUES (?, ?, ?)', ['ZZ', 'Synthetic', 'Synthetic']));
        $this->denied(fn () => DB::statement('UPDATE country_references SET name_en=? WHERE code=?', ['Synthetic', 'SA']));
        $this->denied(fn () => DB::statement('INSERT INTO business_target_markets VALUES (?,?)', [$workspace->id, 'SA']));
        $this->denied(fn () => DB::statement('UPDATE business_target_markets SET country_code=?', ['SA']));
        $this->denied(fn () => DB::statement("INSERT INTO business_target_market_events(workspace_id,actor_user_id,version,before_codes,after_codes) VALUES (?,?,1,ARRAY[]::text[],ARRAY['SA'])", [$workspace->id, auth()->id()]));
        $this->denied(fn () => DB::statement('UPDATE business_target_market_events SET version=2'));
        $this->denied(fn () => DB::scalar("SELECT builderad_business_target_markets_write(?, ?, ARRAY['SA'], 0)", [$workspace->id, str_repeat('f', 64)]));
        $this->denied(function () use ($workspace): void {
            DB::selectOne("SELECT set_config('app.workspace_id',?,true),set_config('app.authorization_phase','workspace',true),set_config('app.business_context_prepared','forged',true)", [(string) $workspace->id]);
            DB::scalar("SELECT builderad_business_target_markets_write(?, ?, ARRAY['SA'], 0)", [$workspace->id, str_repeat('f', 64)]);
        });
        $this->assertEquals($before, $this->snapshot($workspace));
    }

    public function test_workspace_rls_hides_foreign_rows_and_foreign_writer_is_denied(): void
    {
        $first = $this->business('first-declaration');
        $firstActor = auth()->user();
        $this->post('/workspaces/'.$first->public_id.'/business/target-markets', ['country_codes' => ['SA'], 'version' => 0])->assertRedirect();
        $second = $this->business('second-declaration');
        $this->post('/workspaces/'.$second->public_id.'/business/target-markets', ['country_codes' => ['JO'], 'version' => 0])->assertRedirect();
        $this->actingAs($firstActor);
        $request = Request::create('/', 'GET');
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn () => $firstActor);
        app(WorkspaceContext::class)->run($firstActor, $first->public_id, function () use ($first, $second): void {
            $this->assertSame(['SA'], DB::table('business_target_markets')->pluck('country_code')->all());
            $this->assertSame([(int) $first->id], DB::table('business_target_market_events')->pluck('workspace_id')->all());
            $this->denied(fn () => DB::scalar("SELECT builderad_business_target_markets_write(?, ?, ARRAY['JO'], 1)", [$second->id, str_repeat('f', 64)]));
        }, $request);
        $before = $this->snapshot($second);
        $this->post('/workspaces/'.$second->public_id.'/business/target-markets', ['country_codes' => [], 'version' => 1, 'owner_user_id' => $firstActor->id, 'workspace_id' => $first->id])->assertForbidden();
        $this->assertEquals($before, $this->snapshot($second));
    }

    public function test_valid_scoped_service_cannot_declare(): void
    {
        $workspace = $this->business();
        $before = $this->snapshot($workspace);
        $db = DB::connection('pgsql_migrations');
        $credential = bin2hex(random_bytes(32));
        $service = $db->table('service_identities')->insertGetId(['name' => 'Synthetic DTM reader', 'status' => 'enabled',
            'credential_hash' => hash('sha256', $credential), 'version' => 1, 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        $public = $db->table('service_identities')->where('id', $service)->value('public_id');
        $db->table('service_workspace_scopes')->insert(['service_identity_id' => $service, 'workspace_id' => $workspace->id,
            'authorized_by' => auth()->id(), 'purpose' => 'synthetic_metadata_read', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $db->table('service_action_grants')->insert(['service_identity_id' => $service, 'workspace_id' => $workspace->id,
            'granted_by' => auth()->id(), 'action' => 'workspace.view', 'reason' => 'synthetic_metadata_read', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        app(ServiceWorkspaceContext::class)->run($public, $credential, $workspace->public_id, function (Workspace $active): void {
            $this->assertSame('service', DB::scalar("SELECT current_setting('app.authorization_phase')"));
            $this->denied(fn () => DB::scalar("SELECT builderad_business_target_markets_write(?, ?, ARRAY['SA'], 0)", [$active->id, str_repeat('f', 64)]));
            $this->denied(fn () => DB::scalar('SELECT builderad_business_context_begin(?,?)', [$active->id, str_repeat('f', 64)]));
        });
        $this->assertEquals($before, $this->snapshot($workspace));
    }

    public function test_internal_management_is_not_advertiser_declaration_authority(): void
    {
        $workspace = $this->business();
        $before = $this->snapshot($workspace);
        $db = DB::connection('pgsql_migrations');
        $staff = User::factory()->create();
        $db->table('principal_lifecycles')->where('user_id', $staff->id)->update(['principal_class' => 'staff']);
        $db->table('internal_personnel')->insert(['user_id' => $staff->id, 'kind' => 'employee', 'status' => 'active',
            'attested_by' => $staff->id, 'attested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $db->table('passkeys')->insert(['user_id' => $staff->id, 'name' => 'Synthetic factor', 'credential_id' => 'dtm-staff-'.$staff->id,
            'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('security_action_grants')->insert(['subject_user_id' => $staff->id, 'granted_by' => auth()->id(), 'source' => 'human',
            'subject_generation' => 1, 'scope_kind' => 'platform', 'action' => 'business.manage', 'reason' => 'Synthetic internal administration',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('workspace_memberships')->insert(['workspace_id' => $workspace->id, 'user_id' => $staff->id,
            'role' => 'member', 'status' => 'active', 'created_at' => now()]);
        $db->table('security_action_grants')->insert(['subject_user_id' => $staff->id, 'granted_by' => auth()->id(), 'source' => 'human',
            'subject_generation' => 1, 'scope_kind' => 'workspace', 'workspace_id' => $workspace->id, 'action' => 'workspace.view',
            'reason' => 'Synthetic staff view only', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsInternal($staff);
        $this->assertSame('internal_passkey', $db->table('security_sessions')->where('user_id', $staff->id)->value('auth_method'));
        $session = $db->table('security_sessions')->where('user_id', $staff->id)->value('session_key_hash');
        $this->denied(function () use ($staff, $session, $workspace): void {
            app(PrincipalContext::class)->activate($staff);
            DB::scalar('SELECT builderad_business_context_begin(?,?)', [$workspace->id, $session]);
            DB::scalar("SELECT builderad_business_target_markets_write(?, ?, ARRAY['SA'], 0)", [$workspace->id, $session]);
        });
        $this->assertEquals($before, $this->snapshot($workspace));
    }

    public function test_catalog_execution_acl_owner_search_path_and_force_rls(): void
    {
        $owner = DB::connection('pgsql_migrations');
        $signature = 'builderad_business_target_markets_write(bigint,text,text[],bigint)';
        $function = $owner->selectOne('SELECT pg_get_userbyid(proowner) AS owner, prosecdef, proconfig::text FROM pg_proc WHERE oid=?::regprocedure', [$signature]);
        $this->assertSame('builderad_owner', $function->owner);
        $this->assertTrue((bool) $function->prosecdef);
        $this->assertStringContainsString('search_path=public, pg_temp', $function->proconfig);
        $this->assertTrue((bool) $owner->scalar('SELECT has_function_privilege(?, ?, ?)', ['builderad_app', $signature, 'EXECUTE']));
        $this->assertFalse((bool) $owner->scalar('SELECT has_function_privilege(?, ?, ?)', ['builderad_authority', $signature, 'EXECUTE']));
        $this->assertSame(0, (int) $owner->scalar("SELECT count(*) FROM pg_proc p, LATERAL aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a WHERE p.oid=?::regprocedure AND a.grantee=0 AND a.privilege_type='EXECUTE'", [$signature]));
        foreach (['business_target_markets', 'business_target_market_events'] as $table) {
            $catalog = $owner->selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid=?::regclass', [$table]);
            $this->assertTrue((bool) $catalog->relrowsecurity);
            $this->assertTrue((bool) $catalog->relforcerowsecurity);
        }
        $this->denied(fn () => DB::connection('pgsql_authority')->scalar("SELECT builderad_business_target_markets_write(1,?,ARRAY['SA'],0)", [str_repeat('f', 64)]), 'pgsql_authority');
        $this->denied(fn () => DB::scalar('SELECT builderad_business_context_require_prepared(1)'));
    }
}
