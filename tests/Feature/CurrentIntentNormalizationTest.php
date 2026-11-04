<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CurrentIntentNormalizationTest extends TestCase
{
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
        $this->actingAs(User::factory()->create());
        $file = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Independent normalization subject'])->assertCreated()->json('data');
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('workspaces')->count());

        return (object) ['current_intent' => DB::connection('pgsql_migrations')->table('business_current_intents')->value('value')];
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
}
