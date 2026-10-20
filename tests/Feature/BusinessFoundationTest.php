<?php

namespace Tests\Feature;

use App\Http\Middleware\RestoreBusinessAfterCallback;
use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson as Assert;
use Tests\TestCase;

class BusinessFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('pgsql_migrations')->statement('TRUNCATE business_source_guidance,business_promotion_priors RESTART IDENTITY');
        DB::connection('pgsql_migrations')->table('business_types')->update(['active' => true]);
    }

    private function type(string $key = 'real_estate'): int
    {
        return DB::connection('pgsql_migrations')->table('business_types')->where('key', $key)->value('id');
    }

    private function business(User $user, string $slug = 'original'): object
    {
        if (! DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $user->id)
            ->where('action', 'workspace.create')->exists()) {
            app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        }
        $this->actingAs($user)->post('/workspaces', ['name' => 'Original business', 'slug' => $slug])->assertRedirect();

        return DB::connection('pgsql_migrations')->table('workspaces')->where('slug', $slug)->first();
    }

    private function classify(object $workspace, string $key = 'real_estate', int $version = 0)
    {
        return $this->post('/workspaces/'.$workspace->public_id.'/business', [
            'business_type_id' => $this->type($key), 'version' => $version,
        ]);
    }

    private function administrator(): User
    {
        $user = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $db->table('principal_lifecycles')->where('user_id', $user->id)->update(['principal_class' => 'staff']);
        $db->table('internal_personnel')->insert(['user_id' => $user->id, 'kind' => 'founder', 'status' => 'active',
            'attested_by' => $user->id, 'attested_at' => now(), 'factor_independence_attested_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        foreach ([1, 2] as $number) {
            $db->table('passkeys')->insert(['user_id' => $user->id, 'name' => 'Test authenticator '.$number,
                'credential_id' => 'business-test-'.$user->id.'-'.$number, 'credential' => '{}',
                'created_at' => now(), 'updated_at' => now()]);
        }
        app(GrantAuthority::class)->issueFounderBootstrapGrants($user, 1);
        $this->actingAsInternal($user);

        return $user;
    }

    private function prior(): array
    {
        return ['provider' => 'google', 'product' => 'google.search', 'basis' => 'ADMIN_APPROVED_HYPOTHESIS',
            'documented_basis' => 'Controlled test hypothesis, not production evidence',
            'applicability' => 'Only when relevant qualified search demand exists',
            'limits' => 'Not a spend instruction or proof of advertiser performance',
            'rationale_ar' => 'اعتبار مبدئي مشروط بوجود طلب بحث مناسب',
            'rationale_en' => 'Initial consideration conditional on relevant search demand',
            'internal_rationale' => 'Qualified search intent may be relevant in the described context',
            'approved' => true, 'active' => true];
    }

    public function test_first_creation_is_atomic_minimal_and_idempotent(): void
    {
        $user = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('business.start'));
        $this->get('/business/start')->assertOk()->assertViewIs('spa');
        $this->assertNull(session('business.creation_key'));
        $key = $this->getJson('/business/start')->assertOk()->json('data.creationKey');
        $this->assertTrue(Str::isUuid($key));
        $this->assertSame($key, session('business.creation_key'));
        $input = ['name' => 'My business', 'business_type_id' => $this->type(), 'creation_key' => $key];
        $this->post('/business/start', [...$input, 'business_type_id' => 999999])->assertSessionHasErrors('business_type_id');
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('workspaces')->count());
        $this->post('/business/start', $input)->assertRedirect();
        $this->post('/business/start', $input)->assertRedirect();
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(1, $db->table('workspaces')->count());
        $this->assertSame(1, $db->table('business_context_events')->count());
        $workspace = $db->table('workspaces')->first();
        $this->assertSame($this->type(), $workspace->business_type_id);
        $this->get('/dashboard')->assertRedirect(route('workspaces.show', $workspace->public_id));
        $this->getJson('/workspaces/'.$workspace->public_id)->assertJson(fn (Assert $page) => $page
            ->where('data.business.type.key', 'real_estate')->has('data.guidance.sources', 5)->etc());
    }

    public function test_gate_preserves_destination_security_and_existing_identity(): void
    {
        config(['session.driver' => 'redis', 'session.connection' => 'default']);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $workspace = $this->business(User::factory()->create());
        $this->get('/workspaces/'.$workspace->public_id.'?tab=sources')->assertRedirect(route('workspaces.business.edit', $workspace->public_id));
        $this->get('/profile')->assertOk();
        $this->get('/security/devices')->assertOk();
        $this->get('/workspaces/'.$workspace->public_id.'/business')->assertOk()->assertSee('application-bootstrap', false)->assertDontSee('data-page=', false);
        $this->getJson('/workspaces/'.$workspace->public_id.'/business')->assertOk()->assertJsonPath('data.workspace.name', 'Original business')->assertJsonPath('data.canManage', true);
        $this->classify($workspace)->assertRedirect('/workspaces/'.$workspace->public_id.'?tab=sources');
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(1, $db->table('workspaces')->count());
        $this->assertSame('Original business', $db->table('workspaces')->value('name'));
        $this->post('/logout')->assertOk()->assertJsonPath('redirect', '/')
            ->assertJsonPath('transition.status', 'closed');
    }

    public function test_legacy_classification_preserves_source_instances_and_historical_evidence(): void
    {
        $workspace = $this->business(User::factory()->create());
        $db = DB::connection('pgsql_migrations');
        $source = (string) Str::uuid7();
        $db->table('source_registry')->insert(['id' => $source, 'workspace_id' => $workspace->id, 'family' => 'meta',
            'namespace' => 'ad_account', 'declared_external_id' => 'existing-account', 'label' => 'Existing source',
            'version' => 1, 'status' => 'UNVERIFIED', 'created_at' => now()]);
        $before = (array) $db->table('source_registry')->where('id', $source)->first();
        $this->classify($workspace)->assertRedirect();
        $this->classify($workspace, 'ecommerce', 1)->assertRedirect();
        $this->assertSame($before, (array) $db->table('source_registry')->where('id', $source)->first());
        $this->assertSame(1, $db->table('workspace_ownerships')->count());
    }

    public function test_mutation_requires_grant_and_membership_even_for_an_owner(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->business($owner);
        $db = DB::connection('pgsql_migrations');
        $db->table('security_action_grants')->where('action', 'business.context.manage')->delete();
        $this->classify($workspace)->assertForbidden();
        $this->post('/workspaces/'.$workspace->public_id.'/business/authority', [
            'decision' => 'authorize', 'reason' => 'complete_business_context',
        ])->assertRedirect();
        $this->classify($workspace)->assertRedirect();
        $db->table('security_action_grants')->where('action', 'business.context.manage')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->post('/workspaces/'.$workspace->public_id.'/business/authority', [
            'decision' => 'authorize', 'reason' => 'complete_business_context',
        ])->assertForbidden();
        $this->actingAs(User::factory()->create());
        $this->classify($workspace)->assertForbidden();
        $this->get('/workspaces/'.$workspace->public_id.'/business')->assertForbidden();
    }

    public function test_a_nonowner_with_exact_explicit_grant_can_edit_without_owner_role_inheritance(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->business($owner);
        $member = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $db->table('workspace_memberships')->insert(['workspace_id' => $workspace->id, 'user_id' => $member->id,
            'role' => 'member', 'status' => 'active', 'created_at' => now()]);
        foreach (['workspace.view', 'business.context.manage'] as $action) {
            $db->table('security_action_grants')->insert(['subject_user_id' => $member->id, 'granted_by' => $owner->id,
                'subject_generation' => 1, 'source' => 'human', 'scope_kind' => 'workspace', 'workspace_id' => $workspace->id,
                'action' => $action, 'reason' => 'Explicit controlled member authority fixture', 'status' => 'active',
                'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($member);
        $this->getJson('/workspaces/'.$workspace->public_id.'/business')->assertOk()->assertJsonPath('data.canManage', true);
        $this->classify($workspace)->assertRedirect();
        $db->table('security_action_grants')->where('subject_user_id', $member->id)->where('action', 'business.context.manage')
            ->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->getJson('/workspaces/'.$workspace->public_id.'/business')->assertOk()->assertJsonPath('data.canManage', false)->assertJsonPath('data.canRequestAuthority', false);
    }

    public function test_change_is_versioned_and_does_not_rewrite_previous_context(): void
    {
        $workspace = $this->business(User::factory()->create());
        $this->classify($workspace)->assertRedirect();
        $this->classify($workspace, 'ecommerce', 0)->assertSessionHasErrors('business_type_id');
        $this->classify($workspace, 'ecommerce', 1)->assertRedirect();
        $events = DB::connection('pgsql_migrations')->table('business_context_events')->orderBy('version')->get();
        $this->assertCount(2, $events);
        $this->assertSame($this->type(), $events[0]->new_type_id);
        $this->assertSame($this->type(), $events[1]->prior_type_id);
    }

    public function test_inactive_type_is_retained_but_not_newly_selectable_and_fk_prevents_deletion(): void
    {
        $workspace = $this->business(User::factory()->create());
        $this->classify($workspace)->assertRedirect();
        $db = DB::connection('pgsql_migrations');
        $db->table('business_types')->where('id', $this->type())->update(['active' => false]);
        $this->classify($workspace, 'real_estate', 1)->assertRedirect();
        $this->getJson('/workspaces/'.$workspace->public_id)->assertJson(fn (Assert $page) => $page->where('data.business.type.active', false)->etc());
        $this->classify($workspace, 'ecommerce', 1)->assertRedirect();
        $this->classify($workspace, 'real_estate', 2)->assertSessionHasErrors('business_type_id');
        $this->expectException(QueryException::class);
        $db->table('business_types')->where('id', $this->type())->delete();
    }

    public function test_guidance_is_configuration_driven_and_independent_per_business(): void
    {
        $user = User::factory()->create();
        $a = $this->business($user, 'real-estate');
        $this->classify($a)->assertRedirect();
        $b = $this->business($user, 'online-store');
        $this->classify($b, 'ecommerce')->assertRedirect();
        $admin = $this->administrator();
        foreach ([['real_estate', 'google', 'Understand qualified search demand'], ['ecommerce', 'meta', 'Understand paid social store acquisition']] as [$key, $provider, $rationale]) {
            $this->post('/internal/business-types/'.$this->type($key).'/guidance', ['provider' => $provider,
                'priority' => 1, 'rationale_ar' => 'تفسير اختبار مضبوط لغرض اكتساب البيانات', 'rationale_en' => $rationale, 'active' => true])->assertRedirect();
        }
        $this->actingAs($user);
        $this->getJson('/workspaces/'.$a->public_id)->assertJson(fn (Assert $page) => $page->where('data.guidance.sources.0.provider', 'google')->etc());
        $this->getJson('/workspaces/'.$b->public_id)->assertJson(fn (Assert $page) => $page->where('data.guidance.sources.0.provider', 'meta')->etc());
        $this->get('/dashboard')->assertRedirect(route('workspaces.index'));
    }

    public function test_approval_activation_materiality_and_provider_product_integrity(): void
    {
        $this->usePersistentTestSessions();
        $advertiser = User::factory()->create();
        $workspace = $this->business($advertiser);
        $this->classify($workspace)->assertRedirect();
        $this->administrator();
        $url = '/internal/business-types/'.$this->type().'/prior';
        $input = $this->prior();
        $this->withCredentials()->postJson($url, [...$input, 'provider' => 'meta'])->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->post($url, [...$input, 'approved' => false])->assertRedirect();
        $this->actingAs($advertiser)->getJson('/workspaces/'.$workspace->public_id)->assertJson(fn (Assert $page) => $page->has('data.guidance.priors', 0)->etc());
        $this->administrator();
        $row = DB::connection('pgsql_migrations')->table('business_promotion_priors')->first();
        $this->post($url, [...$input, 'id' => $row->id, 'version' => $row->version, 'active' => false])->assertRedirect();
        $this->actingAs($advertiser)->getJson('/workspaces/'.$workspace->public_id)->assertJson(fn (Assert $page) => $page->has('data.guidance.priors', 0)->etc());
        $this->administrator();
        $row = DB::connection('pgsql_migrations')->table('business_promotion_priors')->first();
        $this->post($url, [...$input, 'id' => $row->id, 'version' => $row->version])->assertRedirect();
        $row = DB::connection('pgsql_migrations')->table('business_promotion_priors')->first();
        $this->post($url, [...$input, 'id' => $row->id, 'version' => $row->version, 'rationale_ar' => '  '.$input['rationale_ar'].'  '])->assertRedirect();
        $row = DB::connection('pgsql_migrations')->table('business_promotion_priors')->first();
        $this->assertTrue($row->approved);
        $this->post($url, [...$input, 'id' => $row->id, 'version' => $row->version, 'applicability' => 'A materially different applicability context'])->assertRedirect();
        $row = DB::connection('pgsql_migrations')->table('business_promotion_priors')->first();
        $this->assertFalse($row->approved);
        $this->assertNull($row->approved_by);
    }

    public function test_runtime_cannot_write_configuration_or_read_private_prior_basis(): void
    {
        $this->expectException(QueryException::class);
        DB::table('business_types')->where('id', $this->type())->update(['active' => false]);
    }

    public function test_admin_management_is_not_implied_by_staff_view(): void
    {
        $admin = $this->administrator();
        DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $admin->id)
            ->where('action', 'business.manage')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->get('/internal/business-types')->assertForbidden();
        $this->post('/internal/business-types', ['key' => 'invalid', 'name_ar' => 'نشاط', 'name_en' => 'Activity', 'active' => true])->assertForbidden();
    }

    public function test_callback_restoration_keeps_business_prerequisite_and_cannot_cross_workspace_authority(): void
    {
        $workspace = $this->business(User::factory()->create());
        $request = Request::create('/providers/google/callback');
        $request->setLaravelSession($this->app['session']->driver());
        app(RestoreBusinessAfterCallback::class)->handle($request,
            fn () => redirect()->route('workspaces.show', $workspace->public_id));
        $this->get('/workspaces/'.$workspace->public_id)->assertRedirect(route('workspaces.business.edit', $workspace->public_id));
        $this->get('/workspaces/'.$workspace->public_id)->assertRedirect(route('workspaces.business.edit', $workspace->public_id));
        $this->withSession(['business.callback_resume' => ['workspace' => $workspace->public_id, 'expires_at' => time() + 60]])
            ->actingAs(User::factory()->create())->get('/workspaces/'.$workspace->public_id)->assertForbidden();
    }

    public function test_untrusted_intended_url_is_not_restored(): void
    {
        $workspace = $this->business(User::factory()->create());
        $this->withSession(['business.intended.'.$workspace->public_id => 'https://untrusted.example/workspaces/'.$workspace->public_id]);
        $this->classify($workspace)->assertRedirect(route('workspaces.show', $workspace->public_id));
    }

    public function test_private_basis_is_not_readable_by_runtime_and_stable_key_cannot_be_mutated(): void
    {
        $this->administrator();
        $this->post('/internal/business-types/'.$this->type(), ['key' => 'changed', 'name_ar' => 'عقارات',
            'name_en' => 'Real estate', 'active' => true, 'version' => 1])->assertSessionHasErrors('key');
        $this->expectException(QueryException::class);
        DB::table('business_promotion_priors')->get(['documented_basis', 'internal_rationale']);
    }
}
