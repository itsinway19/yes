<?php

namespace Tests\Unit;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Providers\Ads\Snapchat\SnapchatReportDownload;
use App\Providers\Ads\Snapchat\SnapchatRequestFailed;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

final class SnapchatReportDownloadTest extends TestCase
{
    private const ACCOUNT = '8b8e40af-fc64-455d-925b-ca80f7af6914';

    private const RUN = 'ASYNC_STATS:'.self::ACCOUNT.':1727755200000:7245f6e6-eda4-4650-9255-6e3ab38b95ce';

    private function scope(): ProviderScope
    {
        return new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::Snapchat, self::ACCOUNT, 'report.read', 'advertising_analysis');
    }

    private function window(): ReportWindow
    {
        return new ReportWindow('2026-09-01T00:00:00+03:00', '2026-09-03T00:00:00+03:00', 'HALF_OPEN_INSTANTS', 'TOTAL', 'Asia/Amman', 'synthetic:native');
    }

    private function budget(): ProviderReadBudget
    {
        return new ProviderReadBudget($this->scope(), 'download-test', 1, 0, 1, 100, new DateTimeImmutable('+30 seconds'), 1, 5);
    }

    private function ticket(): array
    {
        return ['binding' => hash('sha256', json_encode([$this->scope()->jsonSerialize(), $this->window()->jsonSerialize(), 'v1',
            'campaign', 'impressions,swipes,spend', 'csv', self::RUN], JSON_THROW_ON_ERROR)),
            'run_id' => self::RUN, 'state' => 'COMPLETED',
            'download_url' => 'https://data-transfer.snapads.com/async-report/snap_ads/'.self::RUN.'/9496dff0-07b9-44d8-8f0b-b44eb276cc9e.csv'];
    }

    public function test_download_is_bounded_and_excludes_global_tokens_cookies_and_middleware(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->globalOptions(['headers' => ['Authorization' => 'Bearer synthetic-private', 'Cookie' => 'synthetic-session'], 'auth' => ['private', 'secret']]);
        $http->globalRequestMiddleware(fn ($r) => $r->withHeader('X-Private', 'synthetic-secret'));
        $http->fake(['*' => $http->response("synthetic,unmapped\n1,2\n", 200, ['Content-Type' => 'text/csv'])]);
        $calls = 0;
        $download = new SnapchatReportDownload($http, function ($scope, $action) use (&$calls): void {
            $this->assertSame($this->scope()->jsonSerialize(), $scope->jsonSerialize());
            $this->assertSame('provider.read', $action);
            $calls++;
        });
        $budget = $this->budget();
        $this->assertSame("synthetic,unmapped\n1,2\n", $download->fetch($this->scope(), $this->window(), $budget, $this->ticket()));
        $this->assertSame(1, $calls);
        $this->assertSame(1, $budget->usage()['requests']);
        $http->assertSent(fn ($r) => ! $r->hasHeader('Authorization') && ! $r->hasHeader('Cookie') && ! $r->hasHeader('X-Private') && $r->method() === 'GET');
        $http->assertSentCount(1);
    }

    public function test_untrusted_or_unfinished_ticket_is_rejected_before_request(): void
    {
        foreach (['host', 'binding', 'state'] as $case) {
            $http = new Factory;
            $http->preventStrayRequests();
            $ticket = $this->ticket();
            if ($case === 'host') {
                $ticket['download_url'] = str_replace('data-transfer.snapads.com', 'evil.example', $ticket['download_url']);
            }
            if ($case === 'binding') {
                $ticket['binding'] = 'foreign';
            }
            if ($case === 'state') {
                $ticket['state'] = 'RUNNING';
            }
            try {
                (new SnapchatReportDownload($http, static fn () => null))->fetch($this->scope(), $this->window(), $this->budget(), $ticket);
                $this->fail('Invalid ticket must not download.');
            } catch (\InvalidArgumentException) {
                $http->assertNothingSent();
            }
        }
    }

    public function test_redirect_html_compressed_and_oversized_files_fail_without_acceptance(): void
    {
        foreach (['redirect', 'html', 'compressed', 'size'] as $case) {
            $http = new Factory;
            $http->preventStrayRequests();
            $headers = ['Content-Type' => $case === 'html' ? 'text/html' : 'text/csv'];
            if ($case === 'redirect') {
                $headers['Location'] = 'https://evil.example/private';
            }
            if ($case === 'compressed') {
                $headers['Content-Encoding'] = 'gzip';
            }
            if ($case === 'size') {
                $headers['Content-Length'] = '8388609';
            }
            $http->fake(['*' => $http->response('synthetic-private-body', $case === 'redirect' ? 302 : 200, $headers)]);
            try {
                (new SnapchatReportDownload($http, static fn () => null))->fetch($this->scope(), $this->window(), $this->budget(), $this->ticket());
                $this->fail('Unverified file must fail.');
            } catch (SnapchatRequestFailed $error) {
                $this->assertStringNotContainsString('synthetic-private-body', $error->getMessage());
            }
            $http->assertSentCount(1);
        }
    }

    public function test_current_authority_denial_prevents_file_request(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('authority-denied');
        try {
            (new SnapchatReportDownload($http, static fn () => throw new \RuntimeException('authority-denied')))
                ->fetch($this->scope(), $this->window(), $this->budget(), $this->ticket());
        } finally {
            $http->assertNothingSent();
        }
    }

    public function test_missing_or_false_size_headers_cannot_bypass_the_stream_byte_limit(): void
    {
        foreach ([[], ['Content-Length' => '12']] as $sizeHeaders) {
            $http = new Factory;
            $http->preventStrayRequests();
            // Explicit memory stream avoids php://temp spilling into the host's restricted temporary directory.
            $nativeStream = fopen('php://memory', 'w+');
            fwrite($nativeStream, str_repeat('x', 8388609));
            rewind($nativeStream);
            $http->fake(['*' => $http->response($nativeStream, 200, ['Content-Type' => 'text/csv'] + $sizeHeaders)]);
            try {
                (new SnapchatReportDownload($http, static fn () => null))->fetch($this->scope(), $this->window(), $this->budget(), $this->ticket());
                $this->fail('Stream must remain bounded without a trusted size header.');
            } catch (SnapchatRequestFailed $error) {
                $this->assertSame('snapchat_async_file_unverified', $error->reason);
            }
            $http->assertSentCount(1);
        }
    }

    public function test_empty_or_truncated_files_are_not_accepted(): void
    {
        foreach (['', 'short'] as $body) {
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->response($body, 200, ['Content-Type' => 'text/csv', 'Content-Length' => '100'])]);
            try {
                (new SnapchatReportDownload($http, static fn () => null))->fetch($this->scope(), $this->window(), $this->budget(), $this->ticket());
                $this->fail('Empty or truncated stream must fail.');
            } catch (SnapchatRequestFailed $error) {
                $this->assertSame('snapchat_async_file_unverified', $error->reason);
            }
        }
    }

    public function test_stream_exception_is_redacted_and_the_connection_is_closed(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('eof')->willReturn(false);
        $stream->method('read')->willThrowException(new \RuntimeException('synthetic-private-url-and-body'));
        $stream->expects($this->once())->method('close');
        $http->fake(['*' => $http->response($stream, 200, ['Content-Type' => 'text/csv'])]);
        try {
            (new SnapchatReportDownload($http, static fn () => null))->fetch($this->scope(), $this->window(), $this->budget(), $this->ticket());
            $this->fail('Broken stream must fail.');
        } catch (SnapchatRequestFailed $error) {
            $this->assertSame('snapchat_async_download_failed', $error->reason);
            $this->assertNull($error->getPrevious());
        }
    }
}
