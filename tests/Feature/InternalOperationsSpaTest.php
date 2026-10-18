<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ApplicationContractAssertions;
use Tests\TestCase;

class InternalOperationsSpaTest extends TestCase
{
    use ApplicationContractAssertions;

    private function operator(): User
    {
        $user = User::factory()->create();
        $database = DB::connection('pgsql_migrations');
        $database->table('principal_lifecycles')->where('user_id', $user->id)->update(['principal_class' => 'staff']);
        $database->table('internal_personnel')->insert(['user_id' => $user->id, 'kind' => 'founder', 'status' => 'active',
            'attested_by' => $user->id, 'attested_at' => now(), 'factor_independence_attested_by' => $user->id,
            'factor_independence_attested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach (['first', 'second'] as $name) {
            $database->table('passkeys')->insert(['user_id' => $user->id, 'name' => $name, 'credential_id' => $name.'-'.$user->id,
                'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (GrantAuthority::STAFF_ACTIONS as $action) {
            $database->table('security_action_grants')->insert(['subject_user_id' => $user->id, 'source' => 'founder_bootstrap',
                'action' => $action, 'scope_kind' => 'platform', 'subject_generation' => 1, 'reason' => 'isolated operations fixture',
                'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    public function test_administration_shell_and_actual_typed_resource_keep_private_data_out_of_bootstrap(): void
    {
        $actor = $this->operator();
        $this->actingAsInternal($actor)->get('/internal/administration')->assertOk()->assertViewIs('spa')
            ->assertDontSee($actor->email)->assertDontSee('data-page=', false);
        $resource = $this->getJson('/internal/administration')->assertOk()->assertJsonPath('data.permissions', fn ($permissions) => $permissions['staff.invite'] === true)->json();
        $this->assertSame($actor->email, $resource['data']['people'][0]['email']);
        $this->assertSame(2, $resource['data']['people'][0]['passkey_count']);
        $this->assertApplicationContract('InternalAdministrationResource', $resource);
        $before = DB::table('security_sessions')->where('user_id', $actor->id)->sole();
        $this->getJson('/internal/administration', ['X-Builderad-Passive' => '1'])->assertOk();
        $this->getJson('/internal/administration', ['X-Builderad-Witness' => '1'])->assertExactJson(['visibility' => $resource['visibility']]);
        $after = DB::table('security_sessions')->where('id', $before->id)->sole();
        $this->assertSame($before->idle_expires_at, $after->idle_expires_at);
        $this->assertSame($before->last_seen_at, $after->last_seen_at);
    }

    public function test_customer_shell_and_sql_projection_match_the_resource_contract_and_full_filter_witness(): void
    {
        $customer = User::factory()->create(['name' => 'Customer Projection']);
        $actor = $this->operator();
        $this->actingAsInternal($actor)->get('/internal/administration/customers')->assertOk()->assertViewIs('spa')
            ->assertDontSee($customer->email)->assertDontSee('data-page=', false);
        foreach (['?kind=users&search=Customer%20Projection&selected='.$customer->public_id, '?kind=users&search=missing-customer', '?kind=workspaces'] as $query) {
            $resource = $this->getJson('/internal/administration/customers'.$query)->assertOk()->json();
            $this->assertApplicationContract('InternalCustomersResource', $resource);
            $this->assertSame('/internal/administration/customers'.$query, $resource['visibility']['path']);
            $this->getJson('/internal/administration/customers'.$query, ['X-Builderad-Witness' => '1'])
                ->assertExactJson(['visibility' => $resource['visibility']]);
        }
        $this->getJson('/internal/administration/customers?page=0')->assertUnprocessable();
        $this->getJson('/internal/administration/customers?kind=unknown')->assertUnprocessable();
    }

    public function test_business_catalog_actual_sql_projection_and_json_commands_preserve_native_response_and_versions(): void
    {
        $actor = $this->operator();
        $this->actingAsInternal($actor)->get('/internal/business-types')->assertOk()->assertViewIs('spa')->assertDontSee('data-page=', false);
        $input = ['key' => 'operations_catalog', 'name_ar' => 'تصنيف اختباري', 'name_en' => 'Operations catalog', 'active' => true];
        $this->postJson('/internal/business-types', $input)->assertOk()->assertExactJson(['saved' => true]);
        $database = DB::connection('pgsql_migrations');
        $type = $database->table('business_types')->where('key', $input['key'])->sole();
        $this->postJson('/internal/business-types/'.$type->id, [...$input, 'version' => 1, 'name_en' => 'Updated catalog'])->assertOk();
        $this->postJson('/internal/business-types/'.$type->id, [...$input, 'version' => 1])->assertUnprocessable();
        $this->postJson('/internal/business-types/'.$type->id.'/guidance', ['provider' => 'google', 'priority' => 0, 'rationale_ar' => 'توجيه موثق', 'rationale_en' => 'Documented guidance', 'active' => true])->assertOk();
        $prior = ['provider' => 'google', 'product' => 'google.search', 'basis' => 'ADMIN_APPROVED_HYPOTHESIS', 'documented_basis' => 'Documented isolated fixture', 'applicability' => 'Applies only to isolated fixtures', 'limits' => 'No provider or business acceptance', 'rationale_ar' => 'مبرر اختباري موثق', 'rationale_en' => 'Documented public rationale', 'internal_rationale' => 'Private isolated rationale', 'approved' => true, 'active' => true];
        $this->postJson('/internal/business-types/'.$type->id.'/prior', $prior)->assertOk();
        $resource = $this->getJson('/internal/business-types')->assertOk()->json();
        $this->assertApplicationContract('InternalBusinessTypesResource', $resource);
        $projection = collect($resource['data']['types'])->firstWhere('id', $type->id);
        $this->assertSame(2, $projection['version']);
        $this->assertSame(0, $projection['source_guidance'][0]['priority']);
        $this->assertTrue($projection['promotion_priors'][0]['approved']);
        $this->getJson('/internal/business-types', ['X-Builderad-Witness' => '1'])->assertExactJson(['visibility' => $resource['visibility']]);
        $this->post('/internal/business-types', [...$input, 'key' => 'native_catalog'], ['Accept' => 'text/html'])->assertRedirect('/internal/business-types');
        $this->get('/internal/business-types')->assertDontSee('Private isolated rationale');
        $database->table('security_action_grants')->where('subject_user_id', $actor->id)->where('action', 'business.manage')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->postJson('/internal/business-types', [...$input, 'key' => 'denied_catalog'])->assertForbidden();
        $this->assertDatabaseMissing('business_types', ['key' => 'denied_catalog']);
    }

    public function test_service_one_time_json_delivery_requires_an_admitted_document_and_never_enters_read_resources(): void
    {
        $actor = $this->operator();
        $this->actingAsInternal($actor)->get('/internal/services')->assertOk()->assertViewIs('spa')->assertDontSee('data-page=', false);
        $this->postJson('/internal/services', ['name' => 'Rejected document'], ['X-Builderad-Document' => 'invalid'])
            ->assertConflict()->assertJsonPath('identityConflict', true);
        $this->assertDatabaseCount('service_identities', 0);
        $secret = $this->postJson('/internal/services', ['name' => 'One time service'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertApplicationContract('InternalServiceCredential', $secret);
        $database = DB::connection('pgsql_migrations');
        $row = $database->table('service_identities')->where('public_id', $secret['public_id'])->sole();
        $this->assertSame(hash('sha256', $secret['credential']), $row->credential_hash);
        $resource = $this->getJson('/internal/services')->assertOk()->assertDontSee($secret['credential'])->assertJsonMissingPath('data.newCredential')->json();
        $this->assertApplicationContract('InternalServicesResource', $resource);
        $this->get('/internal/services')->assertDontSee($secret['credential']);
        $this->assertNull(session('serviceCredential'));
        $rotated = $this->postJson('/internal/services/'.$secret['public_id'].'/rotate')->assertOk()->json();
        $this->assertApplicationContract('InternalServiceCredential', $rotated);
        $this->assertNotSame($secret['credential'], $rotated['credential']);
        $row = $database->table('service_identities')->where('public_id', $secret['public_id'])->sole();
        $this->assertSame(2, $row->version);
        $this->assertSame(hash('sha256', $rotated['credential']), $row->credential_hash);
        $this->getJson('/internal/services')->assertDontSee($secret['credential'])->assertDontSee($rotated['credential']);
        $this->assertSame(2, $database->table('service_audit_events')->count());
        $database->table('security_action_grants')->where('subject_user_id', $actor->id)->where('action', 'service.manage')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->postJson('/internal/services/'.$secret['public_id'].'/rotate')->assertForbidden();
        $this->assertSame(2, $database->table('service_identities')->where('id', $row->id)->value('version'));
    }

    public function test_invitation_json_and_native_flash_use_the_same_pending_authority_writer(): void
    {
        Notification::fake();
        $actor = $this->operator();
        $this->actingAsInternal($actor)->postJson('/internal/staff', ['name' => 'JSON employee', 'email' => 'json-employee@example.test'])
            ->assertOk()->assertExactJson(['saved' => true]);
        $employee = User::where('email', 'json-employee@example.test')->sole();
        $this->assertDatabaseHas('principal_lifecycles', ['user_id' => $employee->id, 'principal_class' => 'staff', 'status' => 'pending']);
        $this->assertDatabaseHas('internal_personnel', ['user_id' => $employee->id, 'status' => 'pending']);
        $this->assertDatabaseHas('security_audit_events', ['subject_user_id' => $employee->id, 'event' => 'staff_invited']);
        $this->from('/internal/administration')->post('/internal/staff', ['name' => 'Native employee', 'email' => 'native-employee@example.test'], ['Accept' => 'text/html'])
            ->assertRedirect('/internal/administration')->assertSessionHas('status');
        $this->assertDatabaseHas('users', ['email' => 'native-employee@example.test']);
        $this->postJson('/internal/staff', ['name' => 'Duplicate employee', 'email' => 'json-employee@example.test'])->assertUnprocessable();
    }

    public function test_revoked_action_after_loading_and_stale_freshness_are_rechecked_for_json_and_native_commands(): void
    {
        Notification::fake();
        $actor = $this->operator();
        $this->actingAsInternal($actor)->getJson('/internal/administration')->assertOk()->assertJsonPath('data.permissions', fn ($permissions) => $permissions['staff.invite'] === true);
        DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $actor->id)
            ->where('action', 'staff.invite')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $input = ['name' => 'Denied employee', 'email' => 'denied-employee@example.test'];
        $this->postJson('/internal/staff', $input)->assertForbidden();
        $this->post('/internal/staff', $input, ['Accept' => 'text/html'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => $input['email']]);
        DB::connection('pgsql_migrations')->table('security_sessions')->where('user_id', $actor->id)->update(['authenticated_at' => now()->subHour()]);
        $this->postJson('/internal/grants', [])->assertForbidden();
        $this->assertSame(0, DB::table('security_action_grants')->where('source', 'human')->count());
    }
}
