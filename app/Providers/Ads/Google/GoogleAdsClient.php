<?php

namespace App\Providers\Ads\Google;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use JsonException;

/** Fixed-host Google Ads REST reads only. POST Search computes a read; no mutate endpoint exists here. */
final readonly class GoogleAdsClient
{
    public function __construct(private Factory $http, private string $version,
        #[\SensitiveParameter] private string $developerToken, private \Closure $currentAuthority, private string $action)
    {
        if (! preg_match('/^v(?:[2-9][0-9]|1[9])$/D', $version)
            || preg_match('/[\x00-\x1f\x7f]/', $developerToken)
            || ! in_array($action, ['provider.connect', 'provider.read'], true)) {
            throw new InvalidArgumentException('Supported Google API version, valid optional developer token and request guard required.');
        }
    }

    public static function customerId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[1-9][0-9]{9}$/D', $id) === 1;
    }

    /** A successful empty response still has the selected-field mask; {} is not a proven empty report. */
    public static function rows(array $page, array $requiredFields): array
    {
        $mask = $page['fieldMask'] ?? null;
        $rows = $page['results'] ?? [];
        if (! is_string($mask) || array_diff($requiredFields, explode(',', $mask))
            || ! is_array($rows) || ! array_is_list($rows) || count($rows) > 10000) {
            throw new GoogleRequestFailed('google_malformed_search_page');
        }

        return $rows;
    }

    public function accessible(#[\SensitiveParameter] ProviderCredentials $credentials, ProviderReadBudget $budget): array
    {
        if ($this->action !== 'provider.connect') {
            throw new InvalidArgumentException('Account discovery requires its own connection authority.');
        }

        // ListAccessibleCustomers is direct OAuth access only and ignores login-customer-id.
        return $this->request('GET', 'customers:listAccessibleCustomers', [], $credentials, $budget, null);
    }

    public function search(string $customer, string $query, #[\SensitiveParameter] ProviderCredentials $credentials,
        ProviderReadBudget $budget, ?string $loginCustomer = null, ?string $pageToken = null): array
    {
        if (! self::customerId($customer) || ($loginCustomer !== null && ! self::customerId($loginCustomer))
            || ($this->action === 'provider.read' && $customer !== $budget->scope->accountId)
            || ! preg_match('/^SELECT\s/i', $query) || strlen($query) > 4096
            || preg_match('/[\x00-\x1f\x7f;]/', $query)
            || ($pageToken !== null && ($pageToken === '' || strlen($pageToken) > 8192))) {
            throw new InvalidArgumentException('Exact Google customer and bounded read-only query required.');
        }
        $body = ['query' => $query];
        if ($pageToken !== null) {
            $body['pageToken'] = $pageToken;
        }

        return $this->request('POST', 'customers/'.$customer.'/googleAds:search', $body, $credentials, $budget, $loginCustomer);
    }

    private function request(string $method, string $path, array $body, ProviderCredentials $credentials,
        ProviderReadBudget $budget, ?string $loginCustomer): array
    {
        if ($budget->scope->provider !== Provider::Google) {
            throw new InvalidArgumentException('Google cannot consume a sibling provider budget.');
        }
        // Authority failures are deliberately outside transport exception handling.
        ($this->currentAuthority)($budget->scope, $this->action);
        $now = new DateTimeImmutable;
        $expiry = $credentials->custodyPayload()['expires_at'] ?? null;
        if (! is_string($expiry) || ! preg_match('/^[0-9]{1,12}$/D', $expiry) || (int) $expiry <= $now->getTimestamp()) {
            throw new GoogleRequestFailed('google_credential_reverification_required');
        }
        $budget->claimRequest($now);
        $seconds = max(1, min(15, $budget->deadline->getTimestamp() - $now->getTimestamp()));
        try {
            // API access belongs to the OAuth client's Cloud project. Legacy tokens are optional.
            $headers = $this->developerToken === '' ? [] : ['developer-token' => $this->developerToken];
            if ($loginCustomer !== null) {
                $headers['login-customer-id'] = $loginCustomer;
            }
            $request = $this->http->acceptJson()->withToken($credentials->value('access_token'))
                ->withHeaders($headers)->connectTimeout(min(5, $seconds))->timeout($seconds)
                ->withOptions(['allow_redirects' => false]);
            $url = 'https://googleads.googleapis.com/'.$this->version.'/'.$path;
            $response = $method === 'GET' ? $request->get($url) : $request->post($url, $body);
            if (strlen($response->body()) > 33554432) {
                throw new GoogleRequestFailed('google_response_too_large');
            }
            if (! $response->successful()) {
                // Error gateways need not produce JSON. Native JSON status is optional error metadata.
                $errorBody = json_decode($response->body(), true, 64, JSON_BIGINT_AS_STRING);
                throw $this->failure($response, is_array($errorBody) ? $errorBody : []);
            }
            // Protobuf DOUBLE metrics arrive as JSON numbers. Retain their wire lexemes,
            // skipping complete quoted strings, before PHP can round them to binary floats.
            $json = preg_replace_callback('/"(?:\\\\.|[^"\\\\])*"|(-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/s',
                static fn (array $token): string => isset($token[1]) && strpbrk($token[1], '.eE') !== false
                    ? '"'.$token[1].'"' : $token[0], $response->body());
            if ($json === null) {
                throw new JsonException('Native numeric decoding failed.');
            }
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($payload) || isset($payload['error'])) {
                throw $this->failure($response, is_array($payload) ? $payload : []);
            }

            return $payload;
        } catch (ConnectionException) {
            throw new GoogleRequestFailed('google_transport_failed');
        } catch (JsonException) {
            throw new GoogleRequestFailed('google_malformed_response');
        }
    }

    private function failure(Response $response, array $payload): GoogleRequestFailed
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $status = $error['status'] ?? null;
        $reason = match (true) {
            $response->status() === 401 || $status === 'UNAUTHENTICATED' => 'google_credential_denied',
            $response->status() === 429 || $status === 'RESOURCE_EXHAUSTED' => 'google_rate_limited',
            $response->status() === 403 || $status === 'PERMISSION_DENIED' => 'google_access_denied',
            $response->status() >= 500 => 'google_service_unavailable',
            default => 'google_request_failed',
        };

        try {
            return new GoogleRequestFailed($reason, $this->retryAfter($response->header('Retry-After')));
        } catch (GoogleRequestFailed $hintFailure) {
            // Non-retryable native denial must remain visible even if a gateway adds a bad hint.
            if (in_array($reason, ['google_credential_denied', 'google_access_denied'], true)) {
                return new GoogleRequestFailed($reason);
            }
            throw $hintFailure;
        }
    }

    private function retryAfter(?string $hint): ?int
    {
        if ($hint === null || $hint === '') {
            return null;
        }
        if (preg_match('/^[0-9]{1,8}$/D', $hint) && (int) $hint <= 31536000) {
            return (int) $hint;
        }
        $format = 'D, d M Y H:i:s \G\M\T';
        $date = DateTimeImmutable::createFromFormat('!'.$format, $hint, new \DateTimeZone('UTC'));
        if ($date !== false && $date->format($format) === $hint) {
            $delay = max(0, $date->getTimestamp() - time());
            if ($delay <= 31536000) {
                return $delay;
            }
        }
        throw new GoogleRequestFailed('google_retry_hint_unverified');
    }

    public function __debugInfo(): array
    {
        return ['version' => $this->version, 'developer_token' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Google transport contains private server configuration.');
    }
}
