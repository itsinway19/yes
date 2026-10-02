<?php

namespace Tests\Unit;

use App\Providers\Ads\AdObservation;
use App\Providers\Ads\NativeAmount;
use App\Providers\Ads\ObservationBatch;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessState;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Sources\EffectiveCapability;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProviderObservationContractTest extends TestCase
{
    private function scope(Provider $provider = Provider::Meta, int $workspace = 1, string $account = 'account-1',
        string $source = '0199aabb-1234-7123-8123-123456789abc', string $operation = 'report.read', string $purpose = 'advertising_analysis'): ProviderScope
    {
        return new ProviderScope($workspace, $source, $provider, $account, $operation, $purpose);
    }

    private function observation(?ProviderScope $scope = null, string $spend = '12.34', ?DateTimeImmutable $readAt = null): AdObservation
    {
        return new AdObservation($scope ?? $this->scope(), 'campaign-1',
            new ReportWindow('2026-09-01', '2026-09-30', 'INCLUSIVE_DATES', 'TOTAL', 'Europe/Istanbul', 'synthetic:account-local-inclusive'),
            new NativeAmount($spend, 'TRY', 'major', 0, 'synthetic:major-unit-definition'),
            ['impressions' => ['value' => '42', 'unit' => 'count', 'meaning' => 'provider-native impression definition', 'basis' => 'PROVIDER_REPORTED'],
                'conversions' => ['value' => null, 'unit' => 'provider-native', 'meaning' => 'provider claim; no business acceptance', 'basis' => 'UNKNOWN']],
            ['api_version' => 'synthetic-v1', 'report' => 'campaign-period', 'documentation_basis' => 'synthetic:contract-test'],
            $readAt ?? new DateTimeImmutable('2026-10-01T08:00:00Z'), new DateTimeImmutable('2026-10-01T09:00:00Z'));
    }

    public static function monetaryCases(): array
    {
        return [['0', 6, '0'], ['5500000', 6, '5.5'], ['1234567', 6, '1.234567'],
            ['999999999999999999999999999999', 6, '999999999999999999999999.999999'],
            ['-0.000100', 0, '-0.0001'], ['00012.3400', 0, '12.34'], ['0.1', 6, '0.0000001'],
            ['-123', 2, '-1.23'], ['-000.000', 0, '0']];
    }

    #[DataProvider('monetaryCases')]
    public function test_exact_native_amounts_never_use_float_or_currency_display_rounding(string $native, int $scale, string $expected): void
    {
        $amount = new NativeAmount($native, 'JPY', 'synthetic-unit', $scale, 'synthetic:unit-basis');
        $this->assertSame($expected, $amount->majorUnits());
        $this->assertSame($native, $amount->jsonSerialize()['native_value']);
    }

    public function test_unknown_amount_currency_and_unit_are_distinct_from_observed_zero(): void
    {
        $unknown = new NativeAmount(null, null, 'unknown', null, null);
        $unverifiedUnit = new NativeAmount('10', 'USD', 'unknown', null, null);
        $this->assertNull($unknown->majorUnits());
        $this->assertNull($unverifiedUnit->majorUnits());
        $this->assertSame('0', (new NativeAmount('0', 'USD', 'major', 0, 'synthetic:zero'))->majorUnits());
        $this->assertNull($unknown->jsonSerialize()['currency']);
        $this->expectException(InvalidArgumentException::class);
        new NativeAmount('10', 'USD', 'micros', 6, null);
    }

    public function test_all_five_provider_namespaces_and_every_scope_dimension_are_isolated(): void
    {
        $keys = [];
        foreach (Provider::cases() as $provider) {
            $keys[] = $this->scope($provider)->key('stream');
        }
        $this->assertCount(5, array_unique($keys));
        $base = $this->scope()->key('stream');
        foreach ([$this->scope(workspace: 2), $this->scope(account: 'account-2'),
            $this->scope(source: '0199aabb-1234-7123-8123-123456789abd'),
            $this->scope(operation: 'discovery.read'), $this->scope(purpose: 'other_purpose')] as $other) {
            $this->assertNotSame($base, $other->key('stream'));
        }
        $this->assertNotSame($base, $this->scope()->key('other-stream'));
        $this->assertSame($base, $this->scope(source: '0199AABB-1234-7123-8123-123456789ABC')->key('stream'));
    }

    public function test_inclusive_local_dates_and_half_open_dst_instants_retain_native_semantics(): void
    {
        $dates = new ReportWindow('2026-09-30', '2026-09-30', 'INCLUSIVE_DATES', 'DAY', null, 'synthetic:timezone-unverified');
        $this->assertSame('2026-09-30', $dates->jsonSerialize()['end']);
        $this->assertNull($dates->timezone);
        $instants = new ReportWindow('2026-10-25T00:00:00+02:00', '2026-10-26T00:00:00+01:00',
            'HALF_OPEN_INSTANTS', 'DAY', 'Europe/Berlin', 'synthetic:25-hour-local-day');
        $this->assertSame('2026-10-25T00:00:00+02:00', $instants->start);
        $this->assertSame('HALF_OPEN_INSTANTS', $instants->boundary);
    }

    public static function invalidWindows(): array
    {
        return [['2026-02-30', '2026-03-01', 'INCLUSIVE_DATES'],
            ['2026-10-02', '2026-10-01', 'INCLUSIVE_DATES'],
            ['2026-10-01T00:00:00', '2026-10-02T00:00:00Z', 'HALF_OPEN_INSTANTS'],
            ['2026-02-30T00:00:00Z', '2026-03-02T00:00:00Z', 'HALF_OPEN_INSTANTS'],
            ['2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z', 'HALF_OPEN_INSTANTS']];
    }

    #[DataProvider('invalidWindows')]
    public function test_invalid_or_implicit_reporting_time_is_rejected(string $start, string $end, string $boundary): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReportWindow($start, $end, $boundary, 'DAY', 'UTC', 'synthetic:invalid-window');
    }

    public function test_identical_reread_is_distinct_from_a_restatement_and_old_reads_expire(): void
    {
        $first = $this->observation();
        $reread = $this->observation(readAt: new DateTimeImmutable('2026-10-01T08:30:00Z'));
        $corrected = $this->observation(spend: '11.01');
        $this->assertSame($first->identity(), $reread->identity());
        $this->assertSame($first->contentHash(), $reread->contentHash());
        $this->assertSame($first->identity(), $corrected->identity());
        $this->assertNotSame($first->contentHash(), $corrected->contentHash());
        $this->assertSame('FRESH', $first->freshness(new DateTimeImmutable('2026-10-01T08:59:59Z')));
        $this->assertSame('STALE', $first->freshness(new DateTimeImmutable('2026-10-01T09:00:00Z')));
        $this->assertSame('UNKNOWN', $first->freshness(new DateTimeImmutable('2026-10-01T07:00:00Z')));
        $this->assertNull($first->jsonSerialize()['native_facts']['conversions']['value']);
        $this->assertArrayNotHasKey('accepted_outcome', $first->jsonSerialize());
    }

    public function test_empty_valid_result_does_not_fabricate_spend_and_partial_result_keeps_coverage(): void
    {
        $empty = new ObservationBatch($this->scope(), [], 'COMPLETE', null, null);
        $this->assertSame([], $empty->observations);
        $partial = new ObservationBatch($this->scope(), [$this->observation()], 'PARTIAL', 'opaque-provider-continuation', 'page_budget_exhausted');
        $this->assertSame('PARTIAL', $partial->coverage);
        $this->assertCount(1, $partial->observations);
        $this->expectException(InvalidArgumentException::class);
        new ObservationBatch($this->scope(), [$this->observation()], 'COMPLETE', 'unfinished', null);
    }

    public function test_workspace_and_sibling_observations_cannot_enter_another_batch(): void
    {
        foreach ([$this->scope(workspace: 2), $this->scope(Provider::Google)] as $other) {
            try {
                new ObservationBatch($this->scope(), [$this->observation($other)], 'COMPLETE', null, null);
                $this->fail('Cross-scope observation accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new ObservationBatch($this->scope(), [$this->observation(), $this->observation(spend: '99')], 'COMPLETE', null, null);
    }

    public function test_connected_is_not_ready_and_common_failure_does_not_rewrite_native_state(): void
    {
        $deriver = new ProviderAccessState(new EffectiveCapability);
        $at = new DateTimeImmutable('2026-10-01T08:30:00Z');
        $state = $deriver->derive('UNVERIFIED', true, 'CONNECTED', [], $at, 'VERIFIED');
        $this->assertSame('CONNECTED', $state['provider_native_state']);
        $this->assertSame('UNKNOWN', $state['effective_operation']['state']);
        $facts = array_fill_keys(EffectiveCapability::AXES, ['state' => 'VERIFIED', 'provenance' => 'synthetic:independent',
            'verified_at' => '2026-10-01T08:00:00Z', 'valid_until' => '2026-10-01T09:00:00Z']);
        $this->assertSame('READY', $deriver->derive('UNVERIFIED', true, 'CONNECTED', $facts, $at, 'VERIFIED')['effective_operation']['state']);
        $this->assertSame('BLOCKED_BY_COMMON_FOUNDATION', $deriver->derive('UNVERIFIED', true, 'CONNECTED', $facts, $at)['effective_operation']['state']);
        $blocked = $deriver->derive('UNVERIFIED', true, 'CONNECTED', $facts, $at, 'DEGRADED');
        $this->assertSame('CONNECTED', $blocked['provider_native_state']);
        $this->assertSame('BLOCKED_BY_COMMON_FOUNDATION', $blocked['effective_operation']['state']);
        $this->assertNotSame('READY', $deriver->derive('UNVERIFIED', false, 'CONNECTED', $facts, $at)['effective_operation']['state']);
        $this->assertSame('REVOKED', $deriver->derive('REVOKED', true, 'CONNECTED', $facts, $at, 'FAILED')['effective_operation']['state']);
    }
}
