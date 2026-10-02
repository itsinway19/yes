<?php

namespace App\Providers\Ads\Snapchat;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;

/** Native job metadata only. COMPLETED is not accepted CSV/observations or durable range progress. */
final readonly class SnapchatAsyncReport
{
    private SnapchatClient $client;

    public function __construct(Factory $http, \Closure $authority)
    {
        $this->client = new SnapchatClient($http, $authority, 'provider.read');
    }

    public static function runId(mixed $id, string $account): bool
    {
        return is_string($id) && preg_match('/^ASYNC_STATS:'.preg_quote($account, '/').':[0-9]{1,16}:[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $id) === 1;
    }

    public function start(ProviderScope $scope, ProviderCredentials $credentials, ReportWindow $window, ProviderReadBudget $budget): array
    {
        $this->requireScope($scope, $credentials, $window, $budget);
        $body = $this->client->get('adaccounts/'.$scope->accountId.'/stats', [
            'granularity' => 'TOTAL', 'breakdown' => 'campaign', 'fields' => 'impressions,swipes,spend',
            'start_time' => $window->start, 'end_time' => $window->end, 'async' => 'true', 'async_format' => 'csv',
        ], $credentials, $budget);

        return $this->ticket($body, $scope, $window);
    }

    /** Caller must retain this server-only ticket in encrypted, epoch-bound execution custody before another job. */
    public function poll(ProviderScope $scope, ProviderCredentials $credentials, ReportWindow $window,
        ProviderReadBudget $budget, array $ticket): array
    {
        $this->requireScope($scope, $credentials, $window, $budget);
        if (array_diff(array_keys($ticket), ['binding', 'run_id', 'state', 'download_url'])
            || ! is_string($ticket['run_id'] ?? null)
            || ($ticket['binding'] ?? null) !== self::binding($scope, $window, $ticket['run_id'])
            || ! self::runId($ticket['run_id'] ?? null, $scope->accountId)
            || ! in_array($ticket['state'] ?? null, ['STARTED', 'RUNNING'], true)
            || ! array_key_exists('download_url', $ticket) || $ticket['download_url'] !== null) {
            throw new InvalidArgumentException('Snapchat asynchronous ticket changed its authorized report binding.');
        }
        $body = $this->client->get('adaccounts/'.$scope->accountId.'/stats_report',
            ['report_run_id' => $ticket['run_id']], $credentials, $budget);

        return $this->ticket($body, $scope, $window, $ticket['run_id']);
    }

    private function ticket(array $body, ProviderScope $scope, ReportWindow $window, ?string $expected = null): array
    {
        $rows = $body['async_stats_reports'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== 1 || ! is_array($rows[0])
            || ! SnapchatClient::success($rows[0]['sub_request_status'] ?? null) || ! empty($body['paging'])) {
            throw new SnapchatRequestFailed('snapchat_async_status_unverified');
        }
        $report = $rows[0]['async_stats_report'] ?? null;
        if (! is_array($report) || ! self::runId($report['report_run_id'] ?? null, $scope->accountId)
            || ($expected !== null && $report['report_run_id'] !== $expected)
            || ! in_array($report['async_status'] ?? null, ['STARTED', 'RUNNING', 'COMPLETED'], true)) {
            throw new SnapchatRequestFailed('snapchat_async_status_unverified');
        }
        $url = null;
        if ($report['async_status'] === 'COMPLETED') {
            $url = $report['result'] ?? null;
            if (! self::downloadUrl($url, $report['report_run_id'])) {
                throw new SnapchatRequestFailed('snapchat_async_result_unverified');
            }
        } elseif (array_key_exists('result', $report)) {
            throw new SnapchatRequestFailed('snapchat_async_result_unverified');
        }

        return ['binding' => self::binding($scope, $window, $report['report_run_id']), 'run_id' => $report['report_run_id'],
            'state' => $report['async_status'], 'download_url' => $url];
    }

    public static function completedUrl(ProviderScope $scope, ReportWindow $window, array $ticket): string
    {
        if ($scope->provider !== Provider::Snapchat || $scope->operation !== 'report.read' || $scope->purpose !== 'advertising_analysis'
            || $window->boundary !== 'HALF_OPEN_INSTANTS' || $window->grain !== 'TOTAL' || $window->timezone === null
            || array_diff(array_keys($ticket), ['binding', 'run_id', 'state', 'download_url'])
            || ! self::runId($ticket['run_id'] ?? null, $scope->accountId)
            || ($ticket['binding'] ?? null) !== self::binding($scope, $window, $ticket['run_id'])
            || ($ticket['state'] ?? null) !== 'COMPLETED'
            || ! self::downloadUrl($ticket['download_url'] ?? null, $ticket['run_id'])) {
            throw new InvalidArgumentException('Snapchat file requires a completed exact report ticket.');
        }

        return $ticket['download_url'];
    }

    private static function downloadUrl(mixed $url, string $runId): bool
    {
        return is_string($url) && strlen($url) <= 2048 && preg_match(
            '#^https://data-transfer\.snapads\.com/async-report/snap_ads/'.preg_quote($runId, '#').
            '/[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\.csv$#D', $url) === 1;
    }

    private static function binding(ProviderScope $scope, ReportWindow $window, string $runId): string
    {
        return hash('sha256', json_encode([$scope->jsonSerialize(), $window->jsonSerialize(), 'v1',
            'campaign', 'impressions,swipes,spend', 'csv', $runId], JSON_THROW_ON_ERROR));
    }

    private function requireScope(ProviderScope $scope, ProviderCredentials $credentials, ReportWindow $window, ProviderReadBudget $budget): void
    {
        $values = $credentials->custodyPayload();
        if ($scope->provider !== Provider::Snapchat || $scope->operation !== 'report.read' || $scope->purpose !== 'advertising_analysis'
            || $scope->jsonSerialize() !== $budget->scope->jsonSerialize() || ! SnapchatClient::accountId($scope->accountId)
            || ($values['account_id'] ?? null) !== $scope->accountId || ($values['account_timezone'] ?? null) !== $window->timezone
            || $window->timezone === null || $window->grain !== 'TOTAL' || $window->boundary !== 'HALF_OPEN_INSTANTS') {
            throw new InvalidArgumentException('Snapchat asynchronous reports require exact selected account and native TOTAL window.');
        }
        if (! is_string($values['expires_at'] ?? null) || ! preg_match('/^[1-9][0-9]{0,10}$/D', $values['expires_at'])
            || (int) $values['expires_at'] <= time()) {
            throw new SnapchatRequestFailed('snapchat_credential_denied');
        }
    }
}
