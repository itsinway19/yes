<?php

namespace App\Providers\Ads\Snapchat;

use App\Providers\Ads\ObservationBatch;
use App\Providers\Ads\ObservationJournal;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Fetch and atomic acceptance inside the caller's signed Workspace transaction. */
final readonly class SnapchatObservationReader
{
    public function __construct(private Factory $http, private ObservationJournal $journal,
        private ProviderAccessStore $custody, private ProviderRequestGuard $requests) {}

    /** Internal metadata continuation only; no CSV download, observation acceptance or public async promise. */
    public function asyncTicket(Request $request, ProviderScope $scope, ReportWindow $window, ProviderReadBudget $budget,
        string $rootCommand, int $accessVersion, DateTimeImmutable $deadline): array
    {
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        if ($scope->provider !== Provider::Snapchat || ! Str::isUuid($rootCommand) || $scope->jsonSerialize() !== $budget->scope->jsonSerialize()
            || $deadline <= $now || $deadline > $now->modify('+5 minutes') || $budget->deadline > $deadline || $budget->deadline <= $now) {
            throw new InvalidArgumentException('Exact bounded Snapchat async command required.');
        }
        $guard = $this->requests->bind($request, $scope, 'provider.read', $accessVersion);
        $authorize = function () use ($guard, $request, $scope): void {
            $guard($scope, 'provider.read');
            (new SnapchatAccessStatus)->requireReport($request, $scope);
        };
        $authorize();
        $credentials = $this->custody->credentials($request, $scope);
        if ($credentials->value('account_id') !== $scope->accountId || $credentials->value('account_timezone') !== $window->timezone
            || ! preg_match('/^[A-Z]{3}$/D', $credentials->value('account_currency') ?? '')
            || ! preg_match('/^[1-9][0-9]{0,10}$/D', $credentials->value('expires_at') ?? '') || (int) $credentials->value('expires_at') <= time()) {
            throw new InvalidArgumentException('Snapchat async work requires current verified native account custody.');
        }
        $settings = SnapchatReadSettings::configured();
        $cache = app()->runningUnitTests() ? Cache::store() : Cache::store('redis');
        $binding = SnapchatCacheBinding::context($cache, $request, $scope, $accessVersion, $settings->binding());
        $cooldown = new SnapchatReadCooldown($cache, $request, $scope, $accessVersion, $settings->version);
        $client = new SnapchatAsyncReport($this->http, function ($nativeScope, $action) use ($authorize): void {
            $authorize();
        });
        $ticket = (new SnapchatAsyncAcquisition($cache))->advance(
            [$binding, $window->jsonSerialize(), $budget->key, $rootCommand, $deadline->format(DATE_ATOM)], $deadline,
            function (?array $ticket) use ($scope, $window, $credentials, $budget, $client, $cooldown, $authorize): array {
                $authorize();
                if (($ticket['state'] ?? null) === 'COMPLETED') {
                    SnapchatAsyncReport::completedUrl($scope, $window, $ticket);

                    return $ticket;
                }
                if (($remaining = $cooldown->remaining()) !== null && $remaining > 0) {
                    throw new SnapchatRequestFailed('snapchat_native_cooldown', $remaining);
                }
                try {
                    return $ticket === null ? $client->start($scope, $credentials, $window, $budget)
                        : $client->poll($scope, $credentials, $window, $budget, $ticket);
                } catch (SnapchatRequestFailed $failure) {
                    if (($failure->retryAfterSeconds ?? 0) > 0) {
                        $cooldown->observe($failure->retryAfterSeconds);
                    }
                    throw $failure;
                }
            });
        $authorize();

        return $ticket;
    }

    public function read(Request $request, ProviderScope $scope, ReportWindow $window, ProviderReadBudget $budget,
        string $version, string $stream, string $command,
        int $expectedGeneration, int $accessVersion): array
    {
        return $this->attempt($request, $scope, $window, $budget, $version, $stream,
            $command, $expectedGeneration, $accessVersion)->receipt;
    }

    /** Metadata-only journal state until the standard campaign CSV contract is established. */
    public function asyncAttempt(Request $request, ProviderScope $scope, ReportWindow $window, ProviderReadBudget $budget,
        string $version, string $stream, string $rootCommand, string $command, int $expectedGeneration,
        int $accessVersion, DateTimeImmutable $deadline): SnapchatReadAttempt
    {
        new SnapchatReadSettings($version);
        $failure = null;
        $delay = null;
        try {
            $ticket = $this->asyncTicket($request, $scope, $window, $budget, $rootCommand, $accessVersion, $deadline);
            $coverage = $ticket['state'] === 'COMPLETED' ? 'FAILED' : 'PENDING';
            $failure = $ticket['state'] === 'COMPLETED' ? 'snapchat_async_schema_unverified' : null;
        } catch (SnapchatRequestFailed $error) {
            $coverage = 'FAILED';
            $failure = $error->reason;
            $delay = $error->retryAfterSeconds;
        }
        $receipt = $this->journal->accept($request, new ObservationBatch($scope, [], $coverage, null, $failure),
            $stream, $command, $expectedGeneration, $accessVersion, ['api_version' => $version,
                'report' => 'Snapchat asynchronous campaign metadata only; no CSV observations accepted',
                'documentation_basis' => 'https://developers.snap.com/api/marketing-api/Ads-API/measurement']);

        return new SnapchatReadAttempt($receipt, $failure, $delay);
    }

    public function attempt(Request $request, ProviderScope $scope, ReportWindow $window, ProviderReadBudget $budget,
        string $version, string $stream, string $command,
        int $expectedGeneration, int $accessVersion): SnapchatReadAttempt
    {
        if ($scope->provider !== Provider::Snapchat) {
            throw new InvalidArgumentException('Snapchat reader requires an exact Snapchat source.');
        }
        $guard = $this->requests->bind($request, $scope, 'provider.read', $accessVersion);
        $guard($scope, 'provider.read');
        (new SnapchatAccessStatus)->requireReport($request, $scope);
        $credentials = $this->custody->credentials($request, $scope);
        $cooldown = new SnapchatReadCooldown(app()->runningUnitTests() ? Cache::store() : Cache::store('redis'), $request, $scope, $accessVersion, $version);
        if (($remaining = $cooldown->remaining()) !== null && $remaining > 0) {
            $receipt = $this->journal->accept($request, new ObservationBatch($scope, [], 'FAILED', null, 'snapchat_native_cooldown'),
                $stream, $command, $expectedGeneration, $accessVersion, ['api_version' => $version, 'report' => 'Snapchat report deferred by native retry hint',
                    'documentation_basis' => 'https://developers.snap.com/api/marketing-api/Ads-API/measurement']);

            return new SnapchatReadAttempt($receipt, 'snapchat_native_cooldown', $remaining);
        }
        if ($credentials->value('account_id') !== $scope->accountId
            || $credentials->value('account_timezone') !== $window->timezone
            || ! preg_match('/^[A-Z]{3}$/D', $credentials->value('account_currency') ?? '')) {
            throw new InvalidArgumentException('Snapchat report requires the verified native account, timezone and currency.');
        }
        new SnapchatReadSettings($version);
        $adapter = new SnapchatObservationAdapter($this->http, $guard);
        $progress = $this->journal->progress($request, $scope, $stream, $accessVersion);
        $result = $adapter->read($scope, $credentials, $window, $budget, $progress);
        if (($seconds = $adapter->failure()?->retryAfterSeconds) !== null && $seconds > 0) {
            $cooldown->observe($seconds);
        }

        $receipt = $this->journal->accept($request, $result->batch, $stream, $command,
            $expectedGeneration, $accessVersion, $result->readProvenance, $result->progress);

        return new SnapchatReadAttempt($receipt, $adapter->failure()?->reason, $adapter->failure()?->retryAfterSeconds);
    }
}
