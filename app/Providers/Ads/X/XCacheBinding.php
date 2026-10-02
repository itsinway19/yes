<?php

namespace App\Providers\Ads\X;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderScope;
use App\Security\SessionAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Request;
use LogicException;

/** Shared binding for X ephemeral correlation and pending credentials; no cache record grants authority. */
final class XCacheBinding
{
    public static function context(Repository $cache, Request $request, ProviderScope $scope, int $version, string $configuration): array
    {
        $store = $cache->getStore();
        if (! $store instanceof LockProvider || (! $store instanceof RedisStore && ! ($store instanceof ArrayStore && app()->runningUnitTests()))) {
            throw new LogicException('X temporary state requires an atomic shared Redis cache.');
        }
        if ($scope->provider !== Provider::X || $scope->operation !== 'discovery.read' || $scope->purpose !== 'advertising_analysis'
            || ! $request->user() || ! $request->hasSession() || $version < 0 || ! preg_match('/^[a-f0-9]{64}$/D', $configuration)) {
            throw new AuthorizationException('Exact X callback context required.');
        }

        return ['actor' => $request->user()->getAuthIdentifier(), 'generation' => (int) $request->session()->get('a001_generation', 0),
            'session' => SessionAuthority::sessionKey($request), 'scope' => $scope->jsonSerialize(), 'access_version' => $version, 'configuration' => $configuration];
    }
}
