<?php

namespace Tests\Feature;

use App\Business\BusinessContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Models\Workspace;
use App\Security\GrantAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CurrentIntentDatabaseTest extends TestCase
{
    private function writeIntent(object $workspace, ?array $value, int $version, ?array $confirmations = null): int
    {
        $request = Request::create('/', 'POST');
        $request->setLaravelSession($this->app['session']->driver());
        $request->setUserResolver(fn () => auth()->user());

        return app(WorkspaceContext::class)->runBusinessMutation(auth()->user(), $workspace->public_id,
            fn (Workspace $active) => app(BusinessContext::class)->replaceCurrentIntent($request, $active, $value,
                $confirmations ?? ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true,
                    'clarification_required' => $value !== null && $value['outcome']['clarification'] !== null, 'quantity' => true, 'horizon' => true], $version), $request);
    }

    private function snapshot(object $workspace): array
    {
        $db = DB::connection('pgsql_migrations');

        return [(array) $db->table('workspaces')->find($workspace->id),
            $db->table('business_current_intent_events')->where('workspace_id', $workspace->id)->orderBy('after_version')->get()->toArray()];
    }

    public function test_timezone_upgrade_is_repeatable_and_preserves_function_authority_and_declarations(): void
    {
        $workspace = $this->business();
        $value = $this->value();
        $value['horizon']['timezone'] = 'UTC';
        $this->assertSame(1, $this->writeIntent($workspace, $value, 0));
        $before = $this->snapshot($workspace);
        $owner = DB::connection('pgsql_migrations');
        $owner->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION builderad_current_intent_timezone_supported(p_zone text) RETURNS boolean
            LANGUAGE sql STABLE SET search_path=public,pg_temp AS $$
             SELECT builderad_current_intent_timezone_name_allowed(p_zone)
              AND EXISTS (SELECT 1 FROM pg_timezone_names WHERE name=p_zone)
            $$;
            SQL);
        $metadataSql = "SELECT oid,proowner,proacl::text,prosecdef FROM pg_proc
            WHERE oid='builderad_current_intent_timezone_supported(text)'::regprocedure";
        $metadata = $owner->selectOne($metadataSql);
        $entry = $owner->scalar("SELECT pg_get_functiondef('builderad_business_context_begin(bigint,text)'::regprocedure)");
        $connection = DB::getDefaultConnection();
        try {
            DB::setDefaultConnection('pgsql_migrations');
            $migration = require database_path('migrations/0001_01_01_000370_optimize_current_intent_timezone_lookup.php');
            $owner->transaction(function () use ($migration): void {
                $migration->up();
                $migration->up();
            });
        } finally {
            DB::setDefaultConnection($connection);
        }
        $this->assertEquals($metadata, $owner->selectOne($metadataSql));
        $this->assertSame($entry, $owner->scalar("SELECT pg_get_functiondef('builderad_business_context_begin(bigint,text)'::regprocedure)"));
        $this->assertEquals($before, $this->snapshot($workspace));
        $this->assertStringNotContainsString('pg_timezone_names', $owner->scalar("SELECT prosrc FROM pg_proc WHERE oid='builderad_current_intent_timezone_supported(text)'::regprocedure"));
        $this->assertTrue((bool) DB::scalar("SELECT builderad_current_intent_timezone_supported('UTC')"));
        $this->assertFalse((bool) DB::scalar("SELECT builderad_current_intent_timezone_supported('UTC/0')"));
        $this->assertSame(1, $this->writeIntent($workspace, $value, 0));
        $this->assertEquals($before, $this->snapshot($workspace));
    }

    public function test_atomic_full_sequence_noop_stale_and_complete_audit(): void
    {
        $workspace = $this->business();
        $a = $this->value();
        $a['horizon']['timezone'] = 'UTC';
        $this->assertSame(1, $this->writeIntent($workspace, $a, 0));
        $before = $this->snapshot($workspace);
        $this->assertSame(1, $this->writeIntent($workspace, $a, 0));
        $this->assertEquals($before, $this->snapshot($workspace));
        $b = $a;
        $b['direction'] = 'preserve_value';
        $b['outcome'] = ['meaning' => 'Accepted bookings', 'clarification' => 'Deposit paid', 'count_unit' => 'paid booking'];
        $b['quantity'] = '999999999999999999999999';
        $this->denied(fn () => $this->writeIntent($workspace, $b, 0), '40001');
        $this->assertEquals($before, $this->snapshot($workspace));
        $this->assertSame(2, $this->writeIntent($workspace, $b, 1));
        $this->assertSame(3, $this->writeIntent($workspace, $a, 2));
        $this->assertSame(4, $this->writeIntent($workspace, null, 3, ['declaration' => true]));
        $before = $this->snapshot($workspace);
        $this->assertSame(4, $this->writeIntent($workspace, null, 0, ['declaration' => true]));
        $this->assertEquals($before, $this->snapshot($workspace));
        $this->assertSame(5, $this->writeIntent($workspace, $a, 4));
        $events = $this->snapshot($workspace)[1];
        $this->assertCount(5, $events);
        foreach ($events as $i => $event) {
            $this->assertSame($i, (int) $event->before_version);
            $this->assertSame($i + 1, (int) $event->after_version);
            $this->assertSame((int) auth()->id(), (int) $event->actor_user_id);
            $this->assertNotEmpty($event->occurred_at);
            $this->assertEquals([null, $a, $b, $a, null][$i], $event->before_value === null ? null : json_decode($event->before_value, true));
            $this->assertEquals([$a, $b, $a, null, $a][$i], $event->after_value === null ? null : json_decode($event->after_value, true));
        }
    }

    public function test_independent_confirmations_and_runtime_horizon_rejections_are_atomic(): void
    {
        $workspace = $this->business();
        $value = $this->value();
        $value['horizon']['timezone'] = 'UTC';
        $flags = ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true, 'quantity' => true, 'horizon' => true];
        $before = $this->snapshot($workspace);
        foreach (['declaration', 'business_wide', 'outcome', 'acceptance_clear', 'quantity', 'horizon'] as $key) {
            $bad = $flags;
            unset($bad[$key]);
            $this->denied(fn () => $this->writeIntent($workspace, $value, 0, $bad));
            $this->assertEquals($before, $this->snapshot($workspace));
        }
        foreach ([['unknown' => true], ['declaration' => 'true'], ['clarification_required' => true]] as $extra) {
            $this->denied(fn () => $this->writeIntent($workspace, $value, 0, array_replace($flags, $extra)));
            $this->assertEquals($before, $this->snapshot($workspace));
        }
        foreach (['Some/PreviouslyAcceptedAlias', 'PST', 'posix/America/New_York', 'right/UTC', '+03:00'] as $zone) {
            $bad = $value;
            $bad['horizon']['timezone'] = $zone;
            $this->denied(fn () => $this->writeIntent($workspace, $bad, 0));
            $this->assertEquals($before, $this->snapshot($workspace));
        }
        $bad = $value;
        $bad['horizon'] = ['deadline_date' => '2011-12-30', 'timezone' => 'Pacific/Apia'];
        $this->denied(fn () => $this->writeIntent($workspace, $bad, 0));
        $this->assertEquals($before, $this->snapshot($workspace));
        $this->assertSame(1, $this->writeIntent($workspace, $value, 0));
        $changed = $value;
        $changed['outcome']['meaning'] = 'Different desired result';
        $badFlags = $flags;
        unset($badFlags['quantity']);
        $this->denied(fn () => $this->writeIntent($workspace, $changed, 1, $badFlags));
        $this->denied(fn () => $this->writeIntent($workspace, null, 1, $flags));
        $oversize = $value;
        $oversize['description'] = str_repeat(' ', 2001);
        $before = $this->snapshot($workspace);
        $this->denied(fn () => $this->writeIntent($workspace, $oversize, 1));
        $this->denied(fn () => $this->writeIntent($workspace, $value, 1, [...$flags, 'flood' => str_repeat('x', 81921)]));
        $this->assertEquals($before, $this->snapshot($workspace));
    }

    public function test_supported_horizon_loss_carries_only_exact_serialized_current_pair(): void
    {
        $workspace = $this->business();
        $value = $this->value();
        $value['horizon'] = ['deadline_date' => '2026-10-05', 'timezone' => 'Europe/Istanbul'];
        $this->assertSame(1, $this->writeIntent($workspace, $value, 0));
        $db = DB::connection('pgsql_migrations');
        $definition = $db->scalar("SELECT pg_get_functiondef('builderad_current_intent_timezone_supported(text)'::regprocedure)");
        try {
            $db->unprepared("CREATE OR REPLACE FUNCTION builderad_current_intent_timezone_supported(p_zone text) RETURNS boolean LANGUAGE sql STABLE SET search_path=public,pg_temp AS \$\$ SELECT p_zone='UTC' \$\$");
            $before = $this->snapshot($workspace);
            $this->assertSame(1, $this->writeIntent($workspace, $value, 0, ['declaration' => true, 'business_wide' => true]));
            $this->assertEquals($before, $this->snapshot($workspace));
            $quantity = $value;
            $quantity['quantity'] = '101';
            $this->assertSame(2, $this->writeIntent($workspace, $quantity, 1, ['declaration' => true, 'business_wide' => true, 'quantity' => true]));
            $direction = $quantity;
            $direction['direction'] = 'preserve_value';
            $this->assertSame(3, $this->writeIntent($workspace, $direction, 2, ['declaration' => true, 'business_wide' => true]));
            $before = $this->snapshot($workspace);
            $this->denied(fn () => $this->writeIntent($workspace, $value, 0), '40001');
            $changed = $direction;
            $changed['horizon']['deadline_date'] = '2026-10-06';
            $this->denied(fn () => $this->writeIntent($workspace, $changed, 3));
            $changed['horizon']['timezone'] = 'Some/Unsupported';
            $this->denied(fn () => $this->writeIntent($workspace, $changed, 3));
            $this->assertEquals($before, $this->snapshot($workspace));
            $removed = $direction;
            $removed['horizon'] = null;
            $this->assertSame(4, $this->writeIntent($workspace, $removed, 3));
            $before = $this->snapshot($workspace);
            $this->denied(fn () => $this->writeIntent($workspace, $direction, 4));
            $this->assertEquals($before, $this->snapshot($workspace));
            $changed['horizon']['timezone'] = 'UTC';
            $this->assertSame(5, $this->writeIntent($workspace, $changed, 4));
            $this->assertSame(6, $this->writeIntent($workspace, null, 5, ['declaration' => true]));
            $before = $this->snapshot($workspace);
            $this->denied(fn () => $this->writeIntent($workspace, $value, 6));
            $this->assertEquals($before, $this->snapshot($workspace));
            $this->assertSame('Europe/Istanbul', json_decode($before[1][0]->after_value, true)['horizon']['timezone']);
        } finally {
            $db->unprepared($definition);
        }
    }

    public function test_runtime_writer_requires_prepared_scope_and_authority_even_for_equal_input(): void
    {
        $workspace = $this->business();
        $value = $this->value();
        $value['horizon']['timezone'] = 'UTC';
        $this->denied(fn () => DB::scalar('SELECT builderad_current_intent_write(?,?,?::jsonb,?::jsonb,?)',
            [$workspace->id, 'not-a-session', json_encode($value), '{"declaration":true}', 0]), '42501');
        $this->assertSame(1, $this->writeIntent($workspace, $value, 0));
        $db = DB::connection('pgsql_migrations');
        $writer = $db->selectOne("SELECT p.prosecdef,p.provolatile,r.rolname AS owner,p.proconfig FROM pg_proc p JOIN pg_roles r ON r.oid=p.proowner WHERE p.oid='builderad_current_intent_write(bigint,text,jsonb,jsonb,bigint)'::regprocedure");
        $this->assertTrue($writer->prosecdef);
        $this->assertSame('v', $writer->provolatile);
        $this->assertSame('builderad_owner', $writer->owner);
        $this->assertStringContainsString('search_path=public, pg_temp', $writer->proconfig);
        $this->assertTrue((bool) $db->scalar("SELECT has_function_privilege('builderad_app','builderad_current_intent_write(bigint,text,jsonb,jsonb,bigint)','EXECUTE')"));
        $this->assertFalse((bool) $db->scalar("SELECT has_function_privilege('builderad_authority','builderad_current_intent_write(bigint,text,jsonb,jsonb,bigint)','EXECUTE')"));
        $before = $this->snapshot($workspace);
        $db->table('security_action_grants')->where('workspace_id', $workspace->id)->where('action', 'business.context.manage')
            ->update(['status' => 'revoked', 'revoked_at' => DB::raw('clock_timestamp()')]);
        $this->denied(fn () => $this->writeIntent($workspace, $value, 0), '42501');
        $this->denied(fn () => $this->writeIntent($workspace, null, 1, ['declaration' => true]), '42501');
        $this->assertEquals($before, $this->snapshot($workspace));
    }

    private function ordered(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($item) => $this->ordered($item), $value);
    }

    private function value(): array
    {
        return $this->ordered(['direction' => 'increase_value', 'scope' => 'business_wide', 'description' => null,
            'outcome' => ['meaning' => 'Bookings', 'clarification' => null, 'count_unit' => 'booking'],
            'quantity' => '100', 'horizon' => ['deadline_date' => '2030-05-01', 'timezone' => 'Some/PreviouslyAcceptedAlias']]);
    }

    private function normalize(mixed $value): mixed
    {
        $result = DB::connection('pgsql_migrations')->scalar('SELECT builderad_current_intent_normalize(?::jsonb)', [json_encode($value, JSON_THROW_ON_ERROR)]);

        return $result === null ? null : $this->ordered(json_decode($result, true, flags: JSON_THROW_ON_ERROR));
    }

    private function denied(callable $operation, string $state = '22023'): void
    {
        try {
            $operation();
            $this->fail('Invalid database operation unexpectedly succeeded.');
        } catch (QueryException $error) {
            $this->assertSame($state, $error->getCode());
        }
    }

    private function business(): object
    {
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Intent fixture', 'slug' => 'intent-fixture'])->assertRedirect();

        return DB::connection('pgsql_migrations')->table('workspaces')->where('slug', 'intent-fixture')->first();
    }

    public function test_absence_and_static_validator_without_environmental_dependencies(): void
    {
        $workspace = $this->business();
        $this->assertNull($workspace->current_intent);
        $owner = DB::connection('pgsql_migrations');
        $this->assertNull($owner->scalar('SELECT builderad_current_intent_normalize(NULL::jsonb)'));
        $this->assertSame($this->value(), $this->normalize($this->value()));
        $function = $owner->selectOne("SELECT provolatile,pg_get_functiondef(oid) AS definition FROM pg_proc WHERE oid='builderad_current_intent_normalize(jsonb)'::regprocedure");
        $this->assertSame('i', $function->provolatile);
        foreach (['pg_timezone', 'AT TIME ZONE', 'current_setting', 'to_date', 'clock_timestamp'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $function->definition);
        }
        foreach (['UTC', 'Pacific/Apia'] as $zone) {
            $owner->statement("SET TIME ZONE '".$zone."'");
            foreach (['ISO, MDY', 'SQL, DMY'] as $style) {
                $owner->statement("SET DateStyle TO '".$style."'");
                $this->assertSame($this->value(), $this->normalize($this->value()));
            }
        }
        $owner->statement("SET TIME ZONE 'UTC'");
        $owner->statement("SET DateStyle TO 'ISO, MDY'");
    }

    public function test_exact_shape_types_relationships_and_conservative_normalization(): void
    {
        foreach ([null, [], (object) [], true, 'text', ['direction' => 'increase_value']] as $bad) {
            $this->denied(fn () => $this->normalize($bad));
        }
        foreach (['increase_value', 'preserve_value', 'improve_efficiency_preserving_value', 'validate_business_direction'] as $direction) {
            $value = $this->value();
            $value['direction'] = $direction;
            $this->assertSame($value, $this->normalize($value));
        }
        $value = $this->value();
        $value['description'] = " \t Description  stays\nverbatim \r\n";
        $value['quantity'] = '000100';
        $normalized = $this->normalize($value);
        $this->assertSame("Description  stays\nverbatim", $normalized['description']);
        $this->assertSame('100', $normalized['quantity']);
        foreach (['subject_id' => 1, 'budget' => '5000 SAR', 'provider' => 'google', 'status' => 'active'] as $key => $data) {
            $this->denied(fn () => $this->normalize([...$this->value(), $key => $data]));
        }
        foreach (['scope' => 'market', 'direction' => 'conversion', 'quantity' => 100, 'horizon' => [], 'outcome' => []] as $key => $data) {
            $this->denied(fn () => $this->normalize([...$this->value(), $key => $data]));
        }
        foreach (['0', '-1', '1.2', '1e5', '', '   100'] as $quantity) {
            $this->denied(fn () => $this->normalize([...$this->value(), 'quantity' => $quantity]));
        }
        $value = $this->value();
        $value['outcome']['count_unit'] = null;
        $this->denied(fn () => $this->normalize($value));
        $value['quantity'] = null;
        $value['horizon'] = null;
        $this->assertSame($value, $this->normalize($value));
    }

    public function test_static_calendar_lexical_validation_only(): void
    {
        foreach (['0001-01-01', '2000-02-29', '2024-02-29', '2011-12-30', '9999-12-31'] as $date) {
            $value = $this->value();
            $value['horizon'] = ['deadline_date' => $date, 'timezone' => 'Pacific/Apia'];
            $this->assertSame($value, $this->normalize($value));
        }
        foreach (['0000-01-01', '1900-02-29', '2026-02-29', '2026-04-31', '2030-13-01', '2030-00-01', '2030-05-00', '30 days', '2030-5-01', '2030-05-01 '] as $date) {
            $value = $this->value();
            $value['horizon']['deadline_date'] = $date;
            $this->denied(fn () => $this->normalize($value));
        }
    }

    public function test_all_technical_boundaries_are_exact_without_truncation(): void
    {
        foreach (['description' => 2000, 'outcome.meaning' => 2000, 'outcome.clarification' => 2000, 'outcome.count_unit' => 120] as $path => $limit) {
            foreach (['ع', '😀', "\u{0301}", "\u{0001}"] as $character) {
                $value = $this->value();
                data_set($value, $path, str_repeat($character, $limit));
                $this->assertSame($value, $this->normalize($value));
                data_set($value, $path, str_repeat($character, $limit + 1));
                $this->denied(fn () => $this->normalize($value));
            }
        }
        $value = $this->value();
        $value['quantity'] = str_repeat('9', 120);
        $value['horizon']['timezone'] = 'A/'.str_repeat('b', 118);
        $this->assertSame($value, $this->normalize($value));
        $this->denied(fn () => $this->normalize([...$value, 'quantity' => str_repeat('9', 121)]));
        $this->denied(fn () => $this->normalize([...$value, 'quantity' => str_repeat('0', 120).'1']));
        $this->denied(fn () => $this->normalize([...$value, 'description' => str_repeat(' ', 2001)]));
        $value['horizon']['timezone'] .= 'b';
        $this->denied(fn () => $this->normalize($value));
        $value = $this->value();
        foreach (['description', 'outcome.meaning', 'outcome.clarification'] as $path) {
            data_set($value, $path, str_repeat("\u{0001}", 2000));
        }
        $value['outcome']['count_unit'] = str_repeat("\u{0001}", 120);
        $value['quantity'] = str_repeat('9', 120);
        $this->assertSame($value, $this->normalize($value));
        $this->assertLessThanOrEqual(40960, (int) DB::connection('pgsql_migrations')->scalar('SELECT octet_length(?::jsonb::text)', [json_encode($value, JSON_THROW_ON_ERROR)]));
        $this->denied(fn () => $this->normalize([...$value, 'description' => str_repeat('x', 40961)]));
    }

    public function test_constraints_and_local_journal_immutability(): void
    {
        $workspace = $this->business();
        $owner = DB::connection('pgsql_migrations');
        $value = $this->value();
        $owner->table('workspaces')->where('id', $workspace->id)->update(['current_intent' => json_encode($value, JSON_THROW_ON_ERROR)]);
        $this->assertSame($value, $this->ordered(json_decode($owner->table('workspaces')->where('id', $workspace->id)->value('current_intent'), true)));
        $this->denied(fn () => $owner->table('workspaces')->where('id', $workspace->id)->update(['current_intent' => '{}']));
        $actor = $owner->table('workspace_ownerships')->where('workspace_id', $workspace->id)->value('responsible_owner_user_id');
        $owner->table('business_current_intent_events')->insert(['workspace_id' => $workspace->id, 'actor_user_id' => $actor,
            'before_version' => 0, 'after_version' => 1, 'before_value' => null, 'after_value' => json_encode($value, JSON_THROW_ON_ERROR)]);
        foreach (['UPDATE business_current_intent_events SET before_version=1', 'DELETE FROM business_current_intent_events'] as $sql) {
            try {
                $owner->statement($sql);
                $this->fail('Journal rewrite succeeded.');
            } catch (QueryException $error) {
                $this->assertSame('23514', $error->getCode());
                $this->assertStringContainsString('Current Intent journal is immutable', $error->getMessage());
                $this->assertStringNotContainsString('SA-01', $error->getMessage());
            }
        }
        $this->denied(fn () => $owner->table('workspaces')->where('id', $workspace->id)->delete(), '23001');
        $this->denied(fn () => $owner->table('users')->where('id', $actor)->delete(), '23001');
        $this->assertSame(1, $owner->table('business_current_intent_events')->count());
    }
}
