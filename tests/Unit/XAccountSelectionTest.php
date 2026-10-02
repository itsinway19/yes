<?php

namespace Tests\Unit;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\X\XAccountSelection;
use App\Providers\Ads\X\XOAuthSigner;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

final class XAccountSelectionTest extends TestCase
{
    private function budget(): ProviderReadBudget
    {
        return new ProviderReadBudget(new ProviderScope(7, '11111111-1111-7111-8111-111111111111', Provider::X,
            'abc123', 'report.read', 'advertising_analysis'), 'selection', 2, 0, 2, 2, new DateTimeImmutable('+30 seconds'), 1, 5);
    }

    private function tokens(): ProviderCredentials
    {
        return new ProviderCredentials(['oauth_token' => 'synthetic-token', 'oauth_token_secret' => 'synthetic-token-secret']);
    }

    private function payload(array $data, string $id = 'abc123'): array
    {
        return ['request' => ['params' => ['account_id' => $id]], 'data' => $data];
    }

    private function reader(Factory $http, ?\Closure $guard = null): XAccountSelection
    {
        return new XAccountSelection($http, new XOAuthSigner('synthetic-key', 'synthetic-secret'), $guard ?? static fn () => null);
    }

    public function test_campaign_analyst_can_connect_without_write_roles_currency_or_fabricated_policy_proof(): void
    {
        foreach (['CAMPAIGN_ANALYST', 'AD_MANAGER', 'ACCOUNT_ADMIN'] as $role) {
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->sequence()->push($this->payload(['id' => 'abc123', 'deleted' => false, 'timezone' => 'Asia/Amman', 'approval_status' => 'PENDING']))
                ->push($this->payload(['user_id' => 'discard-user-id', 'permissions' => [$role]]))]);
            $calls = 0;
            $material = $this->reader($http, function ($scope, $action) use (&$calls): void {
                $this->assertSame('abc123', $scope->accountId);
                $this->assertSame('provider.connect', $action);
                $calls++;
            })->verify($this->budget(), $this->tokens());
            $this->assertSame(2, $calls);
            $this->assertSame('CONNECTED', $material->nativeState);
            $this->assertNull($material->expiresAt);
            $this->assertSame($role, $material->credentials->value('native_role'));
            $this->assertSame('Asia/Amman', $material->credentials->value('account_timezone'));
            $this->assertArrayNotHasKey('account_currency', $material->credentials->custodyPayload());
            $this->assertArrayNotHasKey('user_id', $material->credentials->custodyPayload());
            $this->assertSame('VERIFIED', $material->verification['provider_permission']['state']);
            $this->assertSame('UNKNOWN', $material->verification['privacy_purpose']['state']);
            $this->assertSame('UNKNOWN', $material->verification['provider_support']['state']);
            $http->assertSentCount(2);
        }
    }

    public function test_foreign_account_echo_deleted_or_unknown_timezone_fail_before_permission_request(): void
    {
        foreach ([$this->payload(['id' => 'foreign', 'deleted' => false, 'timezone' => 'UTC']),
            $this->payload(['id' => 'abc123', 'deleted' => false, 'timezone' => 'UTC'], 'foreign'),
            $this->payload(['id' => 'abc123', 'deleted' => true, 'timezone' => 'UTC']),
            $this->payload(['id' => 'abc123', 'deleted' => false, 'timezone' => 'unknown'])] as $payload) {
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->response($payload)]);
            try {
                $this->reader($http)->verify($this->budget(), $this->tokens());
                $this->fail('Unverified account must fail.');
            } catch (\RuntimeException) {
                $http->assertSentCount(1);
            }
        }
    }

    public function test_native_switch_timestamp_is_preserved_while_null_or_absent_remains_unknown(): void
    {
        foreach ([[], ['timezone_switch_at' => null], ['timezone_switch_at' => '2026-01-01T21:00:00Z']] as $metadata) {
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->sequence()->push($this->payload([
                'id' => 'abc123', 'deleted' => false, 'timezone' => 'Asia/Amman', ...$metadata]))
                ->push($this->payload(['permissions' => ['CAMPAIGN_ANALYST']]))]);
            $material = $this->reader($http)->verify($this->budget(), $this->tokens());
            $this->assertSame($metadata['timezone_switch_at'] ?? 'UNKNOWN', $material->credentials->value('timezone_switch_at'));
        }
    }

    public function test_non_campaign_roles_and_changed_authority_do_not_produce_access_material(): void
    {
        foreach ([[], ['ANALYST'], ['CREATIVE_MANAGER'], ['PARTNER_AUDIENCE_MANAGER'], ['TWEET_COMPOSER']] as $roles) {
            $http = new Factory;
            $http->preventStrayRequests();
            $http->fake(['*' => $http->sequence()->push($this->payload(['id' => 'abc123', 'deleted' => false, 'timezone' => 'UTC']))
                ->push($this->payload(['permissions' => $roles]))]);
            try {
                $this->reader($http)->verify($this->budget(), $this->tokens());
                $this->fail('Missing campaign read permission must fail.');
            } catch (\RuntimeException $error) {
                $this->assertSame('x_report_permission_unverified', $error->getMessage());
            }
        }
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response($this->payload(['id' => 'abc123', 'deleted' => false, 'timezone' => 'UTC']))]);
        $calls = 0;
        try {
            $this->reader($http, function () use (&$calls): void {
                if (++$calls === 2) {
                    throw new \RuntimeException('authority-changed');
                }
            })->verify($this->budget(), $this->tokens());
            $this->fail('Second request must recheck authority.');
        } catch (\RuntimeException $error) {
            $this->assertSame('authority-changed', $error->getMessage());
            $http->assertSentCount(1);
        }
    }

    public function test_native_failure_body_and_foreign_permission_echo_do_not_escape_verification(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response('private-native-response', 403)]);
        try {
            $this->reader($http)->verify($this->budget(), $this->tokens());
            $this->fail('Denied native request must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('x_access_denied', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->sequence()->push($this->payload(['id' => 'abc123', 'deleted' => false, 'timezone' => 'UTC']))
            ->push($this->payload(['permissions' => ['CAMPAIGN_ANALYST']], 'foreign'))]);
        try {
            $this->reader($http)->verify($this->budget(), $this->tokens());
            $this->fail('Foreign permission response must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('x_account_response_unverified', $error->getMessage());
        }
    }
}
