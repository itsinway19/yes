<?php

namespace App\Providers\Ads\Snapchat;

use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReadBudgetExhausted;
use App\Providers\Ads\ReportWindow;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;

/** Bounded server-only file retrieval, with no provider credentials and no observation acceptance. */
final readonly class SnapchatReportDownload
{
    private const MAX_BYTES = 8388608;

    public function __construct(private Factory $http, private \Closure $authority) {}

    public function fetch(ProviderScope $scope, ReportWindow $window, ProviderReadBudget $budget, array $ticket): string
    {
        if ($scope->jsonSerialize() !== $budget->scope->jsonSerialize()) {
            throw new InvalidArgumentException('Snapchat file cannot change the authorized read budget.');
        }
        $url = SnapchatAsyncReport::completedUrl($scope, $window, $ticket);
        ($this->authority)($scope, 'provider.read');
        $at = new DateTimeImmutable;
        $budget->claimRequest($at);
        $seconds = max(1, min(15, $budget->deadline->getTimestamp() - $at->getTimestamp()));
        $downloadDeadline = min($at->modify('+'.$seconds.' seconds'), $budget->deadline);
        $stream = null;
        try {
            // An independently created request excludes ambient headers/auth/cookies and middleware.
            // Factory fakes still apply in tests; no bearer credentials are accepted by this boundary.
            $response = $this->http->withoutGlobalConfiguration(fn () => $this->http->createPendingRequest()
                ->replaceHeaders(['Accept' => 'text/csv, text/plain, application/octet-stream'])
                ->connectTimeout(min(5, $seconds))->timeout($seconds)
                ->withOptions(['allow_redirects' => false, 'stream' => true, 'decode_content' => false, 'verify' => true,
                    'read_timeout' => min(5, $seconds)])
                ->get($url));
            $stream = $response->toPsrResponse()->getBody();
            if ($response->status() !== 200) {
                throw new SnapchatRequestFailed('snapchat_async_download_failed');
            }
            $type = strtolower(trim(explode(';', $response->header('Content-Type') ?? '')[0]));
            $encoding = strtolower(trim($response->header('Content-Encoding') ?? ''));
            $length = $response->header('Content-Length');
            if (! in_array($type, ['text/csv', 'application/csv', 'text/plain', 'application/octet-stream'], true)
                || ! in_array($encoding, ['', 'identity'], true)
                || ($length !== null && $length !== '' && (! preg_match('/^[0-9]{1,10}$/D', $length) || (int) $length > self::MAX_BYTES))) {
                throw new SnapchatRequestFailed('snapchat_async_file_unverified');
            }
            $body = '';
            while (! $stream->eof()) {
                if (new DateTimeImmutable >= $downloadDeadline) {
                    throw new ReadBudgetExhausted('deadline_exhausted');
                }
                $chunk = $stream->read(8192);
                if ($chunk === '' && ! $stream->eof()) {
                    throw new SnapchatRequestFailed('snapchat_async_download_failed');
                }
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    throw new SnapchatRequestFailed('snapchat_async_file_unverified');
                }
                $body .= $chunk;
            }
            if ($body === '' || ($length !== null && $length !== '' && strlen($body) !== (int) $length)) {
                throw new SnapchatRequestFailed('snapchat_async_file_unverified');
            }

            return $body;
        } catch (ConnectionException) {
            throw new SnapchatRequestFailed('snapchat_async_download_failed');
        } catch (SnapchatRequestFailed|ReadBudgetExhausted $error) {
            throw $error;
        } catch (\RuntimeException) {
            // Stream failures may contain native URLs; preserve only the safe local failure code.
            throw new SnapchatRequestFailed('snapchat_async_download_failed');
        } finally {
            $stream?->close();
        }
    }
}
