<?php

namespace App\Providers\Ads\X;

use App\Providers\Ads\NativeSourceOwner;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderScope;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Explicit Business-native source declaration; authority stays with the canonical Source writer. */
final class XSourceBootstrap
{
    public function requestConnection(Request $request, NativeSourceOwner $business, string $consumerKey, string $sourceId, string $command): ProviderScope
    {
        $scope = $business->scope($sourceId, Provider::X, $consumerKey, 'discovery.read', 'advertising_analysis');
        $business->bootstrap($request, $scope, $command, 'developer_app', 'X '.$scope->accountId);

        return $scope;
    }

    public function requestAccount(Request $request, NativeSourceOwner $business, ProviderScope $selected, string $command): void
    {
        if ($selected->businessId !== $business->businessId || $selected->provider !== Provider::X
            || $selected->operation !== 'report.read' || $selected->purpose !== 'advertising_analysis') {
            throw new InvalidArgumentException('Exact selected X advertising account required.');
        }
        $business->bootstrap($request, $selected, $command, 'ad_account', 'X '.$selected->accountId);
    }
}
