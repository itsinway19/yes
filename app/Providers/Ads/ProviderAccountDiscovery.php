<?php

namespace App\Providers\Ads;

use InvalidArgumentException;

/** A bounded native discovery page is evidence, never authorization or automatic selection. */
final readonly class ProviderAccountDiscovery
{
    public function __construct(public ProviderScope $scope, public array $accounts, public string $basis,
        public string $coverage, public ?NativeProgress $continuation)
    {
        if ($basis === '' || strlen($basis) > 512 || count($accounts) > 1000
            || ! in_array($coverage, ['COMPLETE', 'PARTIAL', 'UNKNOWN'], true)
            || ($coverage === 'COMPLETE' && $continuation !== null)) {
            throw new InvalidArgumentException('Invalid native account discovery evidence.');
        }
        $ids = [];
        foreach ($accounts as $account) {
            if (! is_array($account) || array_diff(array_keys($account), ['id', 'label', 'native_role'])
                || ! isset($account['id'], $account['label']) || ! is_string($account['id']) || $account['id'] === ''
                || strlen($account['id']) > 180 || isset($ids[$account['id']]) || ! is_string($account['label'])
                || strlen($account['label']) > 200 || ! array_key_exists('native_role', $account)
                || ($account['native_role'] !== null && (! is_string($account['native_role']) || strlen($account['native_role']) > 200))) {
                throw new InvalidArgumentException('Discovery retains unique provider account identities and native roles.');
            }
            $ids[$account['id']] = true;
        }
    }
}
