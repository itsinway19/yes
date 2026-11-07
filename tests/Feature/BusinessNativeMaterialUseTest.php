<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\Ads\Provider;
use App\Security\GrantAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\BusinessNativeMaterialFixture;
use Tests\TestCase;

final class BusinessNativeMaterialUseTest extends TestCase
{
    use ApplicationContractAssertions;
    use BusinessNativeMaterialFixture;

    public static function nativeOperations(): array
    {
        $cases = array_map(fn ($provider) => [$provider, 'retained_read'], Provider::cases());
        foreach (['process', 'display', 'reuse', 'combine', 'export'] as $operation) {
            $cases[] = [Provider::Meta, $operation];
        }

        return $cases;
    }

    private function readNative(array $f, ?array $body = null)
    {
        return $this->postJson('/api/businesses/'.$f['business']['business_public_id'].'/materials/read', $body ?? $f['body']);
    }

    #[DataProvider('nativeOperations')]
    public function test_qualified_business_native_original_is_read_with_exact_existing_rights_and_durable_replay(Provider $provider, string $operation): void
    {
        $f = $this->nativeMaterial($provider, $operation);
        $owner = DB::connection('pgsql_migrations');
        $grants = $owner->table('security_action_grants')->count();
        $response = $this->readNative($f)->assertOk()->assertJsonCount(1, 'data.materials')
            ->assertJsonPath('data.materials.0.fields.spend.native_value', '12')->assertJsonPath('data.materials.0.coverage', 'PARTIAL');
        $this->assertApplicationContract('BusinessMaterialReadResult', $response->json());
        $this->assertSame(['spend'], array_keys($response->json('data.materials.0.fields')));
        $expectedRef = $f['ref'];
        $actualRef = $response->json('data.materials.0.ref');
        ksort($expectedRef, SORT_STRING);
        ksort($actualRef, SORT_STRING);
        $this->assertSame($expectedRef, $actualRef);
        $this->assertStringNotContainsString('DO_NOT_DISCLOSE', $response->getContent());
        DB::disconnect();
        $this->assertSame($response->json(), $this->readNative($f)->assertOk()->json());
        $this->assertSame($grants, $owner->table('security_action_grants')->count());
        $this->assertSame(0, $owner->table('workspaces')->count());
        $receipt = $owner->table('business_command_receipts')->where('command_id', $f['body']['command_id'])->sole();
        $this->assertStringNotContainsString('native_value', $receipt->result_ref);
        foreach ([0, 1, 2] as $missing) {
            $body = $f['body'];
            $body['command_id'] = (string) Str::uuid();
            array_splice($body['authority_selection']['requirements'], $missing, 1);
            $this->readNative($f, $body)->assertForbidden();
        }
    }

    public function test_connection_alone_neither_issues_data_use_nor_allows_business_material_consumption(): void
    {
        $f = $this->nativeMaterial(licensed: false);
        $this->readNative($f)->assertForbidden();
        $owner = DB::connection('pgsql_migrations');
        $this->assertSame(0, $owner->table('data_use_scopes')->count());
        $this->assertSame(0, $owner->table('business_data_use_bindings')->count());
        $this->assertSame(0, $owner->table('workspaces')->count());
    }

