<?php

namespace App\Providers\Ads;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/** BUILDERAD SHARED REALITY SEMANTIC CONTRACT: pure, bounded interpretation of trusted typed advertising evidence. */
class SharedRealityInterpretation
{
    /**
     * Input is the journal's private run projection, never raw provider payloads or an authorization receipt.
     * Output separates accepted-generation selection, returned membership, request uncertainty and monetary support.
     */
    public function view(array $snapshot, DateTimeImmutable $at): array
    {
        try {
            $this->fields($snapshot, ['scope', 'stream', 'generation', 'latest_generation', 'membership_complete', 'run', 'entries']);
            $scope = $this->scope($snapshot['scope']);
            if ($scope->operation !== 'report.read' || ! is_string($snapshot['stream'])
                || ! preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $snapshot['stream'])
                || ! is_bool($snapshot['membership_complete']) || ! is_array($snapshot['entries'])
                || ! array_is_list($snapshot['entries']) || count($snapshot['entries']) > 200) {
                throw new InvalidArgumentException('Invalid bounded accepted projection.');
            }
            foreach (['generation', 'latest_generation'] as $key) {
                if ($snapshot[$key] !== null && (! is_int($snapshot[$key]) || $snapshot[$key] < 1)) {
                    throw new InvalidArgumentException('Invalid accepted generation.');
                }
            }
            $run = $snapshot['run'];
            $claims = [];
            if ($run === null) {
                if ($snapshot['generation'] !== null || $snapshot['entries'] !== []) {
                    throw new InvalidArgumentException('Unrecorded selection cannot contain evidence.');
                }
            } else {
                $this->fields($run, ['coverage', 'failure', 'recorded_at', 'provenance']);
                if ($snapshot['generation'] === null || $snapshot['latest_generation'] === null
                    || $snapshot['generation'] > $snapshot['latest_generation']) {
                    throw new InvalidArgumentException('Run and captured generation disagree.');
                }
                $recorded = $this->time($run['recorded_at'], true);
                $this->provenance($run['provenance']);
                $observations = [];
                foreach ($snapshot['entries'] as $entry) {
                    $this->fields($entry, ['generation', 'identity', 'content_hash', 'observation', 'prior_generation', 'relation']);
                    if ($entry['generation'] !== $snapshot['generation'] || ! is_string($entry['content_hash'])
                        || ! preg_match('/^[a-f0-9]{64}$/D', $entry['content_hash'])
                        || ! in_array($entry['relation'], ['FIRST_OBSERVATION', 'IDENTICAL_REREAD', 'RESTATEMENT'], true)
                        || ($entry['relation'] === 'FIRST_OBSERVATION' ? $entry['prior_generation'] !== null
                            : (! is_int($entry['prior_generation']) || $entry['prior_generation'] < 1 || $entry['prior_generation'] >= $entry['generation']))) {
                        throw new InvalidArgumentException('Invalid accepted entry lineage.');
                    }
                    $o = $this->observation($entry['observation']);
                    if ($o->scope->jsonSerialize() !== $scope->jsonSerialize() || $o->identity() !== $entry['identity']
                        || $o->readAt > $recorded || $o->readAt > $at) {
                        throw new InvalidArgumentException('Stored observation scope, identity or time mismatch.');
                    }
                    $observations[] = $o;
                    $missing = [];
                    foreach (['value' => $o->spend->value, 'scale' => $o->spend->decimalScale, 'currency' => $o->spend->currency] as $component => $value) {
                        if ($value === null) {
                            $missing[] = 'unknown_'.$component;
                        }
                    }
                    if ($run['coverage'] === 'PENDING') {
                        $missing[] = 'pending_acquisition';
                    }
                    $claims[] = ['scope' => $scope->jsonSerialize(), 'stream' => $snapshot['stream'], 'generation' => $entry['generation'],
                        'identity' => $entry['identity'], 'campaign_id' => $o->campaignId, 'window' => $o->window->jsonSerialize(),
                        'native_amount' => $o->spend->jsonSerialize(), 'expenditure' => ['meaning' => 'source_reported_advertising_expenditure',
                            'state' => $missing === [] ? 'SUPPORTED' : 'WITHHELD', 'amount' => $missing === [] ? $o->spend->majorUnits() : null,
                            'currency' => $o->spend->currency, 'reasons' => $missing],
                        'native_facts' => $o->nativeFacts, 'provenance' => $o->provenance, 'read_provenance' => $run['provenance'],
                        'read_at' => $o->readAt->format(DATE_ATOM), 'freshness' => $o->freshness($at), 'coverage' => $run['coverage'],
                        'relation' => $entry['relation'], 'prior_generation' => $entry['prior_generation'],
                        'content_hash' => $entry['content_hash'], 'limitations' => ['native_accounting_not_independently_verified', 'request_population_unknown']];
                }
                new ObservationBatch($scope, $observations, $run['coverage'], null, $run['failure']);
            }

            return ['scope' => $scope->jsonSerialize(), 'stream' => $snapshot['stream'], 'generation' => $snapshot['generation'],
                'latest_generation' => $snapshot['latest_generation'], 'selection' => $run === null ? 'UNRECORDED'
                    : ($snapshot['generation'] === $snapshot['latest_generation'] ? 'LATEST_ACCEPTED' : 'HISTORICAL'),
                'membership_complete' => $snapshot['membership_complete'], 'coverage' => $run['coverage'] ?? null,
                'failure' => $run['failure'] ?? null, 'recorded_at' => $run['recorded_at'] ?? null, 'provenance' => $run['provenance'] ?? null,
                'latest_request' => ['state' => 'WITHHELD', 'window' => null, 'population' => null,
                    'reasons' => ['request_semantics_unknown', 'all_acquisition_attempts_not_established']], 'claims' => $claims];
        } catch (Throwable $error) {
            throw new InvalidArgumentException('Shared advertising evidence could not be validated.', previous: $error);
        }
    }

    /** Eligibility only; never computes a sum, difference, rate or decision. Unknown operations fail closed. */
    public function assess(array $claims, string $operation): array
    {
        if (! in_array($operation, ['revision_comparison', 'period_comparison', 'addition'], true)) {
            throw new InvalidArgumentException('Unsupported shared semantic operation.');
        }
        if (! array_is_list($claims) || count($claims) < 2 || count($claims) > 200
            || ($operation !== 'addition' && count($claims) !== 2)) {
            throw new InvalidArgumentException('A bounded qualified claim set is required.');
        }
        foreach ($claims as $claim) {
            if (! is_array($claim) || ! isset($claim['scope'], $claim['window'], $claim['expenditure'], $claim['native_amount'],
                $claim['identity'], $claim['stream'], $claim['generation'], $claim['relation'], $claim['provenance'], $claim['read_provenance'])
                || ! array_key_exists('prior_generation', $claim)) {
                throw new InvalidArgumentException('Only qualified interpretation claims may be assessed.');
            }
            $this->scope($claim['scope']);
            $this->window($claim['window']);
            $this->provenance($claim['provenance']);
            $this->provenance($claim['read_provenance']);
            $this->fields($claim['expenditure'], ['meaning', 'state', 'amount', 'currency', 'reasons']);
            $amount = $this->amount($claim['native_amount']);
            if ($claim['expenditure']['meaning'] !== 'source_reported_advertising_expenditure'
                || ! in_array($claim['expenditure']['state'], ['SUPPORTED', 'WITHHELD'], true)
                || ($claim['expenditure']['state'] === 'SUPPORTED' && ($amount->currency === null || $amount->majorUnits() === null
                    || $claim['expenditure']['amount'] !== $amount->majorUnits() || $claim['expenditure']['currency'] !== $amount->currency))) {
                throw new InvalidArgumentException('Invalid qualified expenditure claim.');
            }
        }
        $left = $claims[0];
        foreach ($claims as $right) {
            if ($left['scope'] !== $right['scope']) {
                return $this->result($operation, 'UNSUPPORTED', 'different_source_scope');
            }
            if ($left['native_amount']['currency'] !== null && $right['native_amount']['currency'] !== null
                && $left['native_amount']['currency'] !== $right['native_amount']['currency']) {
                return $this->result($operation, 'UNSUPPORTED', 'different_currency');
            }
        }
        foreach ($claims as $right) {
            if ($right['expenditure']['state'] !== 'SUPPORTED') {
                return $this->result($operation, 'WITHHELD', 'monetary_prerequisites_missing');
            }
        }
        if ($operation === 'period_comparison') {
            return $this->result($operation, 'UNDETERMINED', 'accounting_population_and_request_compatibility_not_established');
        }
        if ($operation === 'revision_comparison') {
            $right = $claims[1];
            if ($left['stream'] !== $right['stream'] || $left['identity'] !== $right['identity'] || $left['window'] !== $right['window']) {
                return $this->result($operation, 'UNSUPPORTED', 'different_reported_slot');
            }
            if ($left['window']['boundary'] === 'INCLUSIVE_DATES'
                && ($left['window']['timezone'] === null || $right['window']['timezone'] === null)) {
                return $this->result($operation, 'UNDETERMINED', 'native_civil_timezone_compatibility_unknown');
            }
            if ($right['prior_generation'] !== $left['generation'] || ! in_array($right['relation'], ['RESTATEMENT', 'IDENTICAL_REREAD'], true)
                || $right['generation'] <= $left['generation']) {
                return $this->result($operation, 'UNDETERMINED', 'revision_lineage_not_established');
            }
            foreach (['currency', 'native_unit', 'decimal_scale', 'scale_basis'] as $dimension) {
                if ($left['native_amount'][$dimension] !== $right['native_amount'][$dimension]) {
                    return $this->result($operation, 'UNDETERMINED', 'typed_monetary_meaning_changed');
                }
            }
            if ($left['provenance'] !== $right['provenance'] || $left['read_provenance'] !== $right['read_provenance']) {
                return $this->result($operation, 'UNDETERMINED', 'report_meaning_compatibility_unknown');
            }

            return $this->result($operation, 'PERMITTED', 'same_slot_reported_versions_only');
        }
        foreach ($claims as $index => $right) {
            foreach (array_slice($claims, 0, $index) as $prior) {
                if ($prior['identity'] === $right['identity']) {
                    return $this->result($operation, 'UNSUPPORTED', 'reported_slot_versions_not_additive');
                }
                if ($this->overlaps($this->window($prior['window']), $this->window($right['window']))) {
                    return $this->result($operation, 'UNSUPPORTED', 'overlapping_reporting_periods');
                }
            }
        }

        return $this->result($operation, 'UNDETERMINED', 'additivity_disjoint_population_and_union_coverage_not_established');
    }

    private function result(string $operation, string $state, string $reason): array
    {
        return ['operation' => $operation, 'state' => $state, 'reason' => $reason];
    }

    private function overlaps(ReportWindow $left, ReportWindow $right): bool
    {
        if ($left->boundary !== $right->boundary || $left->semantics !== $right->semantics
            || $left->timezone === null || $left->timezone !== $right->timezone) {
            return false; // Unknown compatibility is never proof of permission; addition remains withheld.
        }
        if ($left->boundary === 'INCLUSIVE_DATES') {
            return $left->start <= $right->end && $right->start <= $left->end;
        }

        return new DateTimeImmutable($left->start) < new DateTimeImmutable($right->end)
            && new DateTimeImmutable($right->start) < new DateTimeImmutable($left->end);
    }

    private function fields(mixed $value, array $keys): void
    {
        if (! is_array($value) || count($value) !== count($keys) || array_diff($keys, array_keys($value))) {
            throw new InvalidArgumentException('Invalid typed evidence shape.');
        }
    }

    private function scope(mixed $value): ProviderScope
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Typed source scope required.');
        }

        return ProviderScope::fromArray($value);
    }

    private function amount(mixed $value): NativeAmount
    {
        $this->fields($value, ['native_value', 'currency', 'native_unit', 'decimal_scale', 'scale_basis', 'major_units']);
        if (($value['native_value'] !== null && ! is_string($value['native_value']))
            || ($value['decimal_scale'] !== null && ! is_int($value['decimal_scale']))
            || ! is_string($value['native_unit']) || ($value['currency'] !== null && ! is_string($value['currency']))
            || ($value['scale_basis'] !== null && ! is_string($value['scale_basis']))) {
            throw new InvalidArgumentException('Exact native money requires typed strings and evidenced scale.');
        }

        return new NativeAmount($value['native_value'], $value['currency'], $value['native_unit'], $value['decimal_scale'], $value['scale_basis']);
    }

    private function window(mixed $value): ReportWindow
    {
        $this->fields($value, ['start', 'end', 'boundary', 'grain', 'timezone', 'semantics']);
        foreach (['start', 'end', 'boundary', 'grain', 'semantics'] as $key) {
            if (! is_string($value[$key])) {
                throw new InvalidArgumentException('Typed reporting window required.');
            }
        }
        if ($value['timezone'] !== null && ! is_string($value['timezone'])) {
            throw new InvalidArgumentException('Typed native timezone required.');
        }

        return new ReportWindow($value['start'], $value['end'], $value['boundary'], $value['grain'], $value['timezone'], $value['semantics']);
    }

    private function provenance(mixed $value): void
    {
        $this->fields($value, ['api_version', 'report', 'documentation_basis']);
        foreach ($value as $part) {
            if (! is_string($part) || $part === '' || strlen($part) > 512 || preg_match('/[\x00-\x1f\x7f]/', $part)) {
                throw new InvalidArgumentException('Explicit report provenance required.');
            }
        }
    }

    private function time(mixed $value, bool $recorded = false): DateTimeImmutable
    {
        $pattern = $recorded ? '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}(?::\d{2})?)$/D'
            : '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D';
        if (! is_string($value) || ! preg_match($pattern, $value)) {
            throw new InvalidArgumentException('Explicit evidence time required.');
        }
        $date = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
            throw new InvalidArgumentException('Invalid evidence time.');
        }

        return $date;
    }

    private function observation(mixed $value): AdObservation
    {
        $this->fields($value, ['scope', 'campaign_id', 'window', 'spend', 'native_facts', 'provenance', 'read_at', 'fresh_until']);
        if (! is_string($value['campaign_id'])) {
            throw new InvalidArgumentException('Typed campaign identity required.');
        }

        return new AdObservation($this->scope($value['scope']), $value['campaign_id'], $this->window($value['window']),
            $this->amount($value['spend']), $value['native_facts'], $value['provenance'], $this->time($value['read_at']),
            $value['fresh_until'] === null ? null : $this->time($value['fresh_until']));
    }
}
