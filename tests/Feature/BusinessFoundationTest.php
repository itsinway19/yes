<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Database\QueryException;
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
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('business-foundation.index-page'));
        $this->get('/organizations/new')->assertOk()->assertViewIs('spa');
        $this->assertNull(session('organization.creation_key'));
        $key = $this->getJson('/organizations/new')->assertOk()->json('data.creationKey');
        $this->assertTrue(Str::isUuid($key));
        $this->assertSame($key, session('organization.creation_key'));
        $input = ['name' => 'My organization', 'creation_key' => $key];
        $this->post('/organizations', [...$input, 'business_type_id' => 999999])->assertSessionHasErrors('business_type_id');
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('workspaces')->count());
        $this->post('/organizations', $input)->assertRedirect();
        $this->post('/organizations', $input)->assertRedirect();
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(1, $db->table('workspaces')->count());
        $this->assertSame(0, $db->table('business_context_events')->count());
        $workspace = $db->table('workspaces')->first();
        $this->assertNull($workspace->business_type_id);
        $this->get('/dashboard')->assertRedirect(route('business-foundation.index-page'));
        $this->getJson('/workspaces/'.$workspace->public_id)->assertJson(fn (Assert $page) => $page
            ->missing('data.business')->missing('data.guidance')->etc());
    }

    public function test_gate_preserves_destination_security_and_existing_identity(): void
    {
        config(['session.driver' => 'redis', 'session.connection' => 'default']);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $workspace = $this->business(User::factory()->create());
        $this->get('/workspaces/'.$workspace->public_id.'?tab=sources')->assertOk();
        $this->get('/profile')->assertOk();
        $this->get('/security/devices')->assertOk();
        $this->get('/workspaces/'.$workspace->public_id.'/commercial-history')->assertOk()->assertSee('application-bootstrap', false)->assertDontSee('data-page=', false);
        $this->getJson('/workspaces/'.$workspace->public_id.'/commercial-history')->assertOk()->assertJsonPath('data.workspace.name', 'Original business')->assertJsonPath('data.read_only', true);
        $this->post('/workspaces/'.$workspace->public_id.'/business', ['business_type_id' => $this->type(), 'version' => 0])->assertStatus(410);
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(1, $db->table('workspaces')->count());
        $this->assertSame('Original business', $db->table('workspaces')->value('name'));
        $this->post('/logout')->assertOk()->assertJsonPath('redirect', '/')
            ->assertJsonPath('transition.status', 'closed');
    }

    public function test_retired_classification_cannot_change_source_instances_or_historical_evidence(): void
    {
        $workspace = $this->business(User::factory()->create());
        $db = DB::connection('pgsql_migrations');
        $source = (string) Str::uuid7();
        $db->table('source_registry')->insert(['id' => $source, 'workspace_id' => $workspace->id, 'family' => 'meta',
            'namespace' => 'ad_account', 'declared_external_id' => 'existing-account', 'label' => 'Existing source',
            'version' => 1, 'status' => 'UNVERIFIED', 'created_at' => now()]);
        $before = (array) $db->table('source_registry')->where('id', $source)->first();
        $this->postJson('/workspaces/'.$workspace->public_id.'/business', ['business_type_id' => $this->type(), 'version' => 0])->assertStatus(410);
        $this->postJson('/workspaces/'.$workspace->public_id.'/business', ['business_type_id' => $this->type('ecommerce'), 'version' => 1])->assertStatus(410);
        $this->assertSame($before, (array) $db->table('source_registry')->where('id', $source)->first());
        $this->assertSame(1, $db->table('workspace_ownerships')->count());
    }

    public function test_legacy_guidance_configuration_does_not_leak_into_organizational_source_inventory(): void
    {
        $user = User::factory()->create();
        $a = $this->business($user, 'real-estate');
        $b = $this->business($user, 'online-store');
        $admin = $this->administrator();
        foreach ([['real_estate', 'google', 'Understand qualified search demand'], ['ecommerce', 'meta', 'Understand paid social store acquisition']] as [$key, $provider, $rationale]) {
            $this->post('/internal/business-types/'.$this->type($key).'/guidance', ['provider' => $provider,
                'priority' => 1, 'rationale_ar' => 'تفسير اختبار مضبوط لغرض اكتساب البيانات', 'rationale_en' => $rationale, 'active' => true])->assertRedirect();
        }
        $this->actingAs($user);
        $this->getJson('/workspaces/'.$a->public_id)->assertJsonMissingPath('data.guidance');
        $this->getJson('/workspaces/'.$b->public_id)->assertJsonMissingPath('data.guidance');
        $this->get('/dashboard')->assertRedirect(route('business-foundation.index-page'));
    }

    public function test_approval_activation_materiality_and_provider_product_integrity(): void
    {
        $this->usePersistentTestSessions();
        $advertiser = User::factory()->create();
        $workspace = $this->business($advertiser);
        $this->postJson('/workspaces/'.$workspace->public_id.'/business', ['business_type_id' => $this->type(), 'version' => 0])->assertStatus(410);
        $this->administrator();
        $url = '/internal/business-types/'.$this->type().'/prior';
        $input = $this->prior();
        $this->withCredentials()->postJson($url, [...$input, 'provider' => 'meta'])->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->post($url, [...$input, 'approved' => false])->assertRedirect();
        $this->actingAs($advertiser)->getJson('/workspaces/'.$workspace->public_id)->assertJsonMissingPath('data.guidance');
        $this->administrator();
        $row = DB::connection('pgsql_migrations')->table('business_promotion_priors')->first();
        $this->post($url, [...$input, 'id' => $row->id, 'version' => $row->version, 'active' => false])->assertRedirect();
        $this->actingAs($advertiser)->getJson('/workspaces/'.$workspace->public_id)->assertJsonMissingPath('data.guidance');
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

    public function test_private_basis_is_not_readable_by_runtime_and_stable_key_cannot_be_mutated(): void
    {
        $this->administrator();
        $this->post('/internal/business-types/'.$this->type(), ['key' => 'changed', 'name_ar' => 'عقارات',
            'name_en' => 'Real estate', 'active' => true, 'version' => 1])->assertSessionHasErrors('key');
        $this->expectException(QueryException::class);
        DB::table('business_promotion_priors')->get(['documented_basis', 'internal_rationale']);
    }
}
