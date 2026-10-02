<?php

namespace App\Providers\Ads\Meta;

use App\Providers\Ads\AdObservation;
use App\Providers\Ads\NativeAmount;
use App\Providers\Ads\NativeProgress;
use App\Providers\Ads\ObservationBatch;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderObservationAdapter;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderReadResult;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReadBudgetExhausted;
use App\Providers\Ads\ReportWindow;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use RuntimeException;

/** Server-only, read-only Graph transport. Callers must first obtain exact-scope current custody authority. */
final class MetaObservationAdapter implements ProviderObservationAdapter
{
    private readonly MetaGraphClient $client;

    private ?MetaRequestFailed $lastFailure = null;

    public function failure(): ?MetaRequestFailed
    {
        return $this->lastFailure;
    }

    public function retryDelay(): ?int
    {
        return $this->lastFailure?->retryAfterSeconds ?? $this->client->cooldownSeconds();
    }

    private const BASIS = 'https://developers.facebook.com/docs/marketing-api/reference/ad-account/insights/';

    public function __construct(Factory $http, private readonly string $version,
        #[\SensitiveParameter] string $appSecret, \Closure $currentAuthority, ?MetaReadCooldown $sharedCooldown = null)
    {
        $this->client = new MetaGraphClient($http, $version, $appSecret, $currentAuthority, 'provider.read', $sharedCooldown);
    }

    public function provider(): Provider
    {
        return Provider::Meta;
    }

    public function read(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $credentials,
        ReportWindow $window, ProviderReadBudget $budget, ?NativeProgress $progress): ProviderReadResult
    {
        $this->lastFailure = null;
        $query = $this->query($scope, $window, $budget);
        // Accepted range receipts never skip a reread: Meta may later restate the same range.
        if ($progress !== null && $progress->kind !== 'meta.accepted_range') {
            throw new InvalidArgumentException('Meta only consumes its own accepted-range progress.');
        }

        return $this->pages($scope, $credentials, $window, $budget, 'act_'.$scope->accountId.'/insights', $query);
    }

    /** Creates a reporting computation only, never a campaign/account mutation. Task state is transient. */
    public function startAsync(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $credentials,
        ReportWindow $window, ProviderReadBudget $budget): MetaReportTask
    {
        $query = $this->query($scope, $window, $budget);
        $response = $this->request('POST', 'act_'.$scope->accountId.'/insights', $query, $credentials, $budget);
        $id = $response['report_run_id'] ?? null;
        if (! is_string($id) && ! is_int($id)) {
            throw new RuntimeException('meta_malformed_report_task');
        }

        return new MetaReportTask((string) $id, $this->fingerprint($scope, $window), new DateTimeImmutable);
    }

    /** One bounded status poll per call; scheduling/backoff is owned by the provider queue. */
    public function readAsync(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $credentials,
        ReportWindow $window, ProviderReadBudget $budget, MetaReportTask $task): ProviderReadResult
    {
        $this->lastFailure = null;
        $this->query($scope, $window, $budget);
        if (! hash_equals($task->requestFingerprint, $this->fingerprint($scope, $window))
            || $task->createdAt > new DateTimeImmutable || $task->createdAt->modify('+30 days') <= new DateTimeImmutable) {
            throw new InvalidArgumentException('Meta report task scope, range or lifetime mismatch.');
        }
        try {
            $status = $this->request('GET', $task->id,
                ['fields' => 'id,account_id,async_status,async_percent_completion'], $credentials, $budget);
        } catch (MetaRequestFailed $exception) {
            $this->lastFailure = $exception;

            return $this->result($scope, [], 'FAILED', $exception->reason);
        }
        if ((string) ($status['id'] ?? '') !== $task->id || (string) ($status['account_id'] ?? '') !== $scope->accountId) {
            throw new RuntimeException('meta_report_task_scope_mismatch');
        }
        if (isset($status['error_code']) || in_array($status['async_status'] ?? null, ['Job Failed', 'Job Skipped'], true)) {
            return $this->result($scope, [], 'FAILED', 'meta_report_task_failed');
        }
        if (($status['async_status'] ?? null) !== 'Job Completed' || ($status['async_percent_completion'] ?? null) !== 100) {
            if (! in_array($status['async_status'] ?? null, ['Job Not Started', 'Job Started', 'Job Running', 'Job Completed'], true)
                || ! is_int($status['async_percent_completion'] ?? null)
                || $status['async_percent_completion'] < 0 || $status['async_percent_completion'] > 100) {
                throw new RuntimeException('meta_malformed_report_status');
            }

            return $this->result($scope, [], 'PENDING');
        }

        return $this->pages($scope, $credentials, $window, $budget, $task->id.'/insights', ['fields' => $this->fields()]);
    }

    private function query(ProviderScope $scope, ReportWindow $window, ProviderReadBudget $budget): array
    {
        if ($scope->provider !== Provider::Meta || $scope->operation !== 'report.read'
            || ! preg_match('/^[1-9][0-9]{0,39}$/D', $scope->accountId)
            || $scope->jsonSerialize() !== $budget->scope->jsonSerialize()
            || $window->boundary !== 'INCLUSIVE_DATES' || ! in_array($window->grain, ['DAY', 'TOTAL'], true)
            || $window->timezone === null) {
            throw new InvalidArgumentException('Exact Meta reporting scope and verified account-local date window required.');
        }

        return ['level' => 'campaign', 'fields' => $this->fields(),
            'time_range' => json_encode(['since' => $window->start, 'until' => $window->end], JSON_THROW_ON_ERROR),
            'time_increment' => $window->grain === 'DAY' ? '1' : 'all_days', 'limit' => 100];
    }

    private function fields(): string
    {
        return 'account_id,account_currency,campaign_id,date_start,date_stop,spend,impressions,clicks';
    }

    private function fingerprint(ProviderScope $scope, ReportWindow $window): string
    {
        return hash('sha256', json_encode([$scope->jsonSerialize(), $window->jsonSerialize(), $this->version, $this->fields()], JSON_THROW_ON_ERROR));
    }

    private function pages(ProviderScope $scope, ProviderCredentials $credentials, ReportWindow $window,
        ProviderReadBudget $budget, string $path, array $query): ProviderReadResult
    {
        $observations = [];
        $seen = [];
        $cursors = [];
        try {
            do {
                $page = $this->request('GET', $path, $query, $credentials, $budget);
                if (! isset($page['data']) || ! is_array($page['data']) || ! array_is_list($page['data'])) {
                    throw new RuntimeException('meta_malformed_report_page');
                }
                $budget->acceptPage(count($page['data']), new DateTimeImmutable);
                foreach ($page['data'] as $row) {
                    $observation = $this->map($row, $scope, $window);
                    if (isset($seen[$observation->identity()])) {
                        throw new RuntimeException('meta_duplicate_measurement_slot');
                    }
                    $seen[$observation->identity()] = true;
                    $observations[] = $observation;
                }
                $paging = $page['paging'] ?? [];
                if (! is_array($paging)) {
                    throw new RuntimeException('meta_malformed_paging');
                }
                $more = isset($paging['next']);
                if ($more) {
                    $after = $paging['cursors']['after'] ?? null;
                    if (! is_string($paging['next']) || $paging['next'] === '' || ! is_string($after)
                        || $after === '' || strlen($after) > 4096 || isset($cursors[$after])) {
                        throw new RuntimeException('meta_invalid_or_repeated_cursor');
                    }
                    // Never follow provider-returned URLs, which can carry tokens or another host/account.
                    $cursors[$after] = true;
                    $query['after'] = $after;
                }
            } while ($more);
        } catch (ReadBudgetExhausted) {
            return $this->result($scope, $observations, 'PARTIAL', 'meta_local_budget_exhausted');
        } catch (MetaRequestFailed $exception) {
            $this->lastFailure = $exception;

            return $this->result($scope, [], 'FAILED', $exception->reason);
        } catch (QueryException $exception) {
            // Database authority failure aborts the caller-owned transaction; never map it as a provider failure.
            throw $exception;
        } catch (RuntimeException|InvalidArgumentException $exception) {
            // Discard this unresolved range rather than expose a malformed prefix as complete knowledge.
            return $this->result($scope, [], 'FAILED', 'meta_read_rejected');
        }
        $progress = new NativeProgress('meta.accepted_range', self::BASIS,
            json_encode(['request_fingerprint' => $this->fingerprint($scope, $window),
                'range' => $window->jsonSerialize(), 'read_at' => (new DateTimeImmutable)->format(DATE_ATOM)], JSON_THROW_ON_ERROR));

        return $this->result($scope, $observations, 'COMPLETE', null, $progress);
    }

    private function map(mixed $row, ProviderScope $scope, ReportWindow $window): AdObservation
    {
        if (! is_array($row) || ($row['account_id'] ?? null) !== $scope->accountId
            || ! is_string($row['campaign_id'] ?? null) || ! preg_match('/^[1-9][0-9]{0,39}$/D', $row['campaign_id'])
            || ! is_string($row['date_start'] ?? null) || ! is_string($row['date_stop'] ?? null)
            || $row['date_start'] < $window->start || $row['date_stop'] > $window->end
            || ($window->grain === 'DAY' && $row['date_start'] !== $row['date_stop'])
            || ($window->grain === 'TOTAL' && ($row['date_start'] !== $window->start || $row['date_stop'] !== $window->end))
            || (isset($row['spend']) && ! is_string($row['spend']))
            || ! is_string($row['account_currency'] ?? null)) {
            throw new RuntimeException('meta_malformed_or_out_of_scope_row');
        }
        $facts = [];
        foreach (['impressions', 'clicks'] as $field) {
            $value = $row[$field] ?? null;
            if ($value !== null && (! is_string($value) || ! preg_match('/^\d{1,60}$/D', $value))) {
                throw new RuntimeException('meta_malformed_delivery_fact');
            }
            $facts[$field] = ['value' => $value, 'unit' => 'count', 'meaning' => 'Meta Insights '.$field,
                'basis' => $value === null ? 'UNKNOWN' : 'PROVIDER_REPORTED'];
        }

        return new AdObservation($scope, $row['campaign_id'],
            new ReportWindow($row['date_start'], $row['date_stop'], 'INCLUSIVE_DATES', $window->grain,
                $window->timezone, 'Meta Insights account-local dates; until includes the full final day'),
            new NativeAmount($row['spend'] ?? null, $row['account_currency'], 'meta.account_currency_major', 0, self::BASIS),
            $facts, $this->provenance(), new DateTimeImmutable, null);
    }

    private function provenance(): array
    {
        return ['api_version' => $this->version, 'report' => 'Meta Insights campaign-level', 'documentation_basis' => self::BASIS];
    }

    private function result(ProviderScope $scope, array $observations, string $coverage, ?string $failure = null,
        ?NativeProgress $progress = null): ProviderReadResult
    {
        return new ProviderReadResult(new ObservationBatch($scope, $observations, $coverage, null, $failure), $this->provenance(), $progress);
    }

    private function request(string $method, string $path, array $parameters, ProviderCredentials $credentials,
        ProviderReadBudget $budget): array
    {
        return $this->client->request($method, $path, $parameters, $credentials, $budget);
    }
}
