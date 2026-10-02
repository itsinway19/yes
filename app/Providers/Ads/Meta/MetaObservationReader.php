<?php

namespace App\Providers\Ads\Meta;

use App\Providers\Ads\ObservationJournal;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/** One provider's fetch/map/accept operation inside the caller-owned signed Workspace transaction. */
final readonly class MetaObservationReader
{
    public function __construct(private Factory $http, private ObservationJournal $journal,
        private ProviderAccessStore $custody, private ProviderRequestGuard $requests) {}

    public function read(Request $request, ProviderScope $scope, ReportWindow $window, ProviderReadBudget $budget,
        string $version, #[\SensitiveParameter] string $appSecret, string $stream, string $command,
        int $expectedGeneration, int $accessVersion, ?MetaReportTask $task = null): array
    {
        return $this->attempt($request, $scope, $window, $budget, $version, $appSecret, $stream, $command,
            $expectedGeneration, $accessVersion, $task)->receipt;
    }

    public function attempt(Request $request, ProviderScope $scope, ReportWindow $window, ProviderReadBudget $budget,
        string $version, #[\SensitiveParameter] string $appSecret, string $stream, string $command,
        int $expectedGeneration, int $accessVersion, ?MetaReportTask $task = null): MetaReadAttempt
    {
        $guard = $this->guard($request, $scope, $accessVersion);
        $guard($scope, 'provider.read');
        $credentials = $this->custody->credentials($request, $scope);
        if ($credentials->value('account_id') !== $scope->accountId
            || $credentials->value('account_timezone') !== $window->timezone) {
            throw new InvalidArgumentException('Report window must match the selected native account timezone.');
        }
        $adapter = new MetaObservationAdapter($this->http, $version, $appSecret, $guard,
            new MetaReadCooldown(Cache::store(), $request, $scope, $accessVersion, $version));
        $progress = $this->journal->progress($request, $scope, $stream, $accessVersion);
        $result = $task === null ? $adapter->read($scope, $credentials, $window, $budget, $progress)
            : $adapter->readAsync($scope, $credentials, $window, $budget, $task);
        foreach ($result->batch->observations as $observation) {
            if ($observation->spend->currency !== $credentials->value('account_currency')) {
                throw new InvalidArgumentException('Native account currency changed; selection must be reverified.');
            }
        }

        $receipt = $this->journal->accept($request, $result->batch, $stream, $command,
            $expectedGeneration, $accessVersion, $result->readProvenance, $result->progress);

        return new MetaReadAttempt($receipt, $result->batch->failure, $adapter->retryDelay());
    }

    /** The reporting computation is authorized separately; callers queue only its encrypted task reference. */
    public function startAsync(Request $request, ProviderScope $scope, ReportWindow $window, ProviderReadBudget $budget,
        string $version, #[\SensitiveParameter] string $appSecret, int $accessVersion): MetaReportTask
    {
        $guard = $this->guard($request, $scope, $accessVersion);
        $guard($scope, 'provider.read');
        $credentials = $this->custody->credentials($request, $scope);
        if ($credentials->value('account_id') !== $scope->accountId || $credentials->value('account_timezone') !== $window->timezone) {
            throw new InvalidArgumentException('Report task must match the selected account and timezone.');
        }

        return (new MetaObservationAdapter($this->http, $version, $appSecret, $guard,
            new MetaReadCooldown(Cache::store(), $request, $scope, $accessVersion, $version)))->startAsync($scope, $credentials, $window, $budget);
    }

    private function guard(Request $request, ProviderScope $scope, int $accessVersion): \Closure
    {
        $current = $this->requests->bind($request, $scope, 'provider.read', $accessVersion);

        return function (ProviderScope $nativeScope, string $action) use ($current, $request, $scope): void {
            $current($nativeScope, $action);
            (new MetaAccessStatus)->requireReport($request, $scope);
        };
    }
}
