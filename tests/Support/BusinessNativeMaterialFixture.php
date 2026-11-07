<?php

namespace Tests\Support;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Providers\Ads\AdObservation;
use App\Providers\Ads\NativeAmount;
use App\Providers\Ads\ObservationBatch;
use App\Providers\Ads\ObservationJournal;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Security\GrantAuthority;
use App\Sources\EffectiveCapability;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Real Source/observation writers; licence roots are explicitly synthetic, not an operating issuer. */
trait BusinessNativeMaterialFixture
{
    private function nativeSigned(User $user, callable $action): mixed
    {
        return DB::transaction(function () use ($user, $action) {
            app(PrincipalContext::class)->activate($user);
            $request = Request::create('/api/businesses');
            $request->setUserResolver(fn () => $user);
            $request->setLaravelSession(app('session')->driver());

            return $action($request);
        });
    }

    private function nativeMaterial(Provider $provider = Provider::Meta, string $operation = 'retained_read', bool $licensed = true, ?NativeAmount $amount = null, int $records = 1, int $datasets = 1): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $business = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Synthetic native material'])->assertCreated()->json('data');
        $owner = DB::connection('pgsql_migrations');
        $id = (int) $owner->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
        $source = (string) Str::uuid7();
        $custody = $this->nativeSigned($user, fn (Request $request) => app(GrantAuthority::class)->bootstrapBusinessSource(
            $request, $business['business_public_id'], $source, (string) Str::uuid(), [
                'family' => $provider->value, 'namespace' => 'ad_account', 'external_id' => '123', 'label' => 'Synthetic source',
                'resource' => '123', 'operation' => 'report.read', 'purpose' => 'advertising_analysis',
                'expected_version' => 0, 'reason' => 'explicit_synthetic_selection',
            ], ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => (int) $business['grant_refs']['business.context.read']],
            ]]
        ));
        $scope = ProviderScope::forBusiness($id, $source, $provider, '123', 'report.read', 'advertising_analysis');
        $verification = array_fill_keys(EffectiveCapability::AXES, ['state' => 'VERIFIED', 'provenance' => 'synthetic:material-verifier',
            'verified_at' => '2026-01-01T00:00:00Z', 'valid_until' => '2099-01-01T00:00:00Z']);
        $this->nativeSigned($user, fn (Request $request) => app(ProviderAccessStore::class)->store($request, $scope, (string) Str::uuid(), 0,
            new ProviderCredentials(['access_token' => 'DO_NOT_DISCLOSE_MATERIAL_TOKEN']), null, 'SYNTHETIC_AUTHORIZED', $verification));
        $provenance = ['api_version' => 'synthetic-v1', 'report' => 'campaign-period', 'documentation_basis' => 'synthetic:material-contract'];
        $observation = new AdObservation($scope, 'campaign-1',
            new ReportWindow('2026-10-01', '2026-10-31', 'INCLUSIVE_DATES', 'TOTAL', 'UTC', 'synthetic:account-civil'),
            $amount ?? new NativeAmount('12', 'SAR', 'synthetic:expenditure', 0, 'synthetic:documented-scale'),
            ['private_lead' => ['value' => 'DO_NOT_DISCLOSE_PRIVATE_LEAD', 'unit' => 'synthetic',
                'meaning' => 'Synthetic provider assertion, not a verified lead', 'basis' => 'PROVIDER_REPORTED']],
            $provenance, new DateTimeImmutable('-1 second'), null);
        $observations = [$observation];
        for ($record = 2; $record <= $records; $record++) {
            $observations[] = new AdObservation($scope, 'campaign-'.$record, $observation->window, $observation->spend,
                $observation->nativeFacts, $provenance, $observation->readAt, null);
        }
        for ($dataset = 0; $dataset < $datasets; $dataset++) {
            $this->nativeSigned($user, fn (Request $request) => app(ObservationJournal::class)->accept($request,
                new ObservationBatch($scope, $observations, $records > 1 || $datasets > 1 ? 'COMPLETE' : 'PARTIAL', null, null),
                $dataset === 0 ? 'synthetic-material' : 'synthetic-material-'.$dataset, (string) Str::uuid(), 0, 1, $provenance));
        }
        $entry = $owner->table('provider_observation_entries')->orderBy('namespace')->orderBy('identity')->first();
        $key = $owner->scalar('SELECT jsonb_build_object(\'namespace\',?::text,\'generation\',?::bigint,\'identity\',?::text)::text', [$entry->namespace, $entry->generation, $entry->identity]);
        $ref = ['namespace' => 'provider_observation_entry', 'key' => $key, 'version' => $entry->content_hash];
        $native = (int) $custody['grant_refs']['source.read'];
        $body = ['command_id' => (string) Str::uuid(), 'material_refs' => [$ref], 'operation' => $operation, 'purpose' => 'market_research',
            'recipient_ref' => $user->public_id, 'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => (int) $business['grant_refs']['business.context.read']],
                ['requirement_kind' => 'native_source', 'leaf_grant_id' => $native],
            ]]];
        if (! $licensed) {
            return compact('user', 'business', 'id', 'source', 'scope', 'body', 'custody', 'ref', 'native');
        }
        // Existing qualified contracts are represented with the established isolated
        // fixture. These insertions do not demonstrate a production licence issuer.
        $boundary = BusinessAuthorityFixture::boundary($user->id);
        $useScope = (string) Str::uuid7();
        $selector = ['native_namespaces' => [$entry->namespace], 'native_keys' => [$entry->identity], 'field_keys' => ['spend'], 'partition_ref' => null];
        $digest = $owner->transaction(function () use ($owner, $boundary, $source, $operation, $selector) {
            $owner->statement('SET LOCAL ROLE builderad_scope_executor');

            return $owner->scalar("SELECT encode(builderad_private.data_scope_digest(?::uuid,?::uuid,'123',?,'market_research',?::jsonb),'hex')", [$boundary['boundary_id'], $source, $operation, json_encode($selector)]);
        });
        BusinessAuthorityFixture::insert('data_use_scopes', ['scope_id' => $useScope, 'boundary_id' => $boundary['boundary_id'], 'source_id' => $source,
            'resource' => '123', 'selection' => $selector, 'selection_schema_version' => 1, 'operation' => $operation, 'purpose' => 'market_research',
            'scope_digest' => '\\x'.$digest, 'status' => 'active', 'revision' => 1], $user->id);
        $use = BusinessAuthorityFixture::root($useScope, $user->id, 'data_use.'.$operation, kind: 'data_use');
        $manager = BusinessAuthorityFixture::root($id, $user->id, 'business.link.manage');
        $data = BusinessAuthorityFixture::root($id, $user->id, 'business.data.use');
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => $manager->id],
            ['requirement_kind' => 'client_data', 'leaf_grant_id' => $boundary['grant_id']],
        ]];
        $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid(), 'target_kind' => 'boundary_subject', 'target_ref' => $boundary['boundary_id'],
            'effect' => 'attach_subject', 'payload' => ['business_public_id' => $business['business_public_id'], 'branch_id' => null],
            'expected_revision' => 0, 'authority_selection' => $selection])->assertOk();
        $selection['requirements'][1] = ['requirement_kind' => 'data_use', 'leaf_grant_id' => $use->id];
        $this->postJson('/api/businesses/'.$business['business_public_id'].'/data-use-bindings', ['command_id' => (string) Str::uuid(), 'data_use_scope_id' => $useScope,
            'effect' => 'add', 'expected_relation' => 0, 'authority_selection' => $selection])->assertOk();
        $body['authority_selection']['requirements'] = [
            ['requirement_kind' => 'business', 'leaf_grant_id' => $data->id],
            ['requirement_kind' => 'data_use', 'leaf_grant_id' => $use->id],
            ['requirement_kind' => 'native_source', 'leaf_grant_id' => $native],
        ];

        return compact('user', 'business', 'id', 'source', 'scope', 'body', 'custody', 'ref', 'native', 'useScope', 'use', 'boundary', 'manager');
    }
}
