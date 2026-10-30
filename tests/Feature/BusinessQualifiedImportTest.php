<?php

namespace Tests\Feature;

use App\Business\BusinessContext;
use App\Foundation\Tenancy\PrincipalContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Models\Workspace;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture as Fixture;
use Tests\Support\ObjectPurposeTestCase;
use Tests\TestCase;

final class BusinessQualifiedImportTest extends TestCase
{
    use ApplicationContractAssertions;

    private function importFixture(bool $withPurpose = false): array
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withCredentials();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        $this->post('/workspaces', ['name' => 'Original collaboration', 'slug' => 'qualified-import'])->assertRedirect();
        $db = DB::connection('pgsql_migrations');
        $w = $db->table('workspaces')->where('slug', 'qualified-import')->first();
        $r = Request::create('/workspaces/'.$w->public_id);
        $r->setUserResolver(fn () => $user);
        $r->setLaravelSession(app('session')->driver());
        $type = $db->table('business_types')->where('key', 'real_estate')->value('id');
        foreach (['Historic B', 'Legacy A'] as $version => $name) {
            app(WorkspaceContext::class)->runBusinessMutation($user, $w->public_id, fn (Workspace $scope) => app(BusinessContext::class)->write($r, $scope,
                ['name' => $name, 'business_type_id' => $type, 'version' => $version]), $r);
        }
        app(WorkspaceContext::class)->runBusinessMutation($user, $w->public_id, fn (Workspace $scope) => app(BusinessContext::class)->replaceTargetMarkets($r, $scope, ['JO', 'SA'], 2), $r);
        $intent = ['description' => null, 'direction' => 'increase_value', 'scope' => 'business_wide', 'outcome' => ['meaning' => 'Qualified original intent', 'clarification' => null, 'count_unit' => null], 'quantity' => null, 'horizon' => null];
        app(WorkspaceContext::class)->runBusinessMutation($user, $w->public_id, fn (Workspace $scope) => app(BusinessContext::class)->replaceCurrentIntent($r, $scope, $intent,
            ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true, 'clarification_required' => false, 'quantity' => true, 'horizon' => true], 3), $r);
        $this->postJson('/workspaces/'.$w->public_id.'/goals', ['value' => ['goals' => ['sales'], 'priority' => 'sales', 'results' => ['sales' => ['actual_sales']]], 'version' => 0])->assertOk();
        if ($withPurpose) {
            $command = ObjectPurposeTestCase::command();
            $command['declaration']['meaning'] = 'Original qualified purpose';
            $command['declaration']['included_objects'] = [['provider' => 'meta', 'account_namespace' => 'meta_ads_account', 'account_id' => '100', 'object_kind' => 'campaign', 'object_id' => '200', 'incarnation' => null]];
            $first = $this->postJson('/workspaces/'.$w->public_id.'/object-purposes', $command)->assertCreated()->json();
            $command['command_id'] = (string) Str::uuid();
            $command['purpose_id'] = $first['purpose_id'];
            $command['expected_version'] = 1;
            $command['operation'] = 'correct';
            $command['supersedes_revision_id'] = $first['revision_id'];
            $command['correction_reason'] = 'Original supported correction';
            $command['confirmations']['transition'] = true;
            $command['declaration']['meaning'] = 'Corrected qualified purpose';
            $this->postJson('/workspaces/'.$w->public_id.'/object-purposes/'.$first['purpose_id'].'/revisions', $command)->assertCreated();
        }
        $nativeSource = (string) Str::uuid7();
        $tuple = ['source_id' => $nativeSource, 'resource' => 'synthetic-import-account', 'operation' => 'report.read', 'purpose' => 'advertising_analysis', 'reason' => 'explicit_synthetic_import_source'];
        foreach (['source.register', 'source.read', 'source.manage', 'source.audit.recover'] as $action) {
            $this->postJson('/workspaces/'.$w->public_id.'/source-grants', [...$tuple, 'action' => $action, 'decision' => 'authorize'])->assertOk();
        }
        $this->postJson('/workspaces/'.$w->public_id.'/sources', [...$tuple, 'command_id' => (string) Str::uuid(), 'effect' => 'register', 'expected_version' => 0,
            'family' => 'meta', 'namespace' => 'ad_account', 'external_id' => $tuple['resource'], 'label' => 'Synthetic source retained in place'])->assertOk();
        $manager = $db->table('security_action_grants')->where('source_target_id', $nativeSource)->where('action', 'source.manage')->value('id');
        $nativeRead = $db->table('security_action_grants')->where('source_target_id', $nativeSource)->where('action', 'source.read')->value('id');
        $nativeSelection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'native_source', 'leaf_grant_id' => $manager]]];
        $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid(), 'target_kind' => 'source', 'target_ref' => $nativeSource,
            'effect' => 'adopt', 'payload' => (object) [], 'expected_revision' => 1, 'authority_selection' => $nativeSelection])->assertOk();
        $a = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Independent A'])->assertCreated()->json('data');
        $b = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Independent B'])->assertCreated()->json('data');
        $source = $db->table('migration_cohort_members')->where('workspace_id', $w->id)->value('cohort_id');
        $cohorts = [];
        foreach (['a', 'b'] as $key) {
            $target = $key === 'a' ? $a : $b;
            $id = $db->table('businesses')->where('public_id', $target['business_public_id'])->value('id');
            $cohorts[$key] = $db->table('migration_cohort_members')->where('business_id', $id)->value('cohort_id');
            $this->transition($cohorts[$key], 'V2_ACTIVE', 0, 'RECOVERY_HOLD');
        }
        $this->transition($source, 'LEGACY_ACTIVE', 0, 'DRAINING');
        $inventory = json_decode($db->scalar('SELECT builderad_v2_legacy_inventory(?::uuid)', [$source]), true, flags: JSON_THROW_ON_ERROR);
        $manifest = (string) Str::uuid7();
        $entries = [];
        foreach ($inventory['entries'] as $native) {
            $status = $native['origin_table'] === 'advertiser_goal_preference_events' ? 'unresolved' : 'qualified';
            $target = $native['origin_table'] === 'business_context_events' && $native['row']['version'] === 1 ? $b : $a;
            $entry = array_intersect_key($native, array_flip(['origin_table', 'origin_key', 'native_version', 'origin_digest']));
            $entry += ['status' => $status, 'target_business_public_id' => $status === 'qualified' ? $target['business_public_id'] : null, 'qualification_ref' => null];
            if ($status === 'qualified') {
                $entry['qualification_ref'] = $this->qualification($native, $target['business_public_id'], $user->id);
            }
            $entries[] = $entry;
        }

        return compact('user', 'r', 'w', 'a', 'b', 'source', 'cohorts', 'inventory', 'manifest', 'entries', 'intent', 'nativeSource', 'nativeRead', 'nativeSelection');
    }

    private function qualification(array $native, string $target, int $actor, bool $intent = true): string
    {
        $actions = [match ($native['origin_table']) {
            'workspaces' => 'legacy.context.import', 'business_target_markets' => 'legacy.markets.import', 'advertiser_goal_preferences' => 'legacy.preferences.import', default => 'legacy.history.map',
        }];
        if ($native['origin_table'] === 'workspaces' && $intent) {
            $actions[] = 'legacy.intent.import';
        }
        $id = (string) Str::uuid7();
        Fixture::insert('authority_qualification_receipts', ['qualification_id' => $id, 'policy_id' => 'test.synthetic.legacy.mapping', 'policy_version' => 1,
            'issuer_kind' => 'qualified_policy', 'issuer_grant_id' => null, 'subject_user_id' => $actor, 'target_kind' => 'legacy_scope_mapping', 'target_ref' => $target,
            'allowed_actions' => '{'.implode(',', $actions).'}', 'evidence_ref' => $native['origin_ref'], 'evidence_digest' => '\\x'.$native['origin_digest'],
            'status' => 'active', 'revision' => 1, 'valid_from' => now()->subSecond()->toIso8601String(), 'valid_until' => null,
            'provenance' => 'SYNTHETIC REVIEW ONLY; no operating issuer or customer migration qualification'], $actor);

        return $id;
    }

    private function transition(string $cohort, string $mode, int $epoch, string $next, ?string $manifest = null, ?string $seal = null, ?string $command = null): array
    {
        return json_decode(DB::connection('pgsql_migrations')->scalar('SELECT builderad_v2_cohort_transition(?::uuid,?::uuid,?,?,?,?::uuid,decode(?,\'hex\'))',
            [$command ?? (string) Str::uuid(), $cohort, $mode, $epoch, $next, $manifest, $seal ?? hash('sha256', 'synthetic-maintenance-checkpoint')]), true, flags: JSON_THROW_ON_ERROR);
    }

    private function import(array $f, ?string $command = null, ?array $entries = null): array
    {
        return json_decode(DB::connection('pgsql_migrations')->scalar('SELECT builderad_v2_legacy_import(?::uuid,?::uuid,?,?::uuid,?::jsonb,decode(?,\'hex\'))',
            [$command ?? (string) Str::uuid(), $f['source'], $f['inventory']['epoch'], $f['manifest'], json_encode($entries ?? $f['entries'], JSON_THROW_ON_ERROR), $f['inventory']['native_digest']]), true, flags: JSON_THROW_ON_ERROR);
    }

    private function denied(callable $operation, string $state = '42501'): void
    {
        try {
            $operation();
            $this->fail('Unqualified migration operation succeeded.');
        } catch (QueryException $error) {
            $this->assertSame($state, $error->errorInfo[0], $error->getMessage());
        }
    }

    private function snapshot(): array
    {
        $db = DB::connection('pgsql_migrations');

        return array_map(fn ($table) => $db->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r)-'runtime_lock_marker'-'scope_lock_marker' ORDER BY to_jsonb(r)::text),'[]'::jsonb)::text FROM {$table} r"),
            ['workspaces', 'business_context_events', 'business_current_intent_events', 'business_target_markets', 'business_target_market_events', 'advertiser_goal_preferences', 'advertiser_goal_preference_events', 'security_action_grants',
                'source_registry', 'source_capabilities', 'source_command_receipts', 'source_versions', 'source_audit_obligations', 'source_audit_delivery']);
    }

    public function test_qualified_baseline_history_cutover_and_forward_recovery_preserve_independent_identities(): void
    {
        $f = $this->importFixture();
        $db = DB::connection('pgsql_migrations');
        $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid(), 'target_kind' => 'source', 'target_ref' => $f['nativeSource'],
            'effect' => 'revoke', 'payload' => ['grant_id' => (string) $f['nativeRead']], 'expected_revision' => 1, 'authority_selection' => $f['nativeSelection']])->assertOk();
        $before = $this->snapshot();
        $count = $db->table('businesses')->count();
        $command = (string) Str::uuid();
        $result = $this->import($f, $command);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($count, $db->table('businesses')->count());
        $this->assertSame($result, $this->import($f, $command)); // Simulated lost ACK: reconcile the exact committed command.
        $this->denied(fn () => $this->transition($f['cohorts']['a'], 'RECOVERY_HOLD', 1, 'V2_ACTIVE', $f['manifest'], $result['checkpoint']));
        $this->denied(fn () => $this->transition($f['source'], 'DRAINING', 1, 'LEGACY_ACTIVE'));
        $this->transition($f['source'], 'DRAINING', 1, 'V2_ACTIVE', $f['manifest'], $result['checkpoint']);
        foreach ($f['cohorts'] as $target) {
            $this->transition($target, 'RECOVERY_HOLD', 1, 'V2_ACTIVE', $f['manifest'], $result['checkpoint']);
        }
        $selection = fn ($a) => ['authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $a['grant_refs']['business.context.read']]]]];
        $projection = $this->postJson('/api/businesses/'.$f['a']['business_public_id'].'/read', $selection($f['a']))->assertOk()->assertJsonPath('data.name', 'Legacy A');
        $this->assertJsonStringEqualsJsonString(json_encode($f['intent']), json_encode($projection->json('data.current_intent')));
        $this->postJson('/api/businesses/'.$f['b']['business_public_id'].'/read', $selection($f['b']))->assertOk()->assertJsonPath('data.name', 'Independent B');
        $bId = $db->table('businesses')->where('public_id', $f['b']['business_public_id'])->value('id');
        $historical = $db->table('legacy_scope_mappings')->where('target_business_id', $bId)->sole();
        $read = json_decode($db->scalar('SELECT builderad_v2_legacy_resolve(?::uuid,?::uuid)', [$f['b']['business_public_id'], $historical->mapping_id]), true);
        $this->assertSame('Historic B', $read['origin']['row']['new_name']);
        $this->denied(fn () => $db->scalar('SELECT builderad_v2_legacy_resolve(?::uuid,?::uuid)', [$f['a']['business_public_id'], $historical->mapping_id]));
        $unknown = $db->table('legacy_scope_mappings')->where('status', 'unresolved')->sole();
        $this->denied(fn () => $db->scalar('SELECT builderad_v2_legacy_resolve(?::uuid,?::uuid)', [$f['a']['business_public_id'], $unknown->mapping_id]));
        $this->postJson('/workspaces/'.$f['w']->public_id.'/business/target-markets', ['country_codes' => ['AE'], 'version' => 4])->assertForbidden();
        $this->denied(fn () => $db->table('workspaces')->where('id', $f['w']->id)->update(['business_context_version' => 5]));
        $this->putJson('/api/businesses/'.$f['a']['business_public_id'].'/context', ['command_id' => (string) Str::uuid(), 'name' => 'Canonical forward change', 'business_type_id' => null, 'expected_context' => 0,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $f['a']['grant_refs']['business.context.manage']]]]])->assertOk();
        $db->table('workspaces')->where('id', $f['w']->id)->update(['name' => 'Collaboration label only']);
        $this->transition($f['source'], 'V2_ACTIVE', 2, 'RECOVERY_HOLD');
        $this->transition($f['source'], 'RECOVERY_HOLD', 3, 'V2_ACTIVE', $f['manifest'], $result['checkpoint']);
        $this->transition($f['cohorts']['a'], 'V2_ACTIVE', 2, 'RECOVERY_HOLD');
        $this->transition($f['cohorts']['a'], 'RECOVERY_HOLD', 3, 'V2_ACTIVE', $f['manifest'], $result['checkpoint']);
        $this->postJson('/api/businesses/'.$f['a']['business_public_id'].'/read', $selection($f['a']))->assertOk()->assertJsonPath('data.name', 'Canonical forward change');
        $this->getJson('/api/businesses/'.$f['a']['business_public_id'].'/history?kind=context&access='.$f['a']['grant_refs']['business.context.read'])
            ->assertOk()->assertJsonPath('data.has_imported_baseline', true)->assertJsonPath('data.legacy_history', 'not_included')
            ->assertJsonCount(1, 'data.entries')->assertJsonPath('data.entries.0.version', '1')
            ->assertJsonPath('data.entries.0.before.name', 'Legacy A')->assertJsonPath('data.entries.0.after.name', 'Canonical forward change');
        $this->getJson('/api/businesses/'.$f['b']['business_public_id'].'/history?kind=context&access='.$f['b']['grant_refs']['business.context.read'])
            ->assertOk()->assertJsonCount(0, 'data.entries')->assertJsonPath('data.has_imported_baseline', false);
        $this->assertSame('Collaboration label only', $db->table('workspaces')->where('id', $f['w']->id)->value('name'));
        $this->assertSame(1, $db->table('business_context_transitions')->count());
        $this->assertSame('revoked', $db->table('security_action_grants')->where('id', $f['nativeRead'])->value('status'));
        $this->assertSame(1, $db->table('source_registry')->count());
        $this->assertSame($f['nativeSource'], $db->table('source_registry')->value('id'));
    }

    public function test_partial_import_failure_rolls_back_every_baseline_mapping_and_receipt_before_retry(): void
    {
        $f = $this->importFixture();
        $db = DB::connection('pgsql_migrations');
        $bad = $f['entries'];
        foreach ($bad as &$entry) {
            if ($entry['origin_table'] === 'business_target_markets' && json_decode($entry['origin_key'], true)['country_code'] === 'SA') {
                $entry['status'] = 'unresolved';
                $entry['target_business_public_id'] = null;
                $entry['qualification_ref'] = null;
            }
        }
        unset($entry);
        $this->denied(fn () => $this->import($f, entries: $bad));
        $this->assertSame(0, $db->table('legacy_scope_mappings')->count());
        $this->assertSame(0, $db->table('business_declared_markets')->count());
        $this->assertSame(0, $db->table('business_goal_preferences')->count());
        $this->assertSame(0, $db->table('business_command_receipts')->where('action', 'maintenance.legacy.import')->count());
        $this->assertSame('Independent A', $db->table('business_context_heads')->where('business_id', $db->table('businesses')->where('public_id', $f['a']['business_public_id'])->value('id'))->value('display_name'));
        $this->assertSame(0, $db->table('migration_cohort_control')->where('cohort_id', $f['source'])->value('manifest_version'));
        $this->transition($f['source'], 'DRAINING', 1, 'LEGACY_ACTIVE'); // No canonical import committed: pre-import recovery is safe.
        $this->transition($f['source'], 'LEGACY_ACTIVE', 2, 'DRAINING');
        $f['inventory'] = json_decode($db->scalar('SELECT builderad_v2_legacy_inventory(?::uuid)', [$f['source']]), true);
        $this->import($f);
        $this->assertSame(1, $db->table('business_command_receipts')->where('action', 'maintenance.legacy.import')->count());
    }

    public function test_runtime_qualified_original_history_preserves_purpose_corrections_and_denies_unmapped_or_withdrawn_reads(): void
    {
        $f = $this->importFixture(true);
        $db = DB::connection('pgsql_migrations');
        $before = $this->snapshot();
        $native = $db->table('object_purpose_revisions')->orderBy('version')->get()->map(fn ($r) => (array) $r)->all();
        $result = $this->import($f);
        $this->transition($f['source'], 'DRAINING', 1, 'V2_ACTIVE', $f['manifest'], $result['checkpoint']);
        foreach ($f['cohorts'] as $target) {
            $this->transition($target, 'RECOVERY_HOLD', 1, 'V2_ACTIVE', $f['manifest'], $result['checkpoint']);
        }
        $url = fn (array $business, string $kind) => '/api/businesses/'.$business['business_public_id'].'/legacy-history?access='.$business['grant_refs']['business.context.read'].'&kind='.$kind;
        $refs = $db->table('object_purpose_business_context_refs')->orderBy('native_revision_id')->get();
        $this->assertCount(2, $refs);
        foreach ($refs as $ref) {
            $this->assertSame('qualified_import', $ref->reference_origin);
            $this->assertSame(0, (int) $ref->context_version);
            $this->assertContains($ref->native_revision_id, array_column($native, 'revision_id'));
            $this->assertSame($ref->business_id, $db->table('legacy_scope_mappings')->where('mapping_id', $ref->mapping_id)->value('target_business_id'));
        }
        $this->getJson('/api/businesses/'.$f['a']['business_public_id'].'/purposes?access='.$f['a']['grant_refs']['business.context.read'])->assertOk()->assertJsonCount(0, 'data.projection.items')->assertJsonPath('data.projection.state', 'UNKNOWN');
        $purpose = $this->getJson($url($f['a'], 'object_purpose'))->assertOk()->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('data.completeness', 'QUALIFIED_SUBSET_ONLY')->assertJsonPath('data.order', 'mapping_reference_not_effective_timeline')
            ->assertJsonPath('data.entries.0.native_version', '1')->assertJsonPath('data.entries.1.native_version', '2')
            ->assertJsonPath('data.entries.0.revision_id', $native[0]['revision_id'])->assertJsonPath('data.entries.1.revision_id', $native[1]['revision_id'])
            ->assertJsonPath('data.entries.1.supersedes_revision_id', $native[0]['revision_id'])
            ->assertJsonPath('data.entries.1.operation', 'correct')->assertJsonPath('data.entries.1.snapshot.meaning', 'Corrected qualified purpose')
            ->assertJsonPath('data.entries.1.applicability', 'NOT_EVALUATED_HISTORICAL_DECLARATION');
        $this->assertApplicationContract('BusinessLegacyHistoryResult', $purpose->json());
        foreach (['actor_user_id', 'ownership_version', 'actor_generation', 'command_id', 'workspace_id', 'qualification_id'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $purpose->getContent());
        }
        $this->getJson($url($f['b'], 'object_purpose'))->assertOk()->assertJsonCount(0, 'data.entries');
        $this->getJson($url($f['a'], 'object_purpose').'&after='.$purpose->json('data.entries.0.mapping_ref'))->assertOk()->assertJsonCount(1, 'data.entries');
        $context = $this->getJson($url($f['b'], 'context'))->assertOk()->assertJsonCount(1, 'data.entries')->assertJsonPath('data.entries.0.after.name', 'Historic B');
        $this->assertApplicationContract('BusinessLegacyHistoryResult', $context->json());
        foreach (['markets', 'intent', 'preferences'] as $kind) {
            $history = $this->getJson($url($f['a'], $kind))->assertOk();
            $this->assertApplicationContract('BusinessLegacyHistoryResult', $history->json());
            $history->assertJsonCount($kind === 'preferences' ? 0 : 1, 'data.entries'); // unresolved preference history is never promoted.
        }
        $q = $db->table('legacy_scope_mappings')->where('mapping_id', $purpose->json('data.entries.0.mapping_ref'))->value('qualification_ref');
        Fixture::revokeQualification($q, $f['user']->id);
        $this->getJson($url($f['a'], 'object_purpose'))->assertOk()->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.entries.0.revision_id', $native[1]['revision_id']);
        $read = $db->table('security_action_grants')->where('id', $f['a']['grant_refs']['business.context.read'])->value('qualification_id');
        Fixture::revokeQualification($read, $f['user']->id);
        $this->getJson($url($f['a'], 'object_purpose'))->assertForbidden();
        $this->getJson('/workspaces/'.$f['w']->public_id.'/object-purposes')->assertForbidden();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($native, $db->table('object_purpose_revisions')->orderBy('version')->get()->map(fn ($r) => (array) $r)->all());
    }

    public function test_legacy_history_locks_source_cohort_before_principals_and_discards_mapping_withdrawn_during_real_wait(): void
    {
        $f = $this->importFixture();
        $db = DB::connection('pgsql_migrations');
        $result = $this->import($f);
        $this->transition($f['source'], 'DRAINING', 1, 'V2_ACTIVE', $f['manifest'], $result['checkpoint']);
        foreach ($f['cohorts'] as $target) {
            $this->transition($target, 'RECOVERY_HOLD', 1, 'V2_ACTIVE', $f['manifest'], $result['checkpoint']);
        }
        $viewer = User::factory()->create();
        $businessId = $db->table('businesses')->where('public_id', $f['b']['business_public_id'])->value('id');
        $read = Fixture::root($businessId, $viewer->id, 'business.context.read');
        $mapping = $db->table('legacy_scope_mappings')->where('target_business_id', $businessId)->sole();
        $this->actingAs($viewer)->getJson('/api/businesses/'.$f['b']['business_public_id'].'/legacy-history?kind=context&access='.$read->id)
            ->assertOk()->assertJsonCount(1, 'data.entries');
        $r = Request::create('/api/businesses');
        $r->setLaravelSession(app('session')->driver());
        $run = 'legacy-history-wait-'.Str::uuid();
        if (! is_dir(storage_path('framework/testing'))) {
            mkdir(storage_path('framework/testing'), 0777, true);
        }
        $file = storage_path('framework/testing/'.$run.'.json');
        $release = $file.'.release';
        file_put_contents($file, json_encode(['actor' => $viewer->id, 'session' => SessionAuthority::sessionKey($r), 'run' => $run, 'release' => $release,
            'operation' => 'legacy-history', 'business' => $f['b']['business_public_id'], 'read_grant' => $read->id], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $file], base_path());
        $worker->setTimeout(45);
        $evidence = [];
        $wait = function (callable $probe) use ($worker) {
            $deadline = microtime(true) + 15;
            do {
                if ($value = $probe()) {
                    return $value;
                }
                if (! $worker->isRunning()) {
                    throw new \RuntimeException($worker->getOutput().$worker->getErrorOutput());
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            throw new \RuntimeException('Actual legacy-history cohort wait was not observed');
        };
        try {
            $worker->start();
            $wait(fn () => str_contains($worker->getOutput(), 'READY_PID='));
            $db->beginTransaction();
            $blocker = (int) $db->scalar('SELECT pg_backend_pid()');
            $db->select('SELECT cohort_id FROM migration_cohort_control WHERE cohort_id=?::uuid FOR UPDATE', [$f['source']]);
            file_put_contents($release, 'go');
            $evidence['wait'] = $wait(fn () => DB::selectOne('SELECT pid,wait_event_type,wait_event,pg_blocking_pids(pid)::text blockers FROM pg_stat_activity WHERE application_name=? AND ?=ANY(pg_blocking_pids(pid))', [$run, $blocker]));
            $db->transaction(fn () => $db->select('SELECT user_id FROM principal_lifecycles WHERE user_id IN(?,?) ORDER BY user_id FOR UPDATE NOWAIT', [$f['user']->id, $viewer->id]));
            $evidence['both_mapping_and_read_principals_available_at_k2'] = true;
            Fixture::revokeQualification($mapping->qualification_ref, $f['user']->id);
            $db->commit();
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('"entries": []', $worker->getOutput());
            $this->assertStringNotContainsString('Historic B', $worker->getOutput());
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(), 'exit' => $worker->getExitCode()];
            file_put_contents(storage_path('framework/testing/'.$run.'-evidence.json'), json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            foreach ([$file, $release] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_missing_intent_qualification_and_runtime_roles_cannot_import_or_resolve_history(): void
    {
        $f = $this->importFixture();
        $bad = $f['entries'];
        foreach ($bad as &$entry) {
            if ($entry['origin_table'] === 'workspaces') {
                $native = collect($f['inventory']['entries'])->firstWhere('origin_table', 'workspaces');
                $entry['qualification_ref'] = $this->qualification($native, $f['a']['business_public_id'], $f['user']->id, false);
            }
        }
        unset($entry);
        $this->denied(fn () => $this->import($f, entries: $bad));
        foreach (['pgsql', 'pgsql_authority'] as $connection) {
            $this->denied(fn () => DB::connection($connection)->scalar('SELECT builderad_v2_legacy_inventory(?::uuid)', [$f['source']]));
            $this->denied(fn () => DB::connection($connection)->scalar('SELECT builderad_v2_legacy_import(?::uuid,?::uuid,1,?::uuid,?::jsonb,decode(?,\'hex\'))', [(string) Str::uuid(), $f['source'], $f['manifest'], json_encode($f['entries']), $f['inventory']['native_digest']]));
        }
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('legacy_scope_mappings')->count());
    }

    public function test_unreconciled_queue_work_blocks_import_without_claiming_the_job_succeeded(): void
    {
        $f = $this->importFixture();
        $db = DB::connection('pgsql_migrations');
        $job = $db->table('jobs')->insertGetId(['queue' => 'synthetic-unknown-legacy', 'payload' => '{"synthetic_nonexecuted":true}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        try {
            $this->denied(fn () => $this->import($f));
            $this->assertSame(0, $db->table('legacy_scope_mappings')->count());
            $this->assertSame(1, $db->table('jobs')->where('id', $job)->count());
        } finally {
            // Only this synthetic, deliberately non-executable fixture is reconciled/removed.
            $db->table('jobs')->where('id', $job)->delete();
        }
        $this->import($f);
        $this->assertSame(1, $db->table('business_command_receipts')->where('action', 'maintenance.legacy.import')->count());
    }

    public function test_unknown_definer_catalog_drift_blocks_qualification_until_the_installed_contract_is_restored(): void
    {
        $f = $this->importFixture();
        $db = DB::connection('pgsql_migrations');
        $db->unprepared("CREATE FUNCTION public.synthetic_uninventoried_definer() RETURNS integer LANGUAGE sql SECURITY DEFINER AS 'SELECT 1'; GRANT EXECUTE ON FUNCTION public.synthetic_uninventoried_definer() TO builderad_app");
        try {
            $this->denied(fn () => $this->import($f));
            $this->assertSame(0, $db->table('legacy_scope_mappings')->count());
            $this->assertSame('DRAINING', $db->table('migration_cohort_control')->where('cohort_id', $f['source'])->value('mode'));
        } finally {
            $db->unprepared('DROP FUNCTION public.synthetic_uninventoried_definer()');
        }
        $this->import($f);
        $this->assertSame(1, $db->table('business_command_receipts')->where('action', 'maintenance.legacy.import')->count());
    }

    private function worker(array $f, array $extra = []): array
    {
        $run = 'legacy-maintenance-'.Str::uuid();
        $file = storage_path('framework/testing/'.$run.'.json');
        $release = $file.'.release';
        $committed = $file.'.committed';
        $ack = $file.'.ack';
        $command = (string) Str::uuid();
        file_put_contents($file, json_encode([...['run' => $run, 'release' => $release, 'committed' => $committed, 'ack_release' => $ack, 'command' => $command,
            'operation' => 'import', 'cohort' => $f['source'], 'epoch' => $f['inventory']['epoch'], 'manifest' => $f['manifest'], 'entries' => $f['entries'], 'native_digest' => $f['inventory']['native_digest']], ...$extra], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessMigrationCommandWorker.php'), $file], base_path());
        $worker->setTimeout(45);
        $worker->start();
        $this->await(fn () => str_contains($worker->getOutput(), 'READY_PID='), $worker);

        return compact('worker', 'run', 'file', 'release', 'committed', 'ack', 'command');
    }

    private function await(callable $probe, Process $worker, bool $allowExited = false): mixed
    {
        $until = microtime(true) + 25;
        do {
            if ($value = $probe()) {
                return $value;
            }
            if (! $allowExited && ! $worker->isRunning()) {
                $this->fail('Migration worker exited before required observation: '.$worker->getOutput().$worker->getErrorOutput());
            }
            usleep(10000);
        } while (microtime(true) < $until);
        $this->fail('Actual migration contention/outcome was not observed: '.$worker->getOutput().$worker->getErrorOutput());
    }

    private function finishWorker(array $w, array $evidence): void
    {
        if ($w['worker']->isRunning()) {
            $w['worker']->stop();
        }
        $evidence['worker'] = ['exit' => $w['worker']->getExitCode(), 'stdout' => $w['worker']->getOutput(), 'stderr' => $w['worker']->getErrorOutput()];
        file_put_contents(storage_path('framework/testing/'.$w['run'].'-evidence.json'), json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        foreach (['file', 'release', 'committed', 'ack'] as $key) {
            if (is_file($w[$key])) {
                unlink($w[$key]);
            }
        }
    }

    public function test_drain_waits_for_real_legacy_writer_and_refuses_its_stale_prepared_baseline(): void
    {
        $f = $this->importFixture();
        $this->transition($f['source'], 'DRAINING', 1, 'LEGACY_ACTIVE');
        $w = $this->worker($f, ['operation' => 'transition', 'mode' => 'LEGACY_ACTIVE', 'epoch' => 2, 'next' => 'DRAINING', 'manifest' => null, 'checkpoint' => hash('sha256', 'isolated-drain')]);
        $observer = DB::build(config('database.connections.pgsql_migrations'));
        $evidence = ['kind' => 'legacy-writer-drain'];
        try {
            app(WorkspaceContext::class)->runBusinessMutation($f['user'], $f['w']->public_id, function (Workspace $scope) use ($f, $w, $observer, &$evidence): void {
                app(BusinessContext::class)->replaceTargetMarkets($f['r'], $scope, ['AE'], 4);
                $holder = DB::scalar('SELECT pg_backend_pid()');
                file_put_contents($w['release'], 'release');
                $evidence['waiter'] = $this->await(function () use ($w, $observer, $holder) {
                    $row = $observer->selectOne('SELECT pid,backend_xid::text AS xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=?', [$w['run']]);

                    return $row?->wait_event_type === 'Lock' && str_contains($row->blockers, (string) $holder) ? $row : false;
                }, $w['worker']);
                $this->assertTrue($w['worker']->isRunning());
            }, $f['r']);
            $w['worker']->wait();
            $this->assertSame(0, $w['worker']->getExitCode(), $w['worker']->getOutput().$w['worker']->getErrorOutput());
            $db = DB::connection('pgsql_migrations');
            $this->assertSame(5, $db->table('workspaces')->where('id', $f['w']->id)->value('business_context_version'));
            $this->assertSame('DRAINING', $db->table('migration_cohort_control')->where('cohort_id', $f['source'])->value('mode'));
            $this->assertSame(3, $db->table('migration_cohort_control')->where('cohort_id', $f['source'])->value('epoch'));
            $f['inventory']['epoch'] = 3;
            $this->denied(fn () => $this->import($f), '40001');
            $this->assertSame(0, $db->table('legacy_scope_mappings')->count());
        } finally {
            $observer->disconnect();
            $this->finishWorker($w, $evidence);
        }
    }

    public function test_disconnect_before_commit_rolls_back_import_and_retry_does_not_duplicate_or_restore_rights(): void
    {
        $f = $this->importFixture();
        $db = DB::connection('pgsql_migrations');
        $observer = DB::build(config('database.connections.pgsql_migrations'));
        $before = $this->snapshot();
        $w = $this->worker($f);
        $evidence = ['kind' => 'disconnect-before-commit'];
        try {
            $db->beginTransaction();
            $db->statement('LOCK TABLE business_command_receipts IN SHARE MODE');
            file_put_contents($w['release'], 'release');
            $evidence['waiter'] = $this->await(fn () => $observer->selectOne("SELECT a.pid,a.backend_xid::text AS xid,pg_blocking_pids(a.pid)::text AS blockers,l.mode,l.relation::regclass::text AS relation FROM pg_stat_activity a JOIN pg_locks l ON l.pid=a.pid WHERE a.application_name=? AND l.relation='business_command_receipts'::regclass AND NOT l.granted", [$w['run']]), $w['worker']);
            $this->assertSame('RowExclusiveLock', $evidence['waiter']->mode);
            $evidence['prior_effect_locks'] = $observer->select("SELECT relation::regclass::text AS relation,mode,granted FROM pg_locks WHERE pid=? AND relation IN('business_context_heads'::regclass,'legacy_scope_mappings'::regclass) AND mode='RowExclusiveLock' AND granted", [$evidence['waiter']->pid]);
            $this->assertCount(2, $evidence['prior_effect_locks']);
            $w['worker']->stop(); // Only this owned PHP client; its explicit outer transaction has sent no COMMIT.
            $this->assertStringNotContainsString('DENIED_STATE=', $w['worker']->getOutput());
            $this->assertStringNotContainsString('COMMITTED=', $w['worker']->getOutput());
            $db->rollBack();
            $this->await(fn () => ! $observer->scalar('SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE pid=?)', [$evidence['waiter']->pid]), $w['worker'], true);
            $this->assertSame(0, $db->table('legacy_scope_mappings')->count());
            $this->assertSame(0, $db->table('business_command_receipts')->where('command_id', $w['command'])->count());
            $this->assertSame($before, $this->snapshot());
            $this->assertSame(0, $db->table('migration_cohort_control')->where('cohort_id', $f['source'])->value('manifest_version'));
            $this->import($f, $w['command']);
            $this->assertSame(1, $db->table('business_command_receipts')->where('command_id', $w['command'])->count());
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            $observer->disconnect();
            $this->finishWorker($w, $evidence);
        }
    }

    public function test_committed_import_with_lost_ack_reconciles_exact_receipt_without_a_second_effect(): void
    {
        $f = $this->importFixture();
        $w = $this->worker($f, ['suppress_ack' => true]);
        $evidence = ['kind' => 'committed-import-lost-ack'];
        try {
            file_put_contents($w['release'], 'release');
            $this->await(fn () => is_file($w['committed']), $w['worker']);
            $this->assertStringNotContainsString('COMMITTED=', $w['worker']->getOutput());
            $w['worker']->stop();
            $db = DB::connection('pgsql_migrations');
            $before = $db->table('legacy_scope_mappings')->count();
            $r = $this->import($f, $w['command']);
            $this->assertSame($before, $db->table('legacy_scope_mappings')->count());
            $this->assertSame(1, $db->table('business_command_receipts')->where('command_id', $w['command'])->count());
            $this->assertSame($f['manifest'], $r['manifest_ref']);
            $changed = $f['entries'];
            array_pop($changed);
            $this->denied(fn () => $this->import($f, $w['command'], $changed), '40001');
        } finally {
            $this->finishWorker($w, $evidence);
        }
    }

    public function test_mapping_qualification_expiring_during_final_receipt_wait_rolls_back_the_whole_import(): void
    {
        $f = $this->importFixture();
        $db = DB::connection('pgsql_migrations');
        $observer = DB::build(config('database.connections.pgsql_migrations'));
        $before = $this->snapshot();
        $w = $this->worker($f);
        $evidence = ['kind' => 'qualification-expiry-at-final-import-receipt'];
        try {
            $head = collect($f['entries'])->firstWhere('origin_table', 'workspaces');
            $expires = Fixture::expireQualificationSoon($head['qualification_ref'], $f['user']->id);
            $evidence['expires'] = $expires;
            $db->beginTransaction();
            $holder = (int) $db->scalar('SELECT pg_backend_pid()');
            $db->statement('LOCK TABLE business_command_receipts IN SHARE MODE');
            file_put_contents($w['release'], 'release');
            $evidence['waiter'] = $this->await(fn () => $observer->selectOne("SELECT a.pid,a.backend_xid::text AS xid,pg_blocking_pids(a.pid)::text AS blockers,l.mode FROM pg_stat_activity a JOIN pg_locks l ON l.pid=a.pid WHERE a.application_name=? AND ?=ANY(pg_blocking_pids(a.pid)) AND l.relation='business_command_receipts'::regclass AND NOT l.granted", [$w['run'], $holder]), $w['worker']);
            $this->assertSame('RowExclusiveLock', $evidence['waiter']->mode);
            $this->await(fn () => (bool) $db->scalar('SELECT clock_timestamp()>=?::timestamptz', [$expires]), $w['worker']);
            $db->commit();
            $w['worker']->wait();
            $this->assertSame(2, $w['worker']->getExitCode(), $w['worker']->getOutput().$w['worker']->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $w['worker']->getOutput());
            $this->assertStringContainsString('Exact current historic mapping qualification required', $w['worker']->getOutput());
            $this->assertStringNotContainsString('COMMITTED=', $w['worker']->getOutput());
            $this->assertSame($before, $this->snapshot());
            $this->assertSame(0, $db->table('legacy_scope_mappings')->count());
            $this->assertSame(0, $db->table('business_command_receipts')->where('command_id', $w['command'])->count());
            $this->assertSame(0, $db->table('migration_cohort_control')->where('cohort_id', $f['source'])->value('manifest_version'));
            $this->assertSame(['Independent A', 'Independent B'], $db->table('business_context_heads')->orderBy('business_id')->pluck('display_name')->all());
            $this->assertSame(0, $db->table('business_declared_markets')->count());
            $this->assertSame(0, $db->table('business_goal_preferences')->count());
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            $observer->disconnect();
            $this->finishWorker($w, $evidence);
        }
    }

    public function test_retired_legacy_workspace_row_cannot_bypass_withdrawn_independent_business_read_authority(): void
    {
        $f = $this->importFixture();
        $command = (string) Str::uuid();
        $result = $this->import($f, $command);
        $this->transition($f['source'], 'DRAINING', 1, 'V2_ACTIVE', $f['manifest'], $result['checkpoint']);
        foreach ($f['cohorts'] as $cohort) {
            $this->transition($cohort, 'RECOVERY_HOLD', 1, 'V2_ACTIVE', $f['manifest'], $result['checkpoint']);
        }
        $read = $f['a']['grant_refs']['business.context.read'];
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $read]]];
        $this->postJson('/api/businesses/'.$f['a']['business_public_id'].'/grants', ['command_id' => (string) Str::uuid(), 'effect' => 'revoke',
            'grantee_public_id' => $f['user']->public_id, 'action' => 'business.context.read', 'parent_grant_id' => null, 'grant_id' => $read,
            'expected_grant' => 1, 'expires_at' => null, 'delegable' => false, 'realm_workspace_public_id' => null, 'authority_selection' => $selection])->assertOk();
        $this->postJson('/api/businesses/'.$f['a']['business_public_id'].'/read', ['authority_selection' => $selection])->assertForbidden();
        $safe = $this->getJson('/api/businesses/'.$f['b']['business_public_id'].'/surface?access='.$f['b']['grant_refs']['business.context.read'])->assertOk();
        $safe->assertJsonPath('data.collaboration.workspaces.0.slug', 'qualified-import')->assertJsonMissingPath('data.collaboration.workspaces.0.name');
        $this->assertStringNotContainsString('Legacy A', $safe->getContent());
        DB::transaction(function () use ($f): void {
            app(PrincipalContext::class)->activate($f['user']);
            $this->assertSame(0, DB::table('workspaces')->where('id', $f['w']->id)->get(['name', 'business_type_id', 'current_intent'])->count(), 'Legacy membership must not expose the retired business profile.');
        });
        $this->assertSame($result, $this->import($f, $command));
        $this->assertSame('revoked', DB::connection('pgsql_migrations')->table('security_action_grants')->where('id', $read)->value('status'));
    }
}
