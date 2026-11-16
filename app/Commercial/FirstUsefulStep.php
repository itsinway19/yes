<?php

namespace App\Commercial;

/** A read-only commercial judgment over the Business owner's qualified declarations. */
final class FirstUsefulStep
{
    public function evaluate(array $business, array $practices, ?string $focus): array
    {
        $active = in_array($business['state'], ['declared_draft', 'active'], true);
        $choices = [];
        if ($active) {
            foreach ($practices as $practice) {
                if ($practice['state'] === 'active') {
                    $choices[$practice['practice_id']] = $practice;
                }
            }
        }
        $practice = $focus === null
            ? (count($choices) === 1 ? reset($choices) : null)
            : ($choices[strtolower($focus)] ?? null);
        $goal = $business['preferences']['priority'] ?? null;
        $decision = match (true) {
            ! $active => ['UNAVAILABLE', 'business_unavailable'],
            $focus !== null && $practice === null => ['UNAVAILABLE', 'practice_unavailable'],
            $goal === null => ['NEEDS_INPUT', 'choose_goal'],
            $goal === 'efficiency' => ['NEEDS_INPUT', 'spend_evidence_required'],
            ! in_array($goal, ['growth', 'customers', 'sales'], true) => ['UNAVAILABLE', 'goal_not_supported'],
            $choices === [] => ['NEEDS_INPUT', 'add_practice'],
            $practice === null => ['NEEDS_INPUT', 'choose_practice'],
            $practice['value']['offering'] === null => ['NEEDS_INPUT', 'describe_offering'],
            default => $this->nextForGoal($goal, $practice['value']),
        };
        [$status, $reason, $marketCheck] = array_pad($decision, 3, null);

        return [
            'function_id' => 'commercial.first_useful_step',
            'method_revision' => 2,
            'status' => $status,
            'reason' => $reason,
            'basis' => [
                'qualification' => 'business_declared',
                'business_public_id' => $business['business_public_id'],
                'context_version' => $business['versions']['context'],
                'preference_version' => $business['versions']['preferences'],
                'goal' => $goal,
                'market_check' => $marketCheck,
                'practice' => $practice === null ? null : [
                    'practice_id' => $practice['practice_id'],
                    'revision' => $practice['revision'],
                    'name' => $practice['value']['name'],
                    'offering' => $practice['value']['offering'],
                ],
            ],
            'practice_choices' => array_values(array_map(static fn (array $choice): array => [
                'practice_id' => $choice['practice_id'],
                'name' => $choice['value']['name'],
            ], $choices)),
        ];
    }

    private function nextForGoal(string $goal, array $practice): array
    {
        return match ($goal) {
            'growth' => $this->growthStep($practice),
            'customers' => $practice['kind'] === 'brokerage'
                ? ['ACTIONABLE', 'record_brokerage_qualifier']
                : ['ACTIONABLE', 'record_buyer_roles'],
            'sales' => ['ACTIONABLE', 'record_sale_evidence'],
        };
    }

    private function growthStep(array $practice): array
    {
        $target = $practice['target_markets'];
        $fulfillment = $practice['fulfillment'];
        if ($target['status'] !== 'declared' || $target['areas'] === []) {
            return ['NEEDS_INPUT', 'declare_target_market'];
        }
        $targetCountries = $this->countryCodes($target['areas']);
        $fulfillmentCountries = $this->countryCodes($fulfillment['areas']);
        $sharedCountries = array_values(array_intersect($targetCountries, $fulfillmentCountries));
        $marketCheck = [
            'target_countries' => $targetCountries,
            'fulfillment_countries' => $fulfillmentCountries,
            'shared_countries' => $sharedCountries,
        ];
        if ($fulfillment['status'] !== 'declared' || $fulfillment['areas'] === []) {
            return ['NEEDS_INPUT', 'declare_fulfillment', $marketCheck];
        }
        if ($sharedCountries !== []) {
            return ['ACTIONABLE', 'test_offer_in_declared_market', $marketCheck];
        }
        // Disjoint country lists describe the declarations. They do not settle how a
        // buyer can complete a transaction or whether the business can serve them.
        if ($fulfillmentCountries !== [] && count($targetCountries) === count($target['areas'])
            && count($fulfillmentCountries) === count($fulfillment['areas'])) {
            return ['NEEDS_INPUT', 'clarify_cross_market_handling', $marketCheck];
        }

        return ['NEEDS_INPUT', 'check_market_coverage', $marketCheck];
    }

    private function countryCodes(array $areas): array
    {
        return array_values(array_map(
            static fn (array $area): string => $area['ref'],
            array_filter($areas, static fn (array $area): bool => $area['authority'] === 'iso3166-1' && $area['level'] === 'country'),
        ));
    }
}
