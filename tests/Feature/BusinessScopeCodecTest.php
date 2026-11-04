<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BusinessScopeCodecTest extends TestCase
{
    /** Translate frozen documentation notation in this test only; runtime JSON never controls SQL types. */
    private function vectorSchema(mixed $node): array
    {
        if (is_object($node)) {
            $fields = get_object_vars($node);
            foreach (['$i' => 'I', '$u' => 'U', '$t' => 'T', '$x' => 'X', '$d' => 'D'] as $key => $type) {
                if (count($fields) === 1 && array_key_exists($key, $fields)) {
                    return [$fields[$key], $type];
                }
            }
            $value = $tags = new \stdClass;
            // Separate objects: PHP object assignment otherwise aliases the schema.
            $tags = new \stdClass;
            foreach ($fields as $key => $child) {
                [$value->{$key}, $tags->{$key}] = $this->vectorSchema($child);
            }

            return [$value, $tags];
        }
        if (is_array($node)) {
            $value = $tags = [];
            foreach ($node as $child) {
                [$v, $t] = $this->vectorSchema($child);
                $value[] = $v;
                $tags[] = $t;
            }

            return [$value, $tags];
        }

        return [$node, new \stdClass];
    }

    private function executor(callable $operation): mixed
    {
        $owner = DB::connection('pgsql_migrations');
        $owner->statement('SET ROLE builderad_scope_executor');
        try {
            return $operation($owner);
        } finally {
            $owner->statement('RESET ROLE');
        }
    }

    public function test_sql_codec_matches_all_frozen_values_envelopes_and_digests(): void
    {
        $file = fopen(base_path('tests/Fixtures/business-foundation/cb1-vectors.csv'), 'r');
        $header = fgetcsv($file, escape: '');
        $count = 0;
        while (($row = fgetcsv($file, escape: '')) !== false) {
            $vector = array_combine($header, $row);
            [$value, $tags] = $this->vectorSchema(json_decode($vector['typed_input'], flags: JSON_THROW_ON_ERROR));
            $actual = $this->executor(fn ($db) => $db->selectOne(<<<'SQL'
                WITH coded AS (SELECT builderad_private.fields(?::jsonb,?::jsonb) AS value)
                SELECT encode(convert_to(value,'UTF8'),'hex') AS value_hex,
                 encode(convert_to('BuilderAd/CB1/'||?,'UTF8')||decode('00','hex')||convert_to(value,'UTF8'),'hex') AS envelope_hex,
                 encode(builderad_private.digest(?,?::jsonb,?::jsonb),'hex') AS digest FROM coded
                SQL, [json_encode($value), json_encode($tags), $vector['domain'], $vector['domain'], json_encode($value), json_encode($tags)]));
            $this->assertSame($vector['cb1_value_hex'], $actual->value_hex, $vector['id'].' value');
            $this->assertSame($vector['envelope_hex'], $actual->envelope_hex, $vector['id'].' envelope');
            $this->assertSame($vector['sha256'], $actual->digest, $vector['id'].' digest');
            $count++;
        }
        fclose($file);
        $this->assertSame(16, $count);
    }

    public function test_caller_notation_is_data_not_an_integer_authority_instruction(): void
    {
        $actual = $this->executor(fn ($db) => $db->selectOne(<<<'SQL'
            SELECT builderad_private.fields('{"$i":"0"}'::jsonb) AS literal,
             builderad_private.fields('"0"'::jsonb,'"I"'::jsonb) AS typed
            SQL));
        $this->assertNotSame($actual->literal, $actual->typed);
        $this->assertSame('I1:0', $actual->typed);
    }

    public function test_codec_rejects_oversize_string_and_excessive_depth(): void
    {
        foreach ([str_repeat('x', 65537), array_reduce(range(1, 33), fn ($v) => [$v], 'leaf')] as $input) {
            try {
                $this->executor(fn ($db) => $db->scalar("SELECT builderad_private.digest('command-payload:1',?::jsonb)", [json_encode($input)]));
                $this->fail('Oversize codec input was accepted.');
            } catch (QueryException $error) {
                $this->assertSame('22023', $error->errorInfo[0]);
            }
        }
    }

    public function test_resource_dependency_snapshot_uses_sql_uuid_and_integer_types_and_rejects_noncanonical_sets(): void
    {
        $row = ['fence_id' => '11111111-1111-7111-8111-111111111111', 'kind' => 'source',
            'source_id' => '22222222-2222-7222-8222-222222222222', 'boundary_id' => null, 'rights_epoch' => 7, 'adoption_state' => 'v2_adopted'];
        $schema = [['fence_id' => 'U', 'source_id' => 'U', 'boundary_id' => 'U', 'rights_epoch' => 'I']];
        $hash = $this->executor(fn ($db) => $db->selectOne("SELECT encode(builderad_private.resource_dependency_digest(?::jsonb),'hex') AS actual, encode(builderad_private.digest('dependency-set:1',?::jsonb,?::jsonb),'hex') AS typed, encode(builderad_private.digest('dependency-set:1',?::jsonb),'hex') AS generic",
            [json_encode([$row]), json_encode([$row]), json_encode($schema), json_encode([$row])]));
        $this->assertSame($hash->typed, $hash->actual);
        $this->assertNotSame($hash->generic, $hash->actual);
        $row['rights_epoch']++;
        $changed = $this->executor(fn ($db) => $db->scalar("SELECT encode(builderad_private.resource_dependency_digest(?::jsonb),'hex')", [json_encode([$row])]));
        $this->assertNotSame($hash->actual, $changed);
        foreach ([[$row, $row], [array_replace($row, ['caller_hash' => 'invented'])], [array_replace($row, ['fence_id' => null])]] as $invalid) {
            try {
                $this->executor(fn ($db) => $db->scalar('SELECT builderad_private.resource_dependency_digest(?::jsonb)', [json_encode($invalid)]));
                $this->fail('Unqualified resource dependency snapshot was accepted.');
            } catch (QueryException $e) {
                $this->assertSame('22023', $e->errorInfo[0]);
            }
        }
    }

    public function test_historical_v1_dependency_encoding_is_stable_with_null_extension_and_distinct_from_market_scope_encoding(): void
    {
        $legacy = ['fence_id' => '11111111-1111-7111-8111-111111111111', 'kind' => 'source',
            'source_id' => '22222222-2222-7222-8222-222222222222', 'boundary_id' => null, 'rights_epoch' => 7, 'adoption_state' => 'v2_adopted'];
        $digest = fn (array $row) => $this->executor(fn ($db) => $db->scalar("SELECT encode(builderad_private.resource_dependency_digest(?::jsonb),'hex')", [json_encode([$row])]));
        $old = $digest($legacy);
        $this->assertSame($old, $digest($legacy + ['data_use_scope_id' => null]), 'Adding the nullable market column must not reinterpret a v1 dependency.');
        $market = array_replace($legacy, ['kind' => 'data_use', 'source_id' => null,
            'data_use_scope_id' => '33333333-3333-7333-8333-333333333333']);
        $expected = $this->executor(fn ($db) => $db->scalar("SELECT encode(builderad_private.digest('dependency-set:1',?::jsonb,?::jsonb),'hex')", [
            json_encode([$market]), json_encode([['fence_id' => 'U', 'source_id' => 'U', 'boundary_id' => 'U', 'data_use_scope_id' => 'U', 'rights_epoch' => 'I']]),
        ]));
        $this->assertSame($expected, $digest($market));
        $this->assertNotSame($old, $digest($market));
        foreach ([array_diff_key($market, ['rights_epoch' => true]), $market + ['caller_hash' => 'invented']] as $invalid) {
            try {
                $digest($invalid);
                $this->fail('An incomplete or expanded market dependency image was admitted.');
            } catch (QueryException $error) {
                $this->assertSame('22023', $error->errorInfo[0]);
            }
        }
    }
}
