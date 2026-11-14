<?php

namespace Tests\Unit;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderScope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProviderScopeTest extends TestCase
{
    private const SOURCE = '018f4e5a-1234-7abc-8def-0123456789ab';

    public function test_business_scope_preserves_wire_bytes_stream_key_and_queue_identity(): void
    {
        $scope = ProviderScope::forBusiness(7, self::SOURCE, Provider::Meta, '123', 'report.read', 'advertising_analysis');
        $encoded = '{"custody_kind":"business_native","business_id":7,"source_id":"'.self::SOURCE.'","provider":"meta","account_id":"123","operation":"report.read","purpose":"advertising_analysis"}';
        self::assertSame($encoded, json_encode($scope, JSON_THROW_ON_ERROR));
        self::assertSame('bb2:'.hash('sha256', '['.$encoded.',"report"]'), $scope->key('report'));
        self::assertSame($scope->jsonSerialize(), ProviderScope::fromArray(json_decode($encoded, true))->jsonSerialize());
        self::assertSame($scope->jsonSerialize(), unserialize(serialize($scope))->jsonSerialize());
        self::assertFalse($scope->sameCustodyAs(ProviderScope::forBusiness(8, self::SOURCE, Provider::Meta, '123', 'report.read', 'advertising_analysis')));
    }

    public function test_legacy_wire_scope_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProviderScope::fromArray(['workspace_id' => 7, 'source_id' => self::SOURCE, 'provider' => 'meta',
            'account_id' => '123', 'operation' => 'report.read', 'purpose' => 'advertising_analysis']);
    }

    public function test_legacy_serialized_scope_is_rejected(): void
    {
        $fields = ['workspaceId' => 7, 'sourceId' => self::SOURCE, 'provider' => Provider::Meta,
            'accountId' => '123', 'operation' => 'report.read', 'purpose' => 'advertising_analysis'];
        $serialized = 'O:'.strlen(ProviderScope::class).':"'.ProviderScope::class.'":'.count($fields).':{';
        foreach ($fields as $name => $value) {
            $serialized .= serialize($name).serialize($value);
        }
        $this->expectException(InvalidArgumentException::class);
        unserialize($serialized.'}');
    }

    public function test_mixed_or_untyped_business_scope_is_rejected(): void
    {
        $scope = ProviderScope::forBusiness(7, self::SOURCE, Provider::Meta, '123', 'report.read', 'advertising_analysis')->jsonSerialize();
        foreach ([$scope + ['workspace_id' => null], [...$scope, 'business_id' => '7'], [...$scope, 'custody_kind' => 'platform_market']] as $invalid) {
            try {
                ProviderScope::fromArray($invalid);
                self::fail('Invalid native custody scope admitted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
