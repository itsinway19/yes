<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurposeDeclarationFixture;
use Tests\TestCase;

class ObjectPurposeDatabaseTest extends TestCase
{
    private function normalize(array $value): array
    {
        return json_decode(DB::connection('pgsql_migrations')->scalar('SELECT builderad_object_purpose_normalize(?::jsonb)', [json_encode($value)]), true);
    }

    public function test_opaque_identity_is_case_preserved_and_provider_neutral(): void
    {
        $value = PurposeDeclarationFixture::declaration();
        $this->assertEquals($value, $this->normalize($value));
        $value['included_objects'][0]['provider'] = 'meta';
        $value['included_objects'][0]['object_id'] = 'Non-numeric-native-ID';
        $this->assertSame('Non-numeric-native-ID', $this->normalize($value)['included_objects'][0]['object_id']);
    }

    public function test_duplicate_disjointness_unknown_keys_and_false_shapes_are_rejected(): void
    {
        $base = PurposeDeclarationFixture::declaration();
        $duplicate = $base;
        $duplicate['included_objects'][] = $base['included_objects'][0];
        $excluded = $base;
        $excluded['excluded_objects'] = $base['included_objects'];
        $unknown = $base;
        $unknown['arbitrary'] = true;
        $empty = $base;
        $empty['included_objects'] = [];
        $number = $base;
        $number['included_objects'][0]['object_id'] = 123;
        $long = $base;
        $long['meaning'] = str_repeat('x', 4001);
        foreach ([$duplicate, $excluded, $unknown, $empty, $number, $long] as $value) {
            try {
                $this->normalize($value);
                $this->fail('Invalid canonical snapshot admitted');
            } catch (QueryException $error) {
                $this->assertSame('22023', $error->getCode());
            }
        }
    }

    public function test_runtime_cannot_write_tables_or_call_private_normalizer(): void
    {
        foreach (['object_purposes', 'object_purpose_revisions', 'object_purpose_command_receipts'] as $table) {
            $this->assertFalse((bool) DB::scalar("SELECT has_table_privilege(current_user, ?, 'INSERT,UPDATE,DELETE')", [$table]));
        }
        $this->assertFalse((bool) DB::scalar("SELECT has_function_privilege(current_user, 'builderad_object_purpose_normalize(jsonb)', 'EXECUTE')"));
    }
}
