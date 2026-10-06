<?php

namespace Tests\Unit;

use App\Providers\Ads\AdObservation;
use App\Providers\Ads\NativeAmount;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Providers\Ads\SharedRealityInterpretation;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SharedRealityInterpretationTest extends TestCase
{
    private function observation(Provider $provider = Provider::Meta, ?string $value = '12.34', ?string $currency = 'TRY', ?int $scale = 0,
        string $start = '2026-09-01', string $end = '2026-09-30', string $grain = 'TOTAL', ?ReportWindow $window = null): AdObservation
    {
        return new AdObservation(new ProviderScope(1, '0199aabb-1234-7123-8123-123456789abc', $provider, 'account-1', 'report.read', 'advertising_analysis'),
            'campaign-1', $window ?? new ReportWindow($start, $end, 'INCLUSIVE_DATES', $grain, 'Europe/Istanbul', 'synthetic:account-local'),
            new NativeAmount($value, $currency, 'synthetic:reported-expenditure', $scale, $scale === null ? null : 'synthetic:documented-scale'),
            ['conversions' => ['value' => '8', 'unit' => 'native_attribution', 'meaning' => 'Provider attribution, not accepted business outcome', 'basis' => 'PROVIDER_REPORTED']],
            ['api_version' => 'synthetic-v1', 'report' => 'campaign-period', 'documentation_basis' => 'synthetic:report-definition'],
            new DateTimeImmutable('2026-10-01T08:00:00Z'), null);
    }

    private function projection(?AdObservation $observation = null, string $coverage = 'COMPLETE', int $generation = 1,
        ?int $prior = null, string $relation = 'FIRST_OBSERVATION'): array
    {
        $o = $observation ?? $this->observation();

        return ['scope' => $o->scope->jsonSerialize(), 'stream' => 'synthetic-range', 'generation' => $generation, 'latest_generation' => $generation,
            'membership_complete' => true, 'run' => ['coverage' => $coverage, 'failure' => $coverage === 'FAILED' ? 'synthetic_failed' : null,
                'recorded_at' => '2026-10-01T08:01:00Z', 'provenance' => $o->provenance],
            'entries' => [['generation' => $generation, 'identity' => $o->identity(), 'content_hash' => str_repeat('a', 64),
                'observation' => $o->jsonSerialize(), 'prior_generation' => $prior, 'relation' => $relation]]];
    }

    private function view(array $projection): array
    {
        return (new SharedRealityInterpretation)->view($projection, new DateTimeImmutable('2026-10-02T00:00:00Z'));
    }

    public static function providerMoney(): array
    {
        return [['meta', '12.34', 0, '12.34'], ['google', '12340000', 6, '12.34'], ['snapchat', '12340000', 6, '12.34'],
            ['tiktok', '12.34', null, null], ['x', '12340000', 6, '12.34']];
    }

    #[DataProvider('providerMoney')]
    public function test_typed_five_provider_contract_needs_no_second_native_mapper(string $provider, string $native, ?int $scale, ?string $expected): void
    {
        $claim = $this->view($this->projection($this->observation(Provider::from($provider), $native, scale: $scale)))['claims'][0];
        $this->assertSame($native, $claim['native_amount']['native_value']);
        $this->assertSame($expected, $claim['expenditure']['amount']);
        $this->assertSame($expected === null ? 'WITHHELD' : 'SUPPORTED', $claim['expenditure']['state']);
        $this->assertSame('UNKNOWN', $claim['freshness']);
        $this->assertArrayNotHasKey('accepted_outcome', $claim);
    }

    public static function moneyCases(): array
    {
        return [['0', 'TRY', 0, '0'], [null, 'TRY', 0, null], ['1', null, 0, null], ['1', 'TRY', null, null],
            ['-123456789012345678901234567890.123456789', 'TRY', 6, '-123456789012345678901234.567890123456789']];
    }

    #[DataProvider('moneyCases')]
    public function test_exact_signed_money_does_not_invent_unknown_or_trust_serialized_major_units(?string $value, ?string $currency, ?int $scale, ?string $expected): void
    {
        $projection = $this->projection($this->observation(value: $value, currency: $currency, scale: $scale));
        $projection['entries'][0]['observation']['spend']['major_units'] = '999999';
        $claim = $this->view($projection)['claims'][0];
        $this->assertSame($expected, $claim['expenditure']['amount']);
        $this->assertSame('8', $claim['native_facts']['conversions']['value']);
        $this->assertArrayNotHasKey('business_outcomes', $claim);
    }

    public function test_empty_complete_unknown_and_historical_membership_are_qualified(): void
    {
        $p = $this->projection();
        $p['entries'] = [];
        $view = $this->view($p);
        $this->assertSame([], $view['claims']);
        $this->assertSame('COMPLETE', $view['coverage']);
        $this->assertSame('WITHHELD', $view['latest_request']['state']);
        $p['run']['coverage'] = 'UNKNOWN';
        $p['generation'] = 1;
        $p['latest_generation'] = 2;
        $p['membership_complete'] = false;
        $view = $this->view($p);
        $this->assertSame('HISTORICAL', $view['selection']);
        $this->assertFalse($view['membership_complete']);
        $this->assertSame('UNKNOWN', $view['coverage']);
    }

    public function test_revision_is_a_qualified_version_comparison_never_an_additional_amount(): void
    {
        $left = $this->view($this->projection())['claims'][0];
        $right = $this->view($this->projection($this->observation(value: '15'), generation: 2, prior: 1, relation: 'RESTATEMENT'))['claims'][0];
        $interpreter = new SharedRealityInterpretation;
        $this->assertSame('PERMITTED', $interpreter->assess([$left, $right], 'revision_comparison')['state']);
        $this->assertSame('UNSUPPORTED', $interpreter->assess([$left, $right], 'addition')['state']);
        $right['provenance']['report'] = 'different report meaning';
        $this->assertSame('UNDETERMINED', $interpreter->assess([$left, $right], 'revision_comparison')['state']);
    }

    public function test_cross_provider_claims_coexist_without_comparison_or_addition(): void
    {
        $claims = [$this->view($this->projection())['claims'][0], $this->view($this->projection($this->observation(Provider::Google)))['claims'][0]];
        foreach (['revision_comparison', 'period_comparison', 'addition'] as $operation) {
            $this->assertSame('UNSUPPORTED', (new SharedRealityInterpretation)->assess($claims, $operation)['state']);
        }
    }

    public function test_changed_read_definition_withholds_linked_revision_comparison(): void
    {
        $left = $this->view($this->projection())['claims'][0];
        $p = $this->projection($this->observation(value: '15'), generation: 2, prior: 1, relation: 'RESTATEMENT');
        $p['run']['provenance']['report'] = 'changed read methodology';
        $right = $this->view($p)['claims'][0];
        $this->assertSame('UNDETERMINED', (new SharedRealityInterpretation)->assess([$left, $right], 'revision_comparison')['state']);
    }

    public function test_half_open_overlap_and_unknown_timezone_never_establish_addition_permission(): void
    {
        $left = $this->view($this->projection($this->observation(window: new ReportWindow('2026-09-01T00:00:00Z', '2026-09-02T00:00:00Z',
            'HALF_OPEN_INSTANTS', 'TOTAL', 'UTC', 'synthetic:instants'))))['claims'][0];
        foreach ([['2026-09-02T01:00:00+02:00', '2026-09-03T00:00:00Z', 'UTC', 'UNSUPPORTED'],
            ['2026-09-02T00:00:00Z', '2026-09-03T00:00:00Z', 'UTC', 'UNDETERMINED'],
            ['2026-09-01T12:00:00Z', '2026-09-03T00:00:00Z', null, 'UNDETERMINED']] as [$start, $end, $timezone, $state]) {
            $right = $this->view($this->projection($this->observation(window: new ReportWindow($start, $end,
                'HALF_OPEN_INSTANTS', 'TOTAL', $timezone, 'synthetic:instants'))))['claims'][0];
            $this->assertSame($state, (new SharedRealityInterpretation)->assess([$left, $right], 'addition')['state']);
        }
    }

    public static function periods(): array
    {
        return [['2026-09-15', '2026-10-01', 'TOTAL', 'UNSUPPORTED'], ['2026-09-01', '2026-09-01', 'DAY', 'UNSUPPORTED'],
            ['2026-10-01', '2026-10-31', 'TOTAL', 'UNDETERMINED']];
    }

    #[DataProvider('periods')]
    public function test_overlap_day_total_and_unproven_disjoint_components_never_produce_subtotal(string $start, string $end, string $grain, string $expected): void
    {
        $left = $this->view($this->projection())['claims'][0];
        $right = $this->view($this->projection($this->observation(value: '-5', start: $start, end: $end, grain: $grain)))['claims'][0];
        $result = (new SharedRealityInterpretation)->assess([$left, $right], 'addition');
        $this->assertSame($expected, $result['state']);
        $this->assertArrayNotHasKey('amount', $result);
        $this->assertArrayNotHasKey('lower_bound', $result);
        $this->assertSame('UNDETERMINED', (new SharedRealityInterpretation)->assess([$left, $right], 'period_comparison')['state']);
    }

    public function test_unknown_assess_operation_fails_closed_without_fallback(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SharedRealityInterpretation)->assess([], 'compare_everything');
    }

    public function test_known_currency_incompatibility_is_not_an_unknown_additivity_claim(): void
    {
        $left = $this->view($this->projection())['claims'][0];
        $right = $this->view($this->projection($this->observation(currency: 'USD'), generation: 2, prior: 1, relation: 'RESTATEMENT'))['claims'][0];
        foreach (['revision_comparison', 'period_comparison', 'addition'] as $operation) {
            $this->assertSame('UNSUPPORTED', (new SharedRealityInterpretation)->assess([$left, $right], $operation)['state']);
        }
    }

    public function test_non_string_persisted_campaign_id_is_rejected_even_when_string_identity_matches(): void
    {
        $original = $this->observation();
        $stringObservation = new AdObservation($original->scope, '123', $original->window, $original->spend,
            $original->nativeFacts, $original->provenance, $original->readAt, $original->freshUntil);
        $projection = $this->projection($stringObservation);
        $valid = $this->view($projection)['claims'][0];
        $this->assertSame('123', $valid['campaign_id']);
        $this->assertSame($stringObservation->identity(), $valid['identity']);
        $this->assertSame('SUPPORTED', $valid['expenditure']['state']);

        $projection['entries'][0]['observation']['campaign_id'] = 123;
        $this->expectException(InvalidArgumentException::class);
        $this->view($projection);
    }

    public static function revisionWindows(): array
    {
        return [
            'unknown civil timezone' => ['INCLUSIVE_DATES', null, '2026-09-01', '2026-09-30', 'UNDETERMINED'],
            'known civil timezone' => ['INCLUSIVE_DATES', 'Europe/Istanbul', '2026-09-01', '2026-09-30', 'PERMITTED'],
            'explicit instants without named timezone' => ['HALF_OPEN_INSTANTS', null, '2026-09-01T00:00:00+03:00', '2026-09-30T00:00:00+03:00', 'PERMITTED'],
        ];
    }

    #[DataProvider('revisionWindows')]
    public function test_revision_eligibility_requires_known_civil_timezone_but_preserves_explicit_instants(
        string $boundary, ?string $timezone, string $start, string $end, string $expected): void
    {
        $window = new ReportWindow($start, $end, $boundary, 'TOTAL', $timezone, 'synthetic:revision-window');
        $left = $this->view($this->projection($this->observation(window: $window)))['claims'][0];
        $right = $this->view($this->projection($this->observation(value: '15', window: $window),
            generation: 2, prior: 1, relation: 'RESTATEMENT'))['claims'][0];
        $this->assertSame($expected, (new SharedRealityInterpretation)->assess([$left, $right], 'revision_comparison')['state']);
    }

    public static function malformedEvidence(): array
    {
        return [['scope'], ['identity'], ['window'], ['amount'], ['time'], ['lineage'], ['generation'], ['scope_type'], ['unit_type'], ['scale_basis_type']];
    }

    #[DataProvider('malformedEvidence')]
    public function test_malformed_stored_evidence_fails_closed(string $case): void
    {
        $p = $this->projection();
        switch ($case) {
            case 'scope': $p['entries'][0]['observation']['scope']['workspace_id'] = 2;
                break;
            case 'identity': $p['entries'][0]['identity'] = 'bb2:'.str_repeat('f', 64);
                break;
            case 'window': $p['entries'][0]['observation']['window']['start'] = '2026-02-30';
                break;
            case 'amount': $p['entries'][0]['observation']['spend']['native_value'] = 1.5;
                break;
            case 'time': $p['entries'][0]['observation']['read_at'] = 'tomorrow';
                break;
            case 'lineage': $p['entries'][0]['prior_generation'] = 1;
                break;
            case 'generation': $p['entries'][0]['generation'] = 2;
                break;
            case 'scope_type': $p['entries'][0]['observation']['scope']['workspace_id'] = '1';
                break;
            case 'unit_type': $p['entries'][0]['observation']['spend']['native_unit'] = 1;
                break;
            case 'scale_basis_type': $p['entries'][0]['observation']['spend']['scale_basis'] = 1;
                break;
        }
        $this->expectException(InvalidArgumentException::class);
        $this->view($p);
    }
}
