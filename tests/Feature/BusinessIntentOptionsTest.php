<?php

namespace Tests\Feature;

use App\Business\BusinessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\BusinessIntentOptionsTestCase;

final class BusinessIntentOptionsTest extends BusinessIntentOptionsTestCase
{
    public function test_support_loss_preserves_the_exact_accepted_pair_and_projection_but_rejects_a_new_pair(): void
    {
        $business = $this->business();
        $value = $this->value();
        $value['horizon'] = ['deadline_date' => '2026-10-05', 'timezone' => 'Europe/Istanbul'];
        $this->putJson($this->url($business), $this->command($value))->assertOk();
        $db = DB::connection('pgsql_migrations');
        $definition = $db->scalar("SELECT pg_get_functiondef('builderad_current_intent_timezone_supported(text)'::regprocedure)");
        try {
            $db->unprepared("CREATE OR REPLACE FUNCTION builderad_current_intent_timezone_supported(p_zone text) RETURNS boolean LANGUAGE sql IMMUTABLE SET search_path=public,pg_temp AS \$\$ SELECT p_zone='UTC' \$\$");
            $this->putJson($this->url($business), $this->command($value, 0))->assertOk();
            $this->assertSame(1, $db->table('business_intent_transitions')->count());
            $value['direction'] = 'preserve_value';
            $this->putJson($this->url($business), $this->command($value, 1))->assertOk();
            $stored = $db->table('business_current_intents')->where('business_id', $business->id)->sole();
            $this->assertEquals($value, json_decode($stored->value, true));
            $changed = $value;
            $changed['horizon']['deadline_date'] = '2026-10-06';
            $this->putJson($this->url($business), $this->command($changed, 2))->assertUnprocessable()->assertJsonPath('error', 'invalid_business_command');
            $this->assertEquals($stored, $db->table('business_current_intents')->where('business_id', $business->id)->sole());
            $this->assertSame(2, $db->table('business_intent_transitions')->count());
        } finally {
            $db->unprepared($definition);
        }
    }