    public function test_a_second_qualified_business_binding_cannot_retarget_a_business_native_original(): void
    {
        $f = $this->nativeMaterial();
        $this->readNative($f)->assertOk();
        $other = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Other qualified consumer'])->assertCreated()->json('data');
        $owner = DB::connection('pgsql_migrations');
        $id = (int) $owner->table('businesses')->where('public_id', $other['business_public_id'])->value('id');
        $manager = BusinessAuthorityFixture::root($id, $f['user']->id, 'business.link.manage');
        $data = BusinessAuthorityFixture::root($id, $f['user']->id, 'business.data.use');
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => $manager->id],
            ['requirement_kind' => 'client_data', 'leaf_grant_id' => $f['boundary']['grant_id']],
        ]];
        $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid(), 'target_kind' => 'boundary_subject', 'target_ref' => $f['boundary']['boundary_id'],
            'effect' => 'attach_subject', 'payload' => ['business_public_id' => $other['business_public_id'], 'branch_id' => null],
            'expected_revision' => 0, 'authority_selection' => $selection])->assertOk();
        $selection['requirements'][1] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => $f['use']->id];
        $this->postJson('/api/businesses/'.$other['business_public_id'].'/data-use-bindings', ['command_id' => (string) Str::uuid(), 'data_use_scope_id' => $f['useScope'],
            'effect' => 'add', 'expected_relation' => 0, 'authority_selection' => $selection])->assertOk();
        $body = $f['body'];
        $body['command_id'] = (string) Str::uuid();
        $body['authority_selection']['requirements'][0]['leaf_grant_id'] = $data->id;
        $this->postJson('/api/businesses/'.$other['business_public_id'].'/materials/read', $body)->assertForbidden();
        // Discriminate the preparation boundary itself; the later complete
        // footprint also rejects cross-Business expansion independently.
        try {
            $owner->transaction(function () use ($owner, $f, $other, $body) {
                $owner->statement('SET LOCAL ROLE builderad_scope_executor');
                $owner->scalar('SELECT builderad_private.material_use_plan(?,?::uuid,?::jsonb,?,?,?::jsonb)', [
                    $f['user']->id, $other['business_public_id'], json_encode($body['material_refs']),
                    $body['operation'], $body['purpose'], json_encode($body['authority_selection']),
                ]);
            });
            $this->fail('Native-owner selection crossed into another qualified Business.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->getCode());
            $this->assertStringContainsString('Exactly one selected native retained-read path required', $error->getMessage());
        }
        $this->assertSame(2, $owner->table('business_data_use_bindings')->where('data_use_scope_id', $f['useScope'])->where('status', 'active')->count());
        $this->readNative($f)->assertOk();
    }

    public function test_native_source_qualification_expiry_denies_previously_successful_material_replay(): void
    {
        $f = $this->nativeMaterial();
        $this->readNative($f)->assertOk();
        $owner = DB::connection('pgsql_migrations');
        $qualification = $owner->table('security_action_grants')->where('id', $f['native'])->value('qualification_id');
        $until = BusinessAuthorityFixture::expireQualificationSoon($qualification, $f['user']->id);
        $this->assertTrue((bool) $owner->scalar('SELECT clock_timestamp()<?::timestamptz', [$until]));
        $deadline = microtime(true) + 5;
        while ((bool) $owner->scalar('SELECT clock_timestamp()<?::timestamptz', [$until]) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertTrue((bool) $owner->scalar('SELECT clock_timestamp()>=?::timestamptz', [$until]));
        $this->readNative($f)->assertForbidden();
        $this->assertSame(1, $owner->table('business_command_receipts')->where('command_id', $f['body']['command_id'])->count());
    }

    public function test_native_material_does_not_cross_actor_business_or_operation_and_source_surrender_denies_replay(): void
    {
        $f = $this->nativeMaterial();
        $this->readNative($f)->assertOk();
        $body = $f['body'];
        $body['command_id'] = (string) Str::uuid();
        $body['operation'] = 'process';
        $this->readNative($f, $body)->assertForbidden();
        $replacement = BusinessAuthorityFixture::root($f['useScope'], $f['user']->id, 'data_use.retained_read', kind: 'data_use');
        $body = $f['body'];
        $body['authority_selection']['requirements'][1]['leaf_grant_id'] = $replacement->id;
        $this->readNative($f, $body)->assertConflict();
        $different = $this->nativeSigned($f['user'], fn (Request $request) => app(GrantAuthority::class)->bootstrapBusinessSource(
            $request, $f['business']['business_public_id'], (string) Str::uuid7(), (string) Str::uuid(), [
                'family' => 'meta', 'namespace' => 'ad_account', 'external_id' => '124', 'label' => 'Different native source',
                'resource' => '124', 'operation' => 'report.read', 'purpose' => 'advertising_analysis',
                'expected_version' => 0, 'reason' => 'explicit_other_source',
            ], ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => (int) $f['business']['grant_refs']['business.context.read']],
            ]]
        ));
        $body = $f['body'];
        $body['command_id'] = (string) Str::uuid();
        $body['authority_selection']['requirements'][2]['leaf_grant_id'] = (int) $different['grant_refs']['source.read'];
        $this->readNative($f, $body)->assertForbidden();
        $other = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Isolated other Business'])->assertCreated()->json('data');
        $this->postJson('/api/businesses/'.$other['business_public_id'].'/materials/read', $f['body'])->assertForbidden();
        $this->actingAs(User::factory()->create());
        $this->readNative($f)->assertForbidden();
        $this->actingAs($f['user']);
        $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid(), 'target_kind' => 'source', 'target_ref' => $f['source'],
            'effect' => 'revoke', 'payload' => ['grant_id' => (string) $f['native']], 'expected_revision' => 1,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'native_source', 'leaf_grant_id' => (int) $f['custody']['grant_refs']['source.manage']],
            ]]])->assertOk();
        $this->readNative($f)->assertForbidden();
        $this->assertSame('revoked', DB::connection('pgsql_migrations')->table('security_action_grants')->where('id', $f['native'])->value('status'));
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('business_command_receipts')->where('command_id', $f['body']['command_id'])->count());
    }
}
