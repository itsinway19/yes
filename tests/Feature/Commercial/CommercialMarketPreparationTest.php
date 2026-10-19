<?php

namespace Tests\Feature\Commercial;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationContractAssertions;
use Tests\TestCase;

class CommercialMarketPreparationTest extends TestCase
{
    use ApplicationContractAssertions;

    public function test_spa_preparation_projection_binding_and_new_intent_preserve_original_writers(): void
    {
        $founder = $this->staff('founder');
        $administrator = $this->staff();
        $this->actingAsInternal($founder)->withCredentials();
        $path = '/internal/administration/commercial-markets';
        $first = $this->getJson($path)->assertOk()->assertJsonPath('data.canPrepare', true)->json();
        $this->assertApplicationContract('CommercialPreparationResource', $first);
        $intent = $first['data']['intentId'];
        $binding = json_decode(Crypt::decryptString($first['data']['intentBinding']), true, flags: JSON_THROW_ON_ERROR);
        $again = $this->getJson($path)->assertOk()->json();
        $this->assertSame($intent, $again['data']['intentId']);
        $this->assertSame($binding, json_decode(Crypt::decryptString($again['data']['intentBinding']), true, flags: JSON_THROW_ON_ERROR));
        $this->postJson($path.'/new-intent')->assertConflict();
        $input = ['mode' => 'direct', 'intent_id' => $intent, 'intent_binding' => $first['data']['intentBinding'],
            'country_code' => 'JO', 'denomination_code' => 'USD', 'reason' => 'Synthetic contract proof'];
        $result = $this->postJson($path, $input)->assertOk()->json();
        $this->assertApplicationContract('CommercialPreparationAcknowledgement', $result);
        $replay = $this->postJson($path, $input)->assertOk()->json();
        $this->assertSame($result, $replay);
        $present = $this->getJson($path)->assertOk()->assertJsonPath('data.directRecorded', true)->json();
        $this->assertApplicationContract('CommercialPreparationResource', $present);
        $this->postJson($path.'/new-intent')->assertOk()->assertExactJson(['saved' => true]);
        $this->assertNotSame($intent, $this->getJson($path)->assertOk()->json('data.intentId'));
        $native = $this->getJson($path)->assertOk()->json('data');
        $approval = ['intent_id' => $native['intentId'], 'intent_binding' => $native['intentBinding'],
            'administrator' => $administrator->public_id, 'label' => 'Synthetic delegated market',
            'reason' => 'Synthetic delegated contract proof', 'expires_at' => now()->addHour()->toIso8601String()];
        $approved = $this->postJson($path, $approval)->assertOk()->json();
        $this->assertApplicationContract('CommercialPreparationAcknowledgement', $approved);
        $this->assertApplicationContract('CommercialPreparationResource', $this->getJson($path)->assertOk()->json());
        $this->post($path.'/new-intent')->assertRedirect(route('internal.commercial-market-preparation.index'));
        $this->actingAsInternal($administrator)->withCredentials();
        $this->postJson($path, $input)->assertForbidden();
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('commercial_markets')->count());
        $this->assertSame(2, DB::connection('pgsql_migrations')->table('commercial_command_results')->count());
    }

    public function test_spa_preparation_empty_projection_and_witness_use_existing_visibility_contract(): void
    {
        $this->actingAsInternal($this->staff('founder'))->withCredentials();
        $path = '/internal/administration/commercial-markets';
        $resource = $this->getJson($path)->assertOk()->assertJsonPath('data.approvals', [])
            ->assertJsonPath('data.recordedMarkets', [])->assertJsonPath('data.directRecorded', false)->json();
        $this->assertApplicationContract('CommercialPreparationResource', $resource);
        $witness = $this->withHeader('X-Builderad-Witness', '1')->getJson($path)->assertOk()->json();
        $this->assertSame($resource['visibility'], $witness['visibility']);
        $this->assertArrayNotHasKey('data', $witness);
        $this->assertArrayNotHasKey('intentBinding', $witness);
    }

    public function test_country_command_replays_after_new_login_with_same_generated_identities(): void
    {
        config(['session.driver' => 'redis', 'session.connection' => 'default']);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $founder = $this->staff('founder');
        $this->actingAsInternal($founder)->withCredentials();
        $page = $this->getJson('/internal/administration/commercial-markets')->assertOk()->json('data');
        $input = ['mode' => 'direct', 'intent_id' => $page['intentId'], 'intent_binding' => $page['intentBinding'],
            'country_code' => 'JO', 'denomination_code' => 'USD'];
        $original = $this->postJson('/internal/administration/commercial-markets', $input)->assertOk()->json();
        $this->post('/internal/logout')->assertOk()->assertJsonPath('redirect', '/internal/login')
            ->assertJsonPath('transition.status', 'closed');
        $this->actingAsInternal($founder)->withCredentials();
        $newPage = $this->getJson('/internal/administration/commercial-markets')->assertOk()->json('data');
        $this->assertNotSame($page['intentId'], $newPage['intentId']);
        $replayed = $this->postJson('/internal/administration/commercial-markets', $input)->assertOk()->json();
        $this->assertSame($original['market_id'], $replayed['market_id']);
        $this->assertSame($original['configuration_id'], $replayed['configuration_id']);
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('commercial_markets')->count());
        $this->postJson('/internal/administration/commercial-markets', [...$input, 'denomination_code' => 'JOD'])->assertConflict();
    }

    public function test_uncommitted_market_identity_can_resume_only_for_same_current_founder_and_authentic_binding(): void
    {
        config(['session.driver' => 'redis', 'session.connection' => 'default']);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $founder = $this->staff('founder');
        $this->actingAsInternal($founder)->withCredentials();
        $page = $this->getJson('/internal/administration/commercial-markets')->assertOk()->json('data');
        $input = ['mode' => 'direct', 'intent_id' => $page['intentId'], 'intent_binding' => $page['intentBinding'],
            'country_code' => 'SA', 'denomination_code' => 'SAR'];
        $binding = json_decode(Crypt::decryptString($page['intentBinding']), true);
        $this->postJson('/internal/administration/commercial-markets', [...$input, 'intent_binding' => 'forged'])->assertForbidden();
        $this->actingAsInternal($this->staff('founder'))->withCredentials();
        $this->postJson('/internal/administration/commercial-markets', $input)->assertForbidden();
        $this->actingAsInternal($founder)->withCredentials();
        $this->post('/internal/logout')->assertOk()->assertJsonPath('transition.status', 'closed');
        $this->actingAsInternal($founder)->withCredentials();
        $this->postJson('/internal/administration/commercial-markets', $input)->assertOk()
            ->assertJsonPath('market_id', $binding['target_id'])->assertJsonPath('configuration_id', $binding['configuration_id']);
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('commercial_markets')->count());
    }

    public function test_founder_creates_and_manages_market_without_any_delegation(): void
    {
        $founder = $this->staff('founder');
        $this->actingAsInternal($founder)->withCredentials();
        $page = $this->getJson('/internal/administration/commercial-markets')->assertOk();
        $input = ['intent_id' => $page->json('data')['intentId'],
            'mode' => 'direct', 'label' => 'السوق السعودي', 'reason' => 'إنشاء السوق بواسطة المؤسس'];
        $first = $this->postJson('/internal/administration/commercial-markets', $input)->assertOk();
        $market = $first->json('market_id');
        $this->assertNotNull($market);
        $this->postJson('/internal/administration/commercial-markets', $input)->assertOk()->assertJsonPath('market_id', $market);
        $this->postJson('/internal/administration/commercial-markets', [...$input, 'label' => 'Changed'])->assertConflict();
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(0, $db->table('security_action_grants')->where('scope_kind', 'commercial')->count());
        $this->assertSame(0, $db->table('security_commercial_market_approvals')->count());
        $this->assertSame($input['reason'], $db->table('commercial_founder_market_notes')->where('command_id', $input['intent_id'])->value('reason'));
        $reference = (string) Str::uuid7();
        $this->postJson('/internal/commercial/catalog-references', ['command_id' => (string) Str::uuid7(),
            'market_id' => $market, 'catalog_reference_id' => $reference, 'catalog_identity' => (string) Str::uuid7(),
            'catalog_version' => '1', 'approval_reference' => 'owner:reference', 'source_sha256' => hash('sha256', 'approved'), 'reason_code' => 'founder_reference'])->assertOk();
        $configuration = (string) Str::uuid7();
        $this->postJson('/internal/commercial/configurations', ['command_id' => (string) Str::uuid7(), 'market_id' => $market,
            'configuration_id' => $configuration, 'catalog_reference_id' => $reference, 'denomination_code' => 'SAR', 'reason_code' => 'founder_configuration'])->assertOk();
        $this->postJson('/internal/commercial/assignability', ['command_id' => (string) Str::uuid7(), 'market_id' => $market,
            'configuration_id' => $configuration, 'expected_revision' => 1, 'reason_code' => 'founder_enable'])->assertOk();
        $this->getJson('/internal/commercial')->assertOk()->assertJsonPath('data.founderAccess', true)->assertJsonCount(1, 'data.markets');
        $this->assertSame(4, $db->table('commercial_command_results')->count());
        $beneficiary = User::factory()->create();
        $workspace = $db->table('workspaces')->insertGetId(['business_type_id' => $db->table('business_types')->where('key', 'professional_services')->value('id'),
            'name' => 'Founder beneficiary', 'slug' => 'founder-'.Str::uuid7(), 'status' => 'active', 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('workspace_memberships')->insert(['workspace_id' => $workspace, 'user_id' => $beneficiary->id, 'role' => 'owner', 'status' => 'active', 'created_at' => now()]);
        $db->table('workspace_ownerships')->insert(['workspace_id' => $workspace, 'responsible_owner_user_id' => $beneficiary->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $page = $this->getJson('/internal/commercial')->assertOk();
        $scope = (array) collect($page->json('data')['targets'])->firstWhere('action', 'RegisterCommercialScope');
        $registration = ['command_id' => (string) Str::uuid7(), 'scope_id' => $scope['target_id'], 'workspace_public_id' => $scope['workspace_public_id'],
            'market_id' => $market, 'configuration_id' => $configuration, 'catalog_reference_id' => $reference, 'denomination_code' => 'SAR', 'reason_code' => 'founder_registration'];
        $this->postJson('/internal/commercial/registrations', $registration)->assertOk();
        $this->getJson('/internal/commercial/receipts/'.$registration['command_id'])->assertOk();
        $this->getJson('/internal/commercial/contexts/'.$scope['target_id'])->assertOk();
        $this->assertSame(0, $db->table('security_action_grants')->where('scope_kind', 'commercial')->count());
        $this->post('/internal/administration/commercial-markets/new-intent')->assertRedirect();
        $newPage = $this->getJson('/internal/administration/commercial-markets')->assertOk();
        $this->assertNotSame($input['intent_id'], $newPage->json('data')['intentId']);
        $minimal = ['mode' => 'direct', 'intent_id' => $newPage->json('data')['intentId'], 'label' => 'السوق الكويتي'];
        $second = $this->postJson('/internal/administration/commercial-markets', $minimal)->assertOk()->json('market_id');
        $this->assertNotSame($market, $second);
        $this->postJson('/internal/administration/commercial-markets', $minimal)->assertOk()->assertJsonPath('market_id', $second);
        $this->postJson('/internal/administration/commercial-markets', [...$minimal, 'reason' => 'Changed note'])->assertConflict();
        $this->postJson('/internal/administration/commercial-markets', [...$minimal, 'target_id' => (string) Str::uuid7()])->assertUnprocessable();
        $this->assertSame('Founder direct market creation', $db->table('commercial_founder_market_notes')->where('command_id', $minimal['intent_id'])->value('reason'));
        $employee = $this->staff();
        $this->actingAsInternal($employee);
        $this->postJson('/internal/administration/commercial-markets', $input)->assertForbidden();
        $this->postJson('/internal/commercial/configurations', ['command_id' => (string) Str::uuid7(), 'market_id' => $market,
            'configuration_id' => (string) Str::uuid7(), 'reason_code' => 'employee_denied'])->assertForbidden();
        $this->actingAsInternal($founder);
        $db->table('internal_personnel')->where('user_id', $founder->id)->update(['status' => 'revoked']);
        $this->postJson('/internal/commercial/configurations', ['command_id' => (string) Str::uuid7(), 'market_id' => $market,
            'configuration_id' => (string) Str::uuid7(), 'reason_code' => 'revoked_denied'])->assertForbidden();
    }

    public function test_founder_country_market_records_selected_currency_without_fabricating_catalog_and_replay_is_exact(): void
    {
        $founder = $this->staff('founder');
        $this->actingAsInternal($founder)->withCredentials();
        $page = $this->getJson('/internal/administration/commercial-markets')->assertOk();
        $input = ['mode' => 'direct', 'intent_id' => $page->json('data')['intentId'], 'country_code' => 'JO', 'denomination_code' => 'USD'];
        $first = $this->postJson('/internal/administration/commercial-markets', $input)->assertOk();
        $market = $first->json('market_id');
        $configuration = $first->json('configuration_id');
        $db = DB::connection('pgsql_migrations');
        $this->assertSame('JO', $db->table('commercial_market_countries')->where('market_id', $market)->value('country_code'));
        $version = $db->table('commercial_market_configuration_versions')->where('id', $configuration)->first();
        $this->assertSame('USD', $version->denomination_code);
        $this->assertNull($version->catalog_reference_id);
        $this->assertNull($db->table('commercial_market_assignability')->where('market_id', $market)->value('configuration_id'));
        $this->assertSame(0, $db->table('commercial_catalog_references')->count());
        $this->postJson('/internal/administration/commercial-markets', $input)->assertOk()->assertJsonPath('configuration_id', $configuration);
        $this->assertSame(1, $db->table('commercial_market_configuration_versions')->where('market_id', $market)->count());
        $this->postJson('/internal/administration/commercial-markets', [...$input, 'country_code' => 'SA'])->assertConflict();
        $this->postJson('/internal/administration/commercial-markets', [...$input, 'denomination_code' => 'JOD'])->assertConflict();
        $this->postJson('/internal/administration/commercial-markets', [...$input, 'country_code' => 'XX'])->assertUnprocessable();
        $this->postJson('/internal/administration/commercial-markets', [...$input, 'denomination_code' => 'XXX'])->assertUnprocessable();
        $this->postJson('/internal/administration/commercial-markets', ['mode' => 'direct', 'intent_id' => $input['intent_id'], 'country_code' => 'JO'])->assertUnprocessable();
        $this->postJson('/internal/commercial/assignability', ['command_id' => (string) Str::uuid7(), 'market_id' => $market,
            'configuration_id' => $configuration, 'expected_revision' => 1, 'reason_code' => 'incomplete_denied'])->assertUnprocessable();
        $this->getJson('/internal/commercial')->assertOk()->assertJsonPath('data.markets.0.country_code', 'JO')->assertJsonPath('data.configurations.0.denomination_code', 'USD');
        $this->actingAsInternal($this->staff());
        $this->postJson('/internal/administration/commercial-markets', $input)->assertForbidden();
    }

    public function test_country_creation_rolls_back_identity_country_receipts_and_notes_if_initial_configuration_fails(): void
    {
        $this->actingAsInternal($this->staff('founder'))->withCredentials();
        $page = $this->getJson('/internal/administration/commercial-markets')->assertOk();
        $input = ['mode' => 'direct', 'intent_id' => $page->json('data')['intentId'], 'country_code' => 'SA', 'denomination_code' => 'USD'];
        $db = DB::connection('pgsql_migrations');
        $db->unprepared("CREATE FUNCTION country_configuration_test_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'synthetic configuration failure' USING ERRCODE='23514'; END \$\$; CREATE TRIGGER country_configuration_test_failure BEFORE INSERT ON commercial_market_configuration_versions FOR EACH ROW EXECUTE FUNCTION country_configuration_test_failure()");
        try {
            $this->postJson('/internal/administration/commercial-markets', $input)->assertUnprocessable();
            foreach (['commercial_markets', 'commercial_market_countries', 'commercial_market_configuration_versions', 'commercial_command_results', 'commercial_founder_market_notes', 'commercial_audit_obligations'] as $table) {
                $this->assertSame(0, $db->table($table)->count(), $table);
            }
        } finally {
            $db->unprepared('DROP TRIGGER country_configuration_test_failure ON commercial_market_configuration_versions; DROP FUNCTION country_configuration_test_failure()');
        }
        $this->postJson('/internal/administration/commercial-markets', $input)->assertOk();
        $this->assertSame(1, $db->table('commercial_market_countries')->count());
    }

    private function staff(string $kind = 'employee'): User
    {
        $user = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $db->table('principal_lifecycles')->where('user_id', $user->id)->update(['principal_class' => 'staff']);
        $db->table('internal_personnel')->insert(['user_id' => $user->id, 'kind' => $kind, 'status' => 'active', 'attested_by' => $user->id, 'attested_at' => now(), 'factor_independence_attested_at' => $kind === 'founder' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        $db->table('passkeys')->insert(['user_id' => $user->id, 'name' => 'Synthetic factor', 'credential_id' => 'synthetic-'.$user->id, 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        if ($kind === 'founder') {
            $db->table('passkeys')->insert(['user_id' => $user->id, 'name' => 'Second synthetic factor', 'credential_id' => 'synthetic-second-'.$user->id, 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    public function test_founder_delegates_existing_market_separately_and_employee_scope_stays_exact(): void
    {
        $founder = $this->staff('founder');
        $employee = $this->staff();
        $outsider = $this->staff();
        $this->actingAsInternal($founder)->withCredentials();
        $page = $this->getJson('/internal/administration/commercial-markets')->assertOk();
        $market = $this->postJson('/internal/administration/commercial-markets', ['mode' => 'direct',
            'intent_id' => $page->json('data')['intentId'], 'label' => 'Delegated market', 'reason' => 'Founder creation'])->assertOk()->json('market_id');
        $payload = ['market_id' => $market, 'operator' => $employee->public_id, 'permission' => 'manage', 'reason' => 'Optional delegation', 'expires_at' => now()->addDay()->toIso8601String()];
        $this->postJson('/internal/administration/commercial-markets/delegate', [...$payload, 'operator' => $founder->public_id])->assertForbidden();
        $this->postJson('/internal/administration/commercial-markets/delegate', $payload)->assertOk();
        $this->postJson('/internal/administration/commercial-markets/delegate', $payload)->assertOk();
        $this->postJson('/internal/administration/commercial-markets/delegate', [...$payload, 'expires_at' => now()->addHour()->toIso8601String()])->assertConflict();
        $this->postJson('/internal/administration/commercial-markets/delegate', [...$payload, 'reason' => 'Changed terms'])->assertConflict();
        $this->assertSame(4, DB::connection('pgsql_migrations')->table('security_action_grants')->where('scope_kind', 'commercial')->count());
        $this->actingAsInternal($employee);
        $command = ['command_id' => (string) Str::uuid7(), 'market_id' => $market, 'configuration_id' => (string) Str::uuid7(), 'reason_code' => 'employee_configuration'];
        $this->postJson('/internal/commercial/configurations', $command)->assertOk();
        $this->postJson('/internal/commercial/configurations', [...$command, 'command_id' => (string) Str::uuid7(), 'market_id' => (string) Str::uuid7()])->assertForbidden();
        $this->postJson('/internal/administration/commercial-markets/delegate', [...$payload, 'operator' => $outsider->public_id])->assertForbidden();
        $this->actingAsInternal($founder);
        $this->postJson('/internal/administration/commercial-markets/delegate', [...$payload, 'permission' => 'all'])->assertUnprocessable();
        $this->postJson('/internal/administration/commercial-markets/delegate', [...$payload, 'expires_at' => now()->subMinute()->toIso8601String()])->assertUnprocessable();
        $this->postJson('/internal/commercial/configurations', [...$command, 'command_id' => (string) Str::uuid7(), 'configuration_id' => (string) Str::uuid7()])->assertOk();
    }

    private function input(User $admin): array
    {
        $intent = (string) Str::uuid7();
        $page = $this->getJson('/internal/administration/commercial-markets');
        if ($page->status() === 200) {
            $intent = $page->json('data')['intentId'];
        } else {
            $this->withSession(['commercial_market_preparation_intent' => $intent]);
        }

        return ['intent_id' => $intent, 'administrator' => $admin->public_id, 'label' => 'Owner approved market', 'reason' => 'owner:market:approval', 'expires_at' => now()->addDay()->toIso8601String()];
    }

    public function test_founder_approval_replay_and_separate_operator_recording(): void
    {
        $founder = $this->staff('founder');
        $admin = $this->staff();
        $operator = $this->staff();
        $this->actingAsInternal($founder)->withCredentials();
        $page = $this->getJson('/internal/administration/commercial-markets')->assertOk()->assertJsonPath('data.canPrepare', true)->assertJsonStructure(['data' => ['intentId']]);
        $input = ['intent_id' => $page->json('data')['intentId'], 'administrator' => $admin->public_id,
            'label' => 'Owner approved market', 'reason' => 'owner:market:approval', 'expires_at' => now()->addDay()->toIso8601String()];
        $response = $this->postJson('/internal/administration/commercial-markets', $input)->assertOk();
        $target = $response->json('target_id');
        $this->assertSame('7', substr($target, 14, 1));
        $this->postJson('/internal/administration/commercial-markets', $input)->assertOk()->assertJsonPath('target_id', $target);
        $this->postJson('/internal/administration/commercial-markets', [...$input, 'label' => 'Changed'])->assertConflict();
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(1, $db->table('security_commercial_market_approvals')->count());
        $this->assertSame(0, $db->table('security_action_grants')->where('subject_user_id', $founder->id)->where('scope_kind', 'commercial')->count());
        $this->actingAsInternal($admin);
        $grant = ['operator' => $operator->public_id, 'reason' => 'approved:operator'];
        $this->postJson('/internal/administration/commercial-markets/'.$input['intent_id'].'/operator', $grant)->assertOk();
        $this->postJson('/internal/administration/commercial-markets/'.$input['intent_id'].'/operator', $grant)->assertOk();
        $this->assertSame(2, $db->table('security_action_grants')->where('subject_user_id', $operator->id)->where('commercial_target_id', $target)->count());
        $this->actingAsInternal($operator);
        $this->getJson('/internal/administration/commercial-markets')->assertForbidden();
        $command = ['command_id' => (string) Str::uuid7(), 'reason_code' => 'approved_market', 'market_id' => $target, 'label' => $input['label']];
        $this->postJson('/internal/commercial/markets', $command)->assertOk();
        $this->postJson('/internal/commercial/markets', $command)->assertOk();
        $this->postJson('/internal/commercial/markets', [...$command, 'command_id' => (string) Str::uuid7()])->assertConflict();
        $this->getJson('/internal/commercial')->assertOk()->assertJsonPath('data.markets.0.id', $target)->assertJsonCount(0, 'data.newMarketTargets');
        $this->assertSame(1, $db->table('security_commercial_market_approvals')->count());
        $this->assertSame(3, $db->table('security_action_grants')->where('commercial_target_id', $target)->count());
    }

    public function test_unqualified_preparation_and_self_grants_are_rejected_atomically(): void
    {
        $employee = $this->staff();
        $founder = $this->staff('founder');
        $admin = $this->staff();
        $this->actingAsInternal($employee)->withCredentials();
        $this->postJson('/internal/administration/commercial-markets', $this->input($admin))->assertForbidden();
        $this->actingAsInternal($founder);
        $this->postJson('/internal/administration/commercial-markets', $this->input($founder))->assertForbidden();
        $input = $this->input($admin);
        $this->postJson('/internal/administration/commercial-markets', [...$input, 'intent_id' => (string) Str::uuid7()])->assertForbidden();
        $this->postJson('/internal/administration/commercial-markets', [...$input, 'target_id' => (string) Str::uuid7()])->assertUnprocessable();
        DB::connection('pgsql_migrations')->table('internal_personnel')->where('user_id', $admin->id)->update(['status' => 'revoked']);
        $this->postJson('/internal/administration/commercial-markets', $input)->assertForbidden();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('security_commercial_market_approvals')->count());
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('security_action_grants')->where('scope_kind', 'commercial')->count());
    }

    public function test_partial_approval_or_operator_grants_roll_back_and_retry_safely(): void
    {
        $founder = $this->staff('founder');
        $admin = $this->staff();
        $operator = $this->staff();
        $this->actingAsInternal($founder)->withCredentials();
        $input = $this->input($admin);
        $db = DB::connection('pgsql_migrations');
        $db->unprepared("CREATE FUNCTION preparation_test_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'synthetic failure' USING ERRCODE='23514'; END \$\$; CREATE TRIGGER preparation_test_failure BEFORE INSERT ON security_commercial_market_approvals FOR EACH ROW EXECUTE FUNCTION preparation_test_failure()");
        try {
            $this->postJson('/internal/administration/commercial-markets', $input)->assertStatus(500);
        } finally {
            $db->unprepared('DROP TRIGGER preparation_test_failure ON security_commercial_market_approvals; DROP FUNCTION preparation_test_failure()');
        }
        $this->assertSame(0, $db->table('security_commercial_market_approvals')->count());
        $this->assertSame(0, $db->table('security_action_grants')->where('scope_kind', 'commercial')->count());
        $target = $this->postJson('/internal/administration/commercial-markets', $input)->assertOk()->json('target_id');
        $this->actingAsInternal($admin);
        $payload = ['operator' => $operator->public_id, 'reason' => 'approved:operator'];
        $path = '/internal/administration/commercial-markets/'.$input['intent_id'].'/operator';
        $this->postJson($path, ['operator' => $admin->public_id, 'reason' => 'self'])->assertForbidden();
        $this->postJson($path, ['operator' => $founder->public_id, 'reason' => 'founder'])->assertForbidden();
        $db->unprepared("CREATE FUNCTION preparation_test_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.action='ReadCommercialMarketRegistry' THEN RAISE EXCEPTION 'synthetic second grant failure' USING ERRCODE='23514'; END IF; RETURN NEW; END \$\$; CREATE TRIGGER preparation_test_failure BEFORE INSERT ON security_action_grants FOR EACH ROW EXECUTE FUNCTION preparation_test_failure()");
        try {
            $this->postJson($path, $payload)->assertStatus(500);
        } finally {
            $db->unprepared('DROP TRIGGER preparation_test_failure ON security_action_grants; DROP FUNCTION preparation_test_failure()');
        }
        $this->assertSame(0, $db->table('security_action_grants')->where('subject_user_id', $operator->id)->where('commercial_target_id', $target)->count());
        $this->postJson($path, $payload)->assertOk();
        $db->table('security_action_grants')->where('action', 'SA01CommercialGrantAdministrator')->where('commercial_target_id', $target)->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->postJson($path, $payload)->assertForbidden();
        $this->actingAsInternal($founder);
        $this->postJson('/internal/administration/commercial-markets', $input)->assertForbidden();
        $this->getJson('/internal/administration/commercial-markets')->assertOk()->assertJsonCount(1, 'data.approvals')->assertJsonPath('data.approvals.0.active', false);
        $this->assertSame(1, $db->table('security_commercial_market_approvals')->count());
    }

    public function test_two_independent_sessions_cannot_consume_the_same_target_twice(): void
    {
        $founder = $this->staff('founder');
        $admin = $this->staff();
        $operator = $this->staff();
        $otherOperator = $this->staff();
        $this->actingAsInternal($founder)->withCredentials();
        $input = $this->input($admin);
        $target = $this->postJson('/internal/administration/commercial-markets', $input)->assertOk()->json('target_id');
        $this->actingAsInternal($admin);
        $this->postJson('/internal/administration/commercial-markets/'.$input['intent_id'].'/operator', ['operator' => $operator->public_id, 'reason' => 'concurrent:proof'])->assertOk();
        $this->postJson('/internal/administration/commercial-markets/'.$input['intent_id'].'/operator', ['operator' => $otherOperator->public_id, 'reason' => 'concurrent:proof'])->assertOk();
        $release = tempnam(sys_get_temp_dir(), 'commercial-consumption-');
        unlink($release);
        $worker = base_path('tests/CommercialMarketRecordingWorker.php');
        $first = new Process([PHP_BINARY, $worker, (string) $operator->id, $target, (string) Str::uuid7(), 'hold', $release]);
        $second = new Process([PHP_BINARY, $worker, (string) $otherOperator->id, $target, (string) Str::uuid7(), 'race', $release]);
        foreach ([$first, $second] as $process) {
            $process->setTimeout(20);
        }
        $waitForOutput = function (Process $process, string $marker): void {
            // On Windows, Process status polling may already have buffered the marker.
            while (! str_contains($process->getOutput(), $marker) && $process->isRunning()) {
                $process->checkTimeout();
                usleep(1000);
            }
            $this->assertStringContainsString($marker, $process->getOutput(), $process->getErrorOutput());
        };
        try {
            $first->start();
            $second->start();
            $waitForOutput($first, 'PID=');
            $waitForOutput($second, 'PID=');
            file_put_contents($release.'-hold-start', 'start');
            $waitForOutput($first, 'INSERT_READY');
            $this->assertTrue($first->isRunning(), $first->getOutput().' / '.$first->getErrorOutput());
            file_put_contents($release.'-race-start', 'start');
            preg_match('/PID=(\d+)/', $second->getOutput(), $match);
            $this->assertNotEmpty($match);
            $deadline = microtime(true) + 2;
            $waiting = false;
            do {
                $waiting = (bool) DB::connection('pgsql_migrations')->scalar('SELECT EXISTS(SELECT 1 FROM pg_locks WHERE pid = ? AND NOT granted)', [(int) $match[1]]);
                if (! $waiting) {
                    usleep(20000);
                }
            } while (! $waiting && microtime(true) < $deadline);
            $this->assertTrue($waiting, 'Actual competing database waiter must be observed. First: '.$first->getOutput().' / '.$first->getErrorOutput().' Second: '.$second->getOutput().' / '.$second->getErrorOutput());
            file_put_contents($release, 'release');
            $first->wait();
            $second->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            $this->assertStringContainsString('STATUS=200', $first->getOutput());
            $this->assertStringContainsString('STATUS=409', $second->getOutput());
            $db = DB::connection('pgsql_migrations');
            $this->assertSame(1, $db->table('commercial_markets')->where('id', $target)->count());
            $this->assertSame(1, $db->table('commercial_command_results')->where('target_id', $target)->count());
            $this->assertSame(1, $db->table('security_commercial_market_approvals')->count());
        } finally {
            file_put_contents($release, 'release');
            foreach ([$first, $second] as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            } foreach ([$release, $release.'-hold-start', $release.'-race-start'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
