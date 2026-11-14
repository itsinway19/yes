<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $created = $this->actingAs($user)->postJson('/api/businesses', [
            'command_id' => (string) Str::uuid7(), 'name' => 'Business '.$slug,
        ])->assertCreated()->json('data');

        return DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $created['business_public_id'])->sole();
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
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('business-foundation.index-page', ['entry' => 1]));
        $this->get('/organizations/new')->assertNotFound();
        $command = ['command_id' => (string) Str::uuid(), 'name' => 'My independent business'];
        $first = $this->postJson('/api/businesses', $command)->assertCreated()->json('data');
        $this->postJson('/api/businesses', $command)->assertCreated()->assertJsonPath('data', $first);
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(1, $db->table('businesses')->count());
        $this->assertNull($db->scalar("SELECT to_regclass('public.workspaces')"));
        $this->assertNull($db->scalar("SELECT to_regclass('public.workspace_memberships')"));
        $this->getJson('/api/businesses/'.$first['business_public_id'].'/surface?access='.$first['grant_refs']['business.context.read'])->assertOk();
    }

    public function test_gate_preserves_destination_security_and_existing_identity(): void
    {
        config(['session.driver' => 'redis', 'session.connection' => 'default']);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $business = $this->business(User::factory()->create());
        $this->get('/businesses/'.$business->public_id)->assertUnprocessable();
        $this->getJson('/businesses')->assertOk();
        $this->get('/profile')->assertOk();
        $this->get('/security/devices')->assertOk();
        $this->getJson('/workspaces/'.$business->public_id)->assertNotFound();
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('businesses')->count());
        $this->post('/logout')->assertOk()->assertJsonPath('redirect', '/')
            ->assertJsonPath('transition.status', 'closed');
    }

    public function test_legacy_guidance_configuration_does_not_leak_into_organizational_source_inventory(): void
    {
        $user = User::factory()->create();
        $a = $this->business($user, 'real-estate');
        $b = $this->business($user, 'online-store');
        $this->administrator();
        foreach ([['real_estate', 'google', 'Understand qualified search demand'], ['ecommerce', 'meta', 'Understand paid social store acquisition']] as [$key, $provider, $rationale]) {
            $this->post('/internal/business-types/'.$this->type($key).'/guidance', ['provider' => $provider,
                'priority' => 1, 'rationale_ar' => 'تفسير اختبار مضبوط لغرض اكتساب البيانات', 'rationale_en' => $rationale, 'active' => true])->assertRedirect();
        }
        $this->actingAs($user);
        $this->getJson('/businesses/'.$a->public_id.'/source-registry')->assertOk()->assertJsonMissingPath('data.guidance');
        $this->getJson('/businesses/'.$b->public_id.'/source-registry')->assertOk()->assertJsonMissingPath('data.guidance');
        $this->get('/dashboard')->assertRedirect(route('business-foundation.index-page', ['entry' => 1]));
    }

    public function test_approval_activation_materiality_and_provider_product_integrity(): void
    {
        $this->usePersistentTestSessions();
        $advertiser = User::factory()->create();
        $business = $this->business($advertiser);
        $this->administrator();
        $url = '/internal/business-types/'.$this->type().'/prior';
        $input = $this->prior();
        $this->withCredentials()->postJson($url, [...$input, 'provider' => 'meta'])->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->post($url, [...$input, 'approved' => false])->assertRedirect();
        $this->actingAs($advertiser)->getJson('/businesses/'.$business->public_id.'/source-registry')->assertOk()->assertJsonMissingPath('data.guidance');
        $this->administrator();
        $row = DB::connection('pgsql_migrations')->table('business_promotion_priors')->first();
        $this->post($url, [...$input, 'id' => $row->id, 'version' => $row->version, 'active' => false])->assertRedirect();
        $this->actingAs($advertiser)->getJson('/businesses/'.$business->public_id.'/source-registry')->assertOk()->assertJsonMissingPath('data.guidance');
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
