<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture;
use Tests\TestCase;

final class BusinessHistoryTest extends TestCase
{
    use ApplicationContractAssertions;

    private function draft(string $name): array
    {
        return $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => $name])->assertCreated()->json('data');
    }

    private function selection(array $business, string $action): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $business['grant_refs'][$action]]]];
    }

    private function url(array $business, string $kind = 'context', int $after = 0): string
    {
        return '/api/businesses/'.$business['business_public_id'].'/history?access='.$business['grant_refs']['business.context.read'].'&kind='.$kind.'&after='.$after;
    }

    private function rename(array $business, int $expected, string $name): array
    {
        return $this->putJson('/api/businesses/'.$business['business_public_id'].'/context', ['command_id' => (string) Str::uuid(),
            'name' => $name, 'business_type_id' => null, 'expected_context' => $expected,
            'authority_selection' => $this->selection($business, 'business.context.manage')])->assertOk()->json('data');
    }

    public function test_history_preserves_native_owners_versions_and_values_without_mixing_subjects_or_releasing_authority_metadata(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $a = $this->draft('Original activity');
        $b = $this->draft('Other activity');
        $this->rename($a, 0, 'Corrected activity');
        $base = '/api/businesses/'.$a['business_public_id'];
        $this->putJson($base.'/markets', ['command_id' => (string) Str::uuid(), 'codes' => ['SA', 'JO'], 'expected_context' => 1,
            'authority_selection' => $this->selection($a, 'business.markets.declare')])->assertOk();
        $intent = ['description' => null, 'direction' => 'increase_value', 'scope' => 'business_wide',
            'outcome' => ['meaning' => 'Qualified stated outcome', 'clarification' => null, 'count_unit' => null], 'quantity' => null, 'horizon' => null];
        $this->putJson($base.'/intent', ['command_id' => (string) Str::uuid(), 'value' => $intent,
            'confirmations' => ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true, 'clarification_required' => false, 'quantity' => true, 'horizon' => true],
            'expected_context' => 2, 'expected_intent' => 0, 'authority_selection' => $this->selection($a, 'business.intent.declare')])->assertOk();
        $goals = ['goals' => ['sales'], 'priority' => 'sales', 'results' => ['sales' => ['actual_sales']]];
        $this->putJson($base.'/preferences', ['command_id' => (string) Str::uuid(), 'value' => $goals, 'expected_preference' => 0,
            'authority_selection' => $this->selection($a, 'business.preferences.manage')])->assertOk();
        foreach (['context' => ['1', '1', 'Corrected activity'], 'markets' => ['2', '2', null], 'intent' => ['1', '3', null], 'preferences' => ['1', null, null]] as $kind => [$version, $context]) {
            $result = $this->getJson($this->url($a, $kind))->assertOk()->assertJsonCount(1, 'data.entries')
                ->assertJsonPath('data.entries.0.version', $version)->assertJsonPath('data.entries.0.context_version', $context)
                ->assertJsonPath('data.has_imported_baseline', false)->assertJsonPath('data.legacy_history', 'not_included');
            $this->assertApplicationContract('BusinessHistoryResult', $result->json());
            foreach (['actor_id', 'command_id', 'workspace_id', 'qualification_id'] as $private) {
                $this->assertStringNotContainsString($private, $result->getContent());
            }
            $this->getJson($this->url($b, $kind))->assertOk()->assertJsonCount(0, 'data.entries');
        }
        $this->getJson($this->url($a))->assertJsonPath('data.entries.0.before.name', 'Original activity')->assertJsonPath('data.entries.0.after.name', 'Corrected activity');
        $this->getJson($this->url($a, 'markets'))->assertJsonPath('data.entries.0.after', ['JO', 'SA']);
        $declared = $this->getJson($this->url($a, 'intent'))->assertJsonPath('data.entries.0.before', null)->json('data.entries.0.after');
        $this->assertJsonStringEqualsJsonString(json_encode($intent), json_encode($declared));
        $this->assertJsonStringEqualsJsonString(json_encode($goals), json_encode($this->getJson($this->url($a, 'preferences'))->json('data.entries.0.after')));
        $this->getJson($this->url($a).'&access='.$b['grant_refs']['business.context.read'])->assertForbidden();
        $this->getJson($base.'/history?access='.$a['grant_refs']['business.context.manage'].'&kind=context')->assertForbidden();
        $this->getJson($base.'/history?access='.$a['grant_refs']['business.context.read'].'&kind=object_purpose')->assertUnprocessable();
        $this->getJson($base.'/history?access='.$a['grant_refs']['business.context.read'].'&kind=context&after=-1')->assertUnprocessable();
        $this->assertSame('no-store, private', $this->getJson($this->url($a))->headers->get('Cache-Control'));
        $this->actingAs(User::factory()->create())->getJson($this->url($a))->assertForbidden();
        try {
            DB::table('business_context_transitions')->get();
            $this->fail('Unqualified raw history was readable');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->getCode());
        }
    }

    public function test_history_pages_by_owner_revision_and_noop_cannot_fabricate_an_event(): void
    {
        $this->actingAs(User::factory()->create());
        $a = $this->draft('Baseline activity');
        for ($version = 0; $version < 34; $version++) {
            $this->rename($a, $version, 'Reviewed name '.($version + 1));
        }
        $this->rename($a, 0, 'Reviewed name 34'); // Qualified equal no-op, not a new declaration.
        $page = $this->getJson($this->url($a))->assertOk()->assertJsonCount(32, 'data.entries')->assertJsonPath('data.next_cursor', '32');
        $this->assertSame(array_map(strval(...), range(1, 32)), array_column($page->json('data.entries'), 'version'));
        $this->getJson($this->url($a, 'context', 32))->assertOk()->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('data.entries.0.version', '33')->assertJsonPath('data.entries.1.version', '34')->assertJsonPath('data.next_cursor', null);
        $this->assertSame(34, DB::connection('pgsql_migrations')->table('business_context_transitions')->count());
    }

    public function test_withdrawal_during_real_business_fence_wait_denies_history_without_early_identity_locks(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $a = $this->draft('Confidential historical name');
        $this->rename($a, 0, 'Current name');
        $db = DB::connection('pgsql_migrations');
        $businessId = $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        $q = $db->table('security_action_grants')->where('id', $a['grant_refs']['business.context.read'])->value('qualification_id');
        $request = Request::create('/api/businesses');
        $request->setLaravelSession(app('session')->driver());
        $run = 'history-withdraw-'.Str::uuid();
        if (! is_dir(storage_path('framework/testing'))) {
            mkdir(storage_path('framework/testing'), 0777, true);
        }
        $file = storage_path('framework/testing/'.$run.'.json');
        $release = $file.'.release';
        file_put_contents($file, json_encode(['actor' => $actor->id, 'session' => SessionAuthority::sessionKey($request), 'run' => $run, 'release' => $release,
            'operation' => 'history', 'business' => $a['business_public_id'], 'read_grant' => $a['grant_refs']['business.context.read']], JSON_THROW_ON_ERROR));
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
            throw new \RuntimeException('Actual history read contention was not observed');
        };
        try {
            $worker->start();
            $wait(fn () => str_contains($worker->getOutput(), 'READY_PID='));
            $db->beginTransaction();
            $blocker = (int) $db->scalar('SELECT pg_backend_pid()');
            $db->select('SELECT business_id FROM business_authority_fences WHERE business_id=? FOR UPDATE', [$businessId]);
            file_put_contents($release, 'go');
            $evidence['wait'] = $wait(fn () => DB::selectOne('SELECT pid,wait_event_type,wait_event,pg_blocking_pids(pid)::text blockers FROM pg_stat_activity WHERE application_name=? AND ?=ANY(pg_blocking_pids(pid))', [$run, $blocker]));
            $db->transaction(fn () => $db->select('SELECT user_id FROM principal_lifecycles WHERE user_id=? FOR UPDATE NOWAIT', [$actor->id]));
            $evidence['identity_available_at_business_fence'] = true;
            BusinessAuthorityFixture::revokeQualification($q, $actor->id);
            $db->commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertStringNotContainsString('Confidential historical name', $worker->getOutput());
            $this->getJson($this->url($a))->assertForbidden();
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
}
