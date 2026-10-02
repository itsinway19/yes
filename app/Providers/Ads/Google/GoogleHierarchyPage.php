<?php

namespace App\Providers\Ads\Google;

use App\Providers\Ads\NativeProgress;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderScope;

/** Provider-native hierarchy page: manager paths are not flattened into common account roles. */
final readonly class GoogleHierarchyPage
{
    public function __construct(public ProviderScope $scope, public string $loginCustomerId,
        public string $queriedManagerId, public array $customers, public ?NativeProgress $continuation)
    {
        if ($scope->provider !== Provider::Google || ! GoogleAdsClient::customerId($loginCustomerId)
            || ! GoogleAdsClient::customerId($queriedManagerId) || count($customers) > 10000
            || ! array_is_list($customers) || ($continuation !== null && $continuation->kind !== 'google.manager_page')) {
            throw new \InvalidArgumentException('Exact bounded Google manager hierarchy required.');
        }
        $seen = [];
        foreach ($customers as $customer) {
            if (! is_array($customer) || array_diff(array_keys($customer), ['id', 'label', 'manager', 'level'])
                || ! GoogleAdsClient::customerId($customer['id'] ?? null) || ! is_string($customer['label'] ?? null)
                || strlen($customer['label']) > 200 || ! is_bool($customer['manager'] ?? null)
                || ! in_array($customer['level'] ?? null, [0, 1], true) || isset($seen[$customer['id']])) {
                throw new \InvalidArgumentException('Invalid native Google hierarchy customer.');
            }
            $seen[$customer['id']] = true;
        }
    }
}
