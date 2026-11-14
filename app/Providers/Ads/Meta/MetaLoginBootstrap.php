<?php

namespace App\Providers\Ads\Meta;

use App\Providers\Ads\NativeSourceOwner;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Explicit Connect-button request over a real server-configured Login configuration, before account discovery. */
final readonly class MetaLoginBootstrap
{
    public function __construct(private ProviderRequestGuard $requests) {}

    /** Source/command IDs are server-issued retry identities; configuration ID comes from server settings. */
    public function requestConnection(Request $request, NativeSourceOwner $business, string $configurationId,
        string $sourceId, string $commandId): ProviderScope
    {
        if (! preg_match('/^[1-9][0-9]{0,39}$/D', $configurationId) || ! Str::isUuid($commandId)) {
            throw new InvalidArgumentException('A real configured Meta Login configuration is required.');
        }
        $scope = $business->scope($sourceId, Provider::Meta, $configurationId, 'discovery.read', 'advertising_analysis');
        $business->bootstrap($request, $scope, $commandId, 'facebook_login_configuration', 'Meta advertiser connection');
        $this->requests->requireCurrent($request, $scope, 'provider.connect', 0);

        return $scope;
    }
}
