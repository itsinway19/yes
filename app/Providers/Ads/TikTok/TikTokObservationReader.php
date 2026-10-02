<?php

namespace App\Providers\Ads\TikTok;

use App\Providers\Ads\ObservationBatch;
use App\Providers\Ads\ObservationJournal;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/** Fetch and atomic acceptance inside the caller's signed Workspace transaction. */
final readonly class TikTokObservationReader
{
    public function __construct(private Factory $http, private ObservationJournal $journal,
        private ProviderAccessStore $custody, private ProviderRequestGuard $requests) {}

    public function read(Request $request, ProviderScope $scope, ReportWindow $window, ProviderReadBudget $budget,
        string $version, string $stream, string $command,
        int $expectedGeneration, int $accessVersion): array
    {
        return $this->attempt($request, $scope, $window, $budget, $version, $stream,
            $command, $expectedGeneration, $accessVersion)->receipt;
    }

    public function attempt(Request $request, ProviderScope $scope, ReportWindow $window, ProviderReadBudget $budget,
        string $version, string $stream, string $command,
        int $expectedGeneration, int $accessVersion): TikTokReadAttempt
    {
        if ($scope->provider !== Provider::TikTok) {
            throw new InvalidArgumentException('TikTok reader requires an exact TikTok source.');
        }
        $guard = $this->requests->bind($request, $scope, 'provider.read', $accessVersion);
        $guard($scope, 'provider.read');
        (new TikTokAccessStatus)->requireReport($request, $scope);
        $credentials = $this->custody->credentials($request, $scope);
        $cooldown = new TikTokReadCooldown(app()->runningUnitTests() ? Cache::store() : Cache::store('redis'), $request, $scope, $accessVersion, $version);
        if (($remaining = $cooldown->remaining()) !== null && $remaining > 0) {
            $receipt = $this->journal->accept($request, new ObservationBatch($scope, [], 'FAILED', null, 'tiktok_native_cooldown'),
                $stream, $command, $expectedGeneration, $accessVersion, ['api_version' => $version, 'report' => 'TikTok report deferred by native retry hint',
                    'documentation_basis' => 'https://business-api.tiktok.com/portal/docs?id=share']);

            return new TikTokReadAttempt($receipt, 'tiktok_native_cooldown', $remaining);
        }
        if ($credentials->value('account_id') !== $scope->accountId
            || $credentials->value('account_timezone') !== $window->timezone
            || ! preg_match('/^[A-Z]{3}$/D', $credentials->value('account_currency') ?? '')) {
            throw new InvalidArgumentException('TikTok report requires the verified native account, timezone and currency.');
        }
        $adapter = new TikTokObservationAdapter($this->http, $version, $guard);
        $progress = $this->journal->progress($request, $scope, $stream, $accessVersion);
        $result = $adapter->read($scope, $credentials, $window, $budget, $progress);
        if (($seconds = $adapter->failure()?->retryAfterSeconds) !== null && $seconds > 0) {
            $cooldown->observe($seconds);
        }

        $receipt = $this->journal->accept($request, $result->batch, $stream, $command,
            $expectedGeneration, $accessVersion, $result->readProvenance, $result->progress);

        return new TikTokReadAttempt($receipt, $adapter->failure()?->reason, $adapter->failure()?->retryAfterSeconds);
    }
}