    public function test_timezone_discovery_exact_set_policy_and_actual_bounded_execution(): void
    {
        $workspace = $this->business();
        $this->assertSame('builderad_current_intent_timezone_name_allowed(text)', DB::connection('pgsql_migrations')->scalar(
            "SELECT to_regprocedure('builderad_current_intent_timezone_name_allowed(text)')::text"));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = microtime(true);
        try {
            $options = app(BusinessContext::class)->currentIntentTimezones();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $elapsed = (microtime(true) - $started) * 1000;
        $this->assertCount(1, $queries);
        $this->assertSame('15s', DB::scalar('SHOW statement_timeout'));
        $query = $queries[0];
        $plan = json_decode(DB::scalar('EXPLAIN (ANALYZE,BUFFERS,FORMAT JSON) '.$query['query'], $query['bindings']), true, flags: JSON_THROW_ON_ERROR);
        $catalogNodes = [];
        $walk = function (array $node) use (&$walk, &$catalogNodes): void {
            if (($node['Function Name'] ?? '') === 'pg_timezone_names') {
                $catalogNodes[] = $node;
            }
            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($plan[0]['Plan']);
        $this->assertCount(1, $catalogNodes, json_encode($plan));
        $this->assertSame(1, $catalogNodes[0]['Actual Loops']);
        $this->assertStringNotContainsString('builderad_current_intent_timezone_supported', $query['query']);
        $this->assertLessThan(15000, $plan[0]['Execution Time']);
        $candidates = array_values(array_unique([...\DateTimeZone::listIdentifiers(\DateTimeZone::ALL), 'UTC']));
        $catalog = DB::select('SELECT name,builderad_current_intent_timezone_name_allowed(name) AS allowed FROM pg_timezone_names');
        $supported = array_values(array_map(fn ($row) => $row->name, array_filter($catalog, fn ($row) => $row->allowed)));
        $expected = array_values(array_intersect($candidates, $supported));
        $this->assertSame([], array_values(array_diff($options, $expected)));
        $this->assertSame([], array_values(array_diff($expected, $options)));
        $this->assertSame(count(array_unique($options)), count($options));
        $sorted = $options;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $options); // Presentation order is separate from acceptance.
        $this->assertContains('UTC', $options);
        foreach (['PST', '+03:00', 'posix/America/New_York', 'right/UTC', 'Some/Unsupported'] as $invalid) {
            $this->assertNotContains($invalid, $options);
            $this->assertFalse((bool) DB::scalar('SELECT builderad_current_intent_timezone_supported(?)', [$invalid]));
        }
        $db = DB::connection('pgsql_migrations');
        $scalar = $db->scalar("SELECT prosrc FROM pg_proc WHERE oid='builderad_current_intent_timezone_supported(text)'::regprocedure");
        $this->assertStringContainsString('builderad_current_intent_timezone_name_allowed(p_zone)', $scalar);
        $this->assertStringNotContainsString('pg_timezone_names', $scalar);
        $this->assertStringContainsString('builderad_current_intent_timezone_name_allowed', $query['query']);
        $this->assertStringNotContainsString('posix', $scalar);
        $this->assertStringNotContainsString('posix', $query['query']);
        foreach (['UTC', 'Europe/Istanbul', 'America/New_York', 'Etc/GMT+3'] as $i => $zone) {
            $value = $this->value();
            $value['horizon'] = ['deadline_date' => '2026-10-05', 'timezone' => $zone];
            $this->putJson($this->url($workspace), $this->command($value, $i))->assertOk();
        }
        $evidence = ['query' => $query['query'], 'explain' => $plan, 'discoveryMilliseconds' => $elapsed,
            'candidateCount' => count($candidates), 'optionCount' => count($options), 'statementTimeout' => '15s',
            'setDifferenceBothDirections' => [], 'catalogFunctionNodes' => count($catalogNodes), 'catalogActualLoops' => 1];
        $path = storage_path('framework/testing/current-intent-execution/timezone-set-execution.json');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        fwrite(STDOUT, 'TIMEZONE_SET_EXECUTION='.$path.PHP_EOL);
    }

    public function test_single_timezone_lookup_matches_the_catalog_exactly_and_restores_caller_settings(): void
    {
        $catalog = DB::select('SELECT name,builderad_current_intent_timezone_name_allowed(name) AS allowed FROM pg_timezone_names');
        $accepted = [];
        $candidates = [];
        foreach ($catalog as $row) {
            if ($row->allowed) {
                $accepted[$row->name] = true;
            }
            array_push($candidates, $row->name, strtolower($row->name), strtoupper($row->name));
        }
        $candidates = [...array_values(array_unique([...$candidates, 'UTC', 'utc', 'Utc', 'Europe/ISTANBUL',
            'PST', '+03:00', 'UTC0', 'UTC/0', 'GMT/0', 'AAA0/BBB', 'Some/Unsupported',
            'posix/America/New_York', 'right/UTC', '../UTC', ':UTC', '', ' UTC', 'UTC ',
            'Zone/'.str_repeat('A', 116)])), null];
        $prior = DB::scalar('SHOW TimeZone');
        DB::transaction(function () use ($candidates, $accepted): void {
            DB::selectOne("SELECT set_config('TimeZone','Pacific/Auckland',true)");
            $rows = DB::select('SELECT value AS name,builderad_current_intent_timezone_supported(value) AS supported
                FROM jsonb_array_elements_text(?::jsonb)', [json_encode($candidates, JSON_THROW_ON_ERROR)]);
            $this->assertCount(count($candidates), $rows);
            foreach ($rows as $row) {
                $this->assertSame(isset($accepted[$row->name]), (bool) $row->supported, 'Timezone acceptance differs: '.var_export($row->name, true));
            }
            $this->assertSame('Pacific/Auckland', DB::scalar('SHOW TimeZone'));
            $this->assertSame('3s', DB::scalar('SHOW lock_timeout'));
            // Successful and rejected checks must also restore settings after rollback.
            DB::beginTransaction();
            DB::selectOne("SELECT set_config('TimeZone','Europe/Istanbul',true)");
            $this->assertTrue((bool) DB::scalar("SELECT builderad_current_intent_timezone_supported('UTC')"));
            $this->assertFalse((bool) DB::scalar("SELECT builderad_current_intent_timezone_supported('Some/Unsupported')"));
            $this->assertSame('Europe/Istanbul', DB::scalar('SHOW TimeZone'));
            DB::rollBack();
            $this->assertSame('Pacific/Auckland', DB::scalar('SHOW TimeZone'));
        });
        $this->assertSame($prior, DB::scalar('SHOW TimeZone'));
    }

    public function test_timezone_optimization_preserves_skipped_civil_date_rejection(): void
    {
        $workspace = $this->business();
        $value = $this->value();
        $value['horizon'] = ['deadline_date' => '2011-12-30', 'timezone' => 'Pacific/Apia'];
        $this->putJson($this->url($workspace), $this->command($value))->assertUnprocessable();
        $this->assertSame(0, (int) DB::connection('pgsql_migrations')->table('business_context_heads')->where('business_id', $workspace->id)->value('context_version'));
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('business_intent_transitions')->count());
        $value['horizon']['deadline_date'] = '2011-12-31';
        $this->putJson($this->url($workspace), $this->command($value))->assertOk()->assertSessionHasNoErrors();
        $this->assertSame(1, (int) DB::connection('pgsql_migrations')->table('business_context_heads')->where('business_id', $workspace->id)->value('context_version'));
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('business_intent_transitions')->count());
    }
}
