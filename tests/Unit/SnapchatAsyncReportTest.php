<?php

namespace Tests\Unit;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Providers\Ads\Snapchat\SnapchatAsyncReport;
use App\Providers\Ads\Snapchat\SnapchatRequestFailed;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

final class SnapchatAsyncReportTest extends TestCase
{
    private const ACCOUNT = '8b8e40af-fc64-455d-925b-ca80f7af6914';

    private const RUN = 'ASYNC_STATS:'.self::ACCOUNT.':1727755200000:7245f6e6-eda4-4650-9255-6e3ab38b95ce';

    private function scope(): ProviderScope
    {
        return new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::Snapchat,
            self::ACCOUNT, 'report.read', 'advertising_analysis');
    }

    private function window(): ReportWindow
    {
        return new ReportWindow('2026-09-01T00:00:00+03:00', '2026-09-03T00:00:00+03:00',
            'HALF_OPEN_INSTANTS', 'TOTAL', 'Asia/Amman', 'synthetic:native');
    }

    private function budget(): ProviderReadBudget
    {
        return new ProviderReadBudget($this->scope(), 'async-test', 5, 0, 5, 100,
            new DateTimeImmutable('+30 seconds'), 1, 5);
    }

    private function credentials(): ProviderCredentials
    {
        return new ProviderCredentials(['access_token' => 'synthetic-private-token', 'account_id' => self::ACCOUNT,
            'account_timezone' => 'Asia/Amman', 'expires_at' => (string) (time() + 300)]);
    }

    private function response(string $state, ?string $url = null, string $id = self::RUN): array
    {
        return ['request_status' => 'SUCCESS', 'async_stats_reports' => [['sub_request_status' => 'SUCCESS',
            'async_stats_report' => ['report_run_id' => $id, 'async_status' => $state] + ($url === null ? [] : ['result' => $url])]]];
    }

    public function test_start_and_poll_preserve_scope_window_and_do_not_download_or_accept_observations(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $url = 'https://data-transfer.snapads.com/async-report/snap_ads/'.self::RUN.'/9496dff0-07b9-44d8-8f0b-b44eb276cc9e.csv';
        $http->fake(['*' => $http->sequence()->push($this->response('STARTED'))->push($this->response('RUNNING'))
            ->push($this->response('COMPLETED', $url))]);
        $calls = 0;
        $client = new SnapchatAsyncReport($http, function ($scope, $action) use (&$calls): void {
            $this->assertSame($this->scope()->jsonSerialize(), $scope->jsonSerialize());
            $this->assertSame('provider.read', $action);
            $calls++;
        });
        $budget = $this->budget();
        $ticket = $client->start($this->scope(), $this->credentials(), $this->window(), $budget);
        $this->assertSame('STARTED', $ticket['state']);
        $this->assertNull($ticket['download_url']);
        $running = $client->poll($this->scope(), $this->credentials(), $this->window(), $budget, $ticket);
        $this->assertSame('RUNNING', $running['state']);
        $complete = $client->poll($this->scope(), $this->credentials(), $this->window(), $budget, $running);
        $this->assertSame($url, $complete['download_url']);
        $this->assertSame('COMPLETED', $complete['state']);
        $this->assertArrayNotHasKey('progress', $complete);
        $this->assertSame(3, $calls);
        $http->assertSentCount(3);
        $http->assertSent(fn ($r) => ($r->data()['async'] ?? null) === 'true' && $r['async_format'] === 'csv' && $r['breakdown'] === 'campaign');
        $http->assertSent(fn ($r) => str_contains($r->url(), '/stats_report?') && ($r->data()['report_run_id'] ?? null) === self::RUN);
    }

    public function test_untrusted_download_locations_fail_without_download(): void
    {
        $valid = 'https://data-transfer.snapads.com/async-report/snap_ads/'.self::RUN.'/9496dff0-07b9-44d8-8f0b-b44eb276cc9e.csv';
        foreach ([str_replace('data-transfer.snapads.com', 'evil.example', $valid),
            str_replace('https:', 'http:', $valid), $valid.'?secret=value', $valid.'#fragment',
            str_replace('data-transfer.snapads.com', 'user@data-transfer.snapads.com', $valid),
            str_replace('.csv', '.xlsx', $valid), str_replace('/snap_ads/', '/other/', $valid),
            str_replace('1727755200000', '1727755200001', $valid)] as $url) {
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->response($this->response('COMPLETED', $url))]);
            try {
                (new SnapchatAsyncReport($http, static fn () => null))->start($this->scope(), $this->credentials(), $this->window(), $this->budget());
                $this->fail('Untrusted download must not be accepted.');
            } catch (SnapchatRequestFailed $error) {
                $this->assertSame('snapchat_async_result_unverified', $error->reason);
            }
            $http->assertSentCount(1);
        }
    }

    public function test_changed_ticket_binding_is_rejected_before_polling(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response($this->response('STARTED'))]);
        $client = new SnapchatAsyncReport($http, static fn () => null);
        $ticket = $client->start($this->scope(), $this->credentials(), $this->window(), $this->budget());
        $ticket['binding'] = 'foreign';
        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->poll($this->scope(), $this->credentials(), $this->window(), $this->budget(), $ticket);
        } finally {
            $http->assertSentCount(1);
        }
    }

    public function test_unknown_native_states_wrong_report_ids_and_failed_wrappers_are_never_completed(): void
    {
        $unknown = $this->response('UNKNOWN');
        $failed = $this->response('RUNNING');
        $failed['async_stats_reports'][0]['sub_request_status'] = 'ERROR';
        $duplicate = $this->response('STARTED');
        $duplicate['async_stats_reports'][] = $duplicate['async_stats_reports'][0];
        $foreign = $this->response('RUNNING', null, str_replace(self::ACCOUNT, '22ada972-f2aa-4d06-a45a-a7a80f53ae34', self::RUN));
        foreach ([$unknown, $failed, $duplicate, $foreign] as $payload) {
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->response($payload)]);
            try {
                (new SnapchatAsyncReport($http, static fn () => null))->start($this->scope(), $this->credentials(), $this->window(), $this->budget());
                $this->fail('Unverified job must fail.');
            } catch (SnapchatRequestFailed $error) {
                $this->assertSame('snapchat_async_status_unverified', $error->reason);
            }
            $http->assertSentCount(1);
        }
    }

    public function test_poll_response_cannot_substitute_another_job_in_the_same_account(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->response('STARTED'))
            ->push($this->response('RUNNING', null, str_replace('1727755200000', '1727755200001', self::RUN)))]);
        $client = new SnapchatAsyncReport($http, static fn () => null);
        $ticket = $client->start($this->scope(), $this->credentials(), $this->window(), $this->budget());
        $this->expectException(SnapchatRequestFailed::class);
        $this->expectExceptionMessage('snapchat_async_status_unverified');
        try {
            $client->poll($this->scope(), $this->credentials(), $this->window(), $this->budget(), $ticket);
        } finally {
            $http->assertSentCount(2);
        }
    }

    public function test_expired_credentials_and_revoked_authority_prevent_creation(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $client = new SnapchatAsyncReport($http, static fn () => throw new \RuntimeException('authority-denied'));
        $values = $this->credentials()->custodyPayload();
        $values['expires_at'] = (string) (time() - 1);
        try {
            $client->start($this->scope(), new ProviderCredentials($values), $this->window(), $this->budget());
            $this->fail('Expired token must not be sent.');
        } catch (SnapchatRequestFailed $error) {
            $this->assertSame('snapchat_credential_denied', $error->reason);
        }
        try {
            $client->start($this->scope(), $this->credentials(), $this->window(), $this->budget());
            $this->fail('Revoked authority must prevent native creation.');
        } catch (\RuntimeException $error) {
            $this->assertSame('authority-denied', $error->getMessage());
        }
        $http->assertNothingSent();
    }
}
