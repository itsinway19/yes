<?php

namespace Tests\Unit;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderScope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderScopeTest extends TestCase
{
    private const SOURCE = '018f4e5a-1234-7abc-8def-0123456789ab';

    public function test_historical_workspace_scope_keeps_its_exact_serialization_and_stream_key(): void
    {
        $scope = new ProviderScope(7, self::SOURCE, Provider::Meta, '123', 'report.read', 'advertising_analysis');
        $encoded = '{"workspace_id":7,"source_id":"'.self::SOURCE.'","provider":"meta","account_id":"123","operation":"report.read","purpose":"advertising_analysis"}';

        self::assertSame($encoded, json_encode($scope, JSON_THROW_ON_ERROR));
        self::assertSame('bb2:'.hash('sha256', '['.$encoded.',"report"]'), $scope->key('report'));
        self::assertSame($scope->jsonSerialize(), ProviderScope::fromArray(json_decode($encoded, true))->jsonSerialize());
    }

    public function test_business_custody_has_an_explicit_distinct_scope_without_a_workspace(): void
    {
        $scope = ProviderScope::forBusiness(7, self::SOURCE, Provider::Meta, '123', 'report.read', 'advertising_analysis');
        $workspace = new ProviderScope(7, self::SOURCE, Provider::Meta, '123', 'report.read', 'advertising_analysis');

        self::assertNull($scope->workspaceId);
        self::assertSame(7, $scope->businessId);
        self::assertSame('business_native', $scope->jsonSerialize()['custody_kind']);
        self::assertArrayNotHasKey('workspace_id', $scope->jsonSerialize());
        self::assertNotSame($workspace->key('report'), $scope->key('report'));
        self::assertSame($scope->jsonSerialize(), ProviderScope::fromArray($scope->jsonSerialize())->jsonSerialize());
        self::assertNotSame($scope->key('report'), ProviderScope::forBusiness(8, self::SOURCE, Provider::Meta, '123', 'report.read', 'advertising_analysis')->key('report'));
    }

    public function test_queued_six_field_workspace_scope_restores_without_reinterpreting_its_historical_identity(): void
    {
        $scope = new ProviderScope(7, self::SOURCE, Provider::Meta, '123', 'report.read', 'advertising_analysis');
        $fields = get_object_vars($scope);
        unset($fields['businessId']);
        $historical = 'O:'.strlen(ProviderScope::class).':"'.ProviderScope::class.'":'.count($fields).':{';
        foreach ($fields as $name => $value) {
            $historical .= serialize($name).serialize($value);
        }
        $historical .= '}';
        $restored = unserialize($historical);
        self::assertNull($restored->businessId);
        self::assertSame(json_encode($scope), json_encode($restored));
        self::assertSame($scope->key('original-stream'), $restored->key('original-stream'));
        $business = ProviderScope::forBusiness(7, self::SOURCE, Provider::Meta, '123', 'report.read', 'advertising_analysis');
        self::assertSame($business->jsonSerialize(), unserialize(serialize($business))->jsonSerialize());
    }

    public function test_mixed_or_untyped_custody_cannot_be_deserialized(): void
    {
        $scope = (new ProviderScope(7, self::SOURCE, Provider::Meta, '123', 'report.read', 'advertising_analysis'))->jsonSerialize();
        foreach ([
            $scope + ['business_id' => 7],
            [...$scope, 'workspace_id' => null],
            [...$scope, 'workspace_id' => 0],
            [...$scope, 'workspace_id' => '7'],
            [...$scope, 'workspace_id' => true],
            $scope + ['custody_kind' => 'platform_market'],
        ] as $invalid) {
            try {
                ProviderScope::fromArray($invalid);
                self::fail('A mixed or invalid native custody scope was admitted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public static function custodyPairs(): array
    {
        return [
            'same Workspace' => [7, null, 7, null, true],
            'different Workspaces' => [7, null, 8, null, false],
            'same Business' => [null, 7, null, 7, true],
            'different Businesses with NULL Workspaces' => [null, 7, null, 8, false],
            'numeric identity cannot cross custody kinds' => [7, null, null, 7, false],
            'numeric identity cannot cross custody kinds in reverse' => [null, 7, 7, null, false],
        ];
    }

    #[DataProvider('custodyPairs')]
    public function test_custody_comparison_keeps_business_and_workspace_identity_distinct(
        ?int $leftWorkspace, ?int $leftBusiness, ?int $rightWorkspace, ?int $rightBusiness, bool $same): void
    {
        $left = new ProviderScope($leftWorkspace, self::SOURCE, Provider::Meta, '123', 'report.read', 'advertising_analysis', $leftBusiness);
        $right = new ProviderScope($rightWorkspace, self::SOURCE, Provider::Meta, '123', 'report.read', 'advertising_analysis', $rightBusiness);

        self::assertSame($same, $left->sameCustodyAs($right));
        self::assertSame($same, $right->sameCustodyAs($left));
    }
}
