<?php

namespace Tests\Feature;

use App\Business\BusinessContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Models\Workspace;
use App\Security\GrantAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CurrentIntentTest extends TestCase
{
    private function command(?array $value, int $version = 0): array
    {
        return ['command' => $value === null ? 'clear' : 'replace', 'value' => $value, 'version' => $version,
            'confirmations' => $value === null ? ['declaration' => true] : ['declaration' => true, 'business_wide' => true,
                'outcome' => true, 'acceptance_clear' => true, 'clarification_required' => $value['outcome']['clarification'] !== null,
                'quantity' => true, 'horizon' => true]];
    }

    private function url(object $workspace): string
    {
        return '/workspaces/'.$workspace->public_id.'/business/current-intent';
    }

    private function raw(object $workspace, string $body)
    {
        return $this->call('POST', $this->url($workspace), [], $this->prepareCookiesForJsonRequest(), [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
    }

    private function stored(object $workspace): array
    {
        $db = DB::connection('pgsql_migrations');

        return [(array) $db->table('workspaces')->find($workspace->id), $db->table('business_current_intent_events')->get()->toArray()];
    }

    public function test_http_complete_create_replace_equal_stale_clear_and_no_partial_effects(): void
    {
        $workspace = $this->business();
        $url = $this->url($workspace);
        $value = $this->value();
        $this->postJson($url, $this->command($value))->assertRedirect('/workspaces/'.$workspace->public_id.'/business');
        $before = $this->stored($workspace);
        $this->postJson($url, $this->command($value))->assertRedirect();
        $this->assertEquals($before, $this->stored($workspace));
        $next = $value;
        $next['outcome']['meaning'] = 'Changed desired result';
        foreach (['ar', 'en'] as $locale) {
            $this->withSession(['locale' => $locale])->postJson($url, $this->command($next))->assertUnprocessable()->assertJsonValidationErrors('version');
            $this->assertEquals($before, $this->stored($workspace));
        }
        $this->postJson($url, $this->command($next, 1))->assertRedirect();
        $this->projection($workspace, 2, $next);
        $this->postJson($url, $this->command(null, 2))->assertRedirect();
        $this->projection($workspace, 3, null);
        $this->postJson($url, $this->command(null, 0))->assertRedirect();
        $this->assertSame(3, count($this->stored($workspace)[1]));
    }

    public function test_http_raw_shape_types_and_forged_fields_rejected_without_effects(): void
    {
        $workspace = $this->business();
        $valid = $this->command($this->value());
        $before = $this->stored($workspace);
        foreach (['[]', '{}', 'null', '{invalid', str_replace('"outcome":{', '"outcome":[', json_encode($valid))] as $body) {
            $this->raw($workspace, $body)->assertUnprocessable();
        }
        $this->withHeader('Accept', 'application/json')->post($this->url($workspace), $valid)->assertUnprocessable();
        $bad = [];
        foreach (['actor_user_id', 'provider', 'status', 'business_context_prepared', 'same_meaning', 'horizon_carryover'] as $key) {
            $bad[] = [...$valid, $key => true];
            $candidate = $valid;
            $candidate['value'][$key] = true;
            $bad[] = $candidate;
        }
        foreach (['outcome', 'horizon'] as $key) {
            $candidate = $valid;
            $candidate['value'][$key] = [];
            $bad[] = $candidate;
            $candidate['value'][$key] = (object) [];
            $bad[] = $candidate;
        }
        foreach (['description', 'quantity', 'horizon'] as $key) {
            $candidate = $valid;
            unset($candidate['value'][$key]);
            $bad[] = $candidate;
        }
        foreach ([100, true, '0', '-1', '1.5', '1e4', str_repeat('0', 121).'1'] as $quantity) {
            $candidate = $valid;
            $candidate['value']['quantity'] = $quantity;
            $bad[] = $candidate;
        }
        foreach (['market', 'product', 'service', 'project'] as $scope) {
            $candidate = $valid;
            $candidate['value']['scope'] = $scope;
            $bad[] = $candidate;
        }
        foreach ([[], ['declaration' => 'true'], ['declaration' => 1], ['declaration' => true, 'bypass' => true]] as $flags) {
            $bad[] = [...$valid, 'confirmations' => $flags];
        }
        foreach ([null, -1, 1.5, '0'] as $version) {
            $bad[] = [...$valid, 'version' => $version];
        }
        $bad[] = [...$valid, 'command' => 'clear'];
        $bad[] = [...$valid, 'command' => 'patch'];
        $bad[] = [...$valid, 'value' => null];
        foreach ($bad as $command) {
            $this->raw($workspace, json_encode($command, JSON_THROW_ON_ERROR))->assertUnprocessable();
            $this->assertEquals($before, $this->stored($workspace));
        }
    }

    public function test_http_codepoint_digit_and_raw_transport_boundaries_never_truncate(): void
    {
        $workspace = $this->business();
        $value = $this->value();
        foreach (['description', 'meaning', 'clarification'] as $field) {
            if ($field === 'description') {
                $value[$field] = str_repeat('😀', 2000);
            } else {
                $value['outcome'][$field] = str_repeat('😀', 2000);
            }
        }
        $value['outcome']['count_unit'] = str_repeat('😀', 120);
        $value['quantity'] = str_repeat('9', 120);
        $command = $this->command($value);
        $json = json_encode($command, JSON_THROW_ON_ERROR); // Escaped non-BMP surrogate pairs.
        $this->assertLessThanOrEqual(81920, strlen($json));
        $body = $json.str_repeat(' ', 81920 - strlen($json));
        $this->assertSame(81920, strlen($body));
        $this->raw($workspace, $body)->assertRedirect();
        $this->projection($workspace, 1, $value);
        $before = $this->stored($workspace);
        $this->raw($workspace, $body.' ')->assertStatus(413);
        $this->assertEquals($before, $this->stored($workspace));
        foreach (['description', 'meaning', 'clarification', 'count_unit', 'quantity', 'timezone'] as $field) {
            $bad = $value;
            if ($field === 'description') {
                $bad[$field] .= '😀';
            } elseif ($field === 'quantity') {
                $bad[$field] .= '9';
            } elseif ($field === 'timezone') {
                $bad['horizon'][$field] = 'Zone/'.str_repeat('A', 116);
            } else {
                $bad['outcome'][$field] .= '😀';
            }
            $this->postJson($this->url($workspace), $this->command($bad, 1))->assertUnprocessable();
            $this->assertEquals($before, $this->stored($workspace));
        }
        $bad = $this->value();
        $bad['description'] = str_repeat(' ', 2001);
        $this->postJson($this->url($workspace), $this->command($bad, 1))->assertUnprocessable();
        $this->assertEquals($before, $this->stored($workspace));
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
            $this->postJson($this->url($workspace), $this->command($value, $i))->assertRedirect();
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
        $this->postJson($this->url($workspace), $this->command($value))->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->assertSame(0, (int) DB::connection('pgsql_migrations')->table('workspaces')->where('id', $workspace->id)->value('business_context_version'));
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('business_current_intent_events')->count());
        $value['horizon']['deadline_date'] = '2011-12-31';
        $this->postJson($this->url($workspace), $this->command($value))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, (int) DB::connection('pgsql_migrations')->table('workspaces')->where('id', $workspace->id)->value('business_context_version'));
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('business_current_intent_events')->count());
    }

    public function test_http_unchanged_horizon_carryover_survives_option_loss_but_new_pair_is_denied(): void
    {
        $workspace = $this->business();
        $value = $this->value();
        $this->postJson($this->url($workspace), $this->command($value))->assertRedirect();
        $db = DB::connection('pgsql_migrations');
        $definition = $db->scalar("SELECT pg_get_functiondef('builderad_current_intent_timezone_name_allowed(text)'::regprocedure)");
        try {
            $db->unprepared("CREATE OR REPLACE FUNCTION builderad_current_intent_timezone_name_allowed(p_zone text) RETURNS boolean LANGUAGE sql IMMUTABLE SET search_path=public,pg_temp AS \$\$ SELECT p_zone='UTC' \$\$");
            $before = $this->stored($workspace);
            $this->postJson($this->url($workspace), $this->command($value, 0))->assertRedirect();
            $this->assertEquals($before, $this->stored($workspace));
            $value['quantity'] = '999';
            $this->postJson($this->url($workspace), $this->command($value, 1))->assertRedirect();
            $value['direction'] = 'preserve_value';
            $this->postJson($this->url($workspace), $this->command($value, 2))->assertRedirect();
            $this->get('/workspaces/'.$workspace->public_id.'/business')->assertInertia(fn (Assert $page) => $page
                ->where('timezoneOptions', ['UTC'])->where('business.currentIntent.value.horizon.timezone', 'Europe/Istanbul')
                ->where('business.currentIntent.value.horizon.deadline_date', '2026-10-05')->where('business.version', 3));
            $before = $this->stored($workspace);
            $changed = $value;
            $changed['horizon']['deadline_date'] = '2026-10-06';
            $this->postJson($this->url($workspace), $this->command($changed, 3))->assertUnprocessable()->assertJsonValidationErrors('value');
            $this->assertEquals($before, $this->stored($workspace));
        } finally {
            $db->unprepared($definition);
        }
    }

    public function test_unrepresentable_postgresql_text_is_validation_error_without_effects(): void
    {
        $workspace = $this->business();
        $before = $this->stored($workspace);
        $value = $this->value();
        $value['description'] = "Retain\0this complete input";
        $this->postJson($this->url($workspace), $this->command($value))->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->assertEquals($before, $this->stored($workspace));
    }

    private function business(): object
    {
        $this->withCredentials();
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Intent projection', 'slug' => 'intent-projection'])->assertRedirect();

        return DB::connection('pgsql_migrations')->table('workspaces')->where('slug', 'intent-projection')->first();
    }

    private function request(): Request
    {
        $request = Request::create('/', 'GET');
        $request->setLaravelSession($this->app['session']->driver());
        $request->setUserResolver(fn () => auth()->user());

        return $request;
    }

    private function value(): array
    {
        return ['direction' => 'validate_business_direction', 'scope' => 'business_wide', 'description' => 'Specific direction',
            'outcome' => ['meaning' => 'Accepted reservations', 'clarification' => 'Deposit received', 'count_unit' => 'reservation'],
            'quantity' => '12345678901234567890', 'horizon' => ['deadline_date' => '2026-10-05', 'timezone' => 'Europe/Istanbul']];
    }

    private function write(object $workspace, ?array $value, int $version): int
    {
        $request = $this->request();
        $flags = $value === null ? ['declaration' => true] : ['declaration' => true, 'business_wide' => true,
            'outcome' => true, 'acceptance_clear' => true, 'clarification_required' => true, 'quantity' => true, 'horizon' => true];

        return app(WorkspaceContext::class)->runBusinessMutation(auth()->user(), $workspace->public_id,
            fn ($active) => app(BusinessContext::class)->replaceCurrentIntent($request, $active, $value, $flags, $version), $request);
    }

    private function projection(object $workspace, int $expectedVersion, ?array $expectedValue): array
    {
        $request = $this->request();

        return app(WorkspaceContext::class)->run(auth()->user(), $workspace->public_id, function (Workspace $active) use ($request, $expectedVersion, $expectedValue): array {
            $active->business_context_version = 987654; // A cached/model revision cannot override the row read.
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $projection = app(BusinessContext::class)->projection($request, $active);
                $reads = array_values(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], '"workspaces"')));
                $this->assertCount(1, $reads);
                $this->assertStringContainsString('"current_intent"', $reads[0]['query']);
                $this->assertStringContainsString('"business_context_version"', $reads[0]['query']);
            } finally {
                DB::disableQueryLog();
            }
            $this->assertSame($expectedVersion, $projection['version']);
            $this->assertSame($expectedVersion, $projection['currentIntent']['version']);
            $this->assertSame($expectedValue === null ? 'NOT_DECLARED' : 'PRESENT', $projection['currentIntent']['state']);
            $this->assertEquals($expectedValue, $projection['currentIntent']['value']);

            return $projection;
        }, $request);
    }

    public function test_projection_absence_present_clear_history_and_sources_compatibility(): void
    {
        $workspace = $this->business();
        $this->projection($workspace, 0, null);
        $value = $this->value();
        $this->assertSame(1, $this->write($workspace, $value, 0));
        $this->projection($workspace, 1, $value);
        $this->get('/workspaces/'.$workspace->public_id.'/business')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Business/Context')->where('business.currentIntent.state', 'PRESENT')->where('business.version', 1)
            ->where('business.currentIntent.version', 1)->where('version', 1)->where('workspace.business_context_version', 1));
        $type = DB::connection('pgsql_migrations')->table('business_types')->where('key', 'real_estate')->value('id');
        $this->post('/workspaces/'.$workspace->public_id.'/business', ['business_type_id' => $type, 'version' => 1])->assertRedirect();
        $this->get('/workspaces/'.$workspace->public_id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Business/Sources')->where('business.currentIntent.state', 'PRESENT')->where('business.version', 2));
        $this->assertSame(3, $this->write($workspace, null, 2));
        $this->projection($workspace, 3, null);
        $this->assertSame(2, DB::connection('pgsql_migrations')->table('business_current_intent_events')->count());
    }

    public function test_projection_keeps_accepted_current_and_history_after_timezone_support_loss(): void
    {
        $workspace = $this->business();
        $value = $this->value();
        $this->write($workspace, $value, 0);
        $db = DB::connection('pgsql_migrations');
        $definition = $db->scalar("SELECT pg_get_functiondef('builderad_current_intent_timezone_supported(text)'::regprocedure)");
        try {
            $db->unprepared('CREATE OR REPLACE FUNCTION builderad_current_intent_timezone_supported(p_zone text) RETURNS boolean LANGUAGE plpgsql STABLE SET search_path=public,pg_temp AS $$ BEGIN RAISE EXCEPTION \'Projection must not validate timezone support\'; END $$');
            $this->projection($workspace, 1, $value);
            $this->assertEquals($value, json_decode($db->table('business_current_intent_events')->value('after_value'), true));
        } finally {
            $db->unprepared($definition);
        }
    }

    public function test_explicit_fresh_followup_after_name_and_markets_shared_revisions(): void
    {
        $workspace = $this->business();
        $request = $this->request();
        $type = DB::connection('pgsql_migrations')->table('business_types')->where('key', 'real_estate')->value('id');
        app(WorkspaceContext::class)->runBusinessMutation(auth()->user(), $workspace->public_id,
            fn ($active) => app(BusinessContext::class)->write($request, $active, ['name' => 'Fresh context', 'business_type_id' => $type, 'version' => 0]), $request);
        $this->assertSame(2, $this->write($workspace, $this->value(), 1));
        app(WorkspaceContext::class)->runBusinessMutation(auth()->user(), $workspace->public_id,
            fn ($active) => app(BusinessContext::class)->replaceTargetMarkets($request, $active, ['SA'], 2), $request);
        $this->projection($workspace, 3, $this->value());
        $value = $this->value();
        $value['description'] = 'Fresh explicit follow-up';
        $this->assertSame(4, $this->write($workspace, $value, 3));
        $this->projection($workspace, 4, $value);
        $events = DB::connection('pgsql_migrations')->table('business_current_intent_events')->orderBy('after_version')->get();
        $this->assertSame([1, 3], $events->pluck('before_version')->all());
        $this->assertSame([2, 4], $events->pluck('after_version')->all());
    }
}
