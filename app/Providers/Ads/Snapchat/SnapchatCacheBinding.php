<?php

namespace App\Providers\Ads\Snapchat;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderScope;
use App\Security\SessionAuthority;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;

/** Snapchat's ephemeral server-side correlation; no provider authority is inferred from cache contents. */
final class SnapchatCacheBinding
{
    public static function context(Repository $cache, Request $request, ProviderScope $scope,
        int $accessVersion, string $configuration): array
    {
        $store = $cache->getStore();
        if (! $store instanceof LockProvider || (! $store instanceof RedisStore
            && ! ($store instanceof ArrayStore && app()->runningUnitTests()))) {
            throw new LogicException('Snapchat temporary state requires an atomic shared Redis cache.');
        }
        if ($scope->provider !== Provider::Snapchat || ! $request->user() || ! $request->hasSession()
            || ! preg_match('/^[a-f0-9]{64}$/D', $configuration) || $accessVersion < 0) {
            throw new InvalidArgumentException('Exact Snapchat callback context is required.');
        }

        return ['actor' => $request->user()->getAuthIdentifier(), 'generation' => (int) $request->session()->get('a001_generation', 0),
            'session' => SessionAuthority::sessionKey($request), 'scope' => $scope->jsonSerialize(),
            'access_version' => $accessVersion, 'configuration' => $configuration];
    }
}
