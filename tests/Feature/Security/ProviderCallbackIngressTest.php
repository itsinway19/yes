<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProviderCallbackIngressTest extends TestCase
{
    public static function providers(): array
    {
        return array_map(fn ($provider) => [$provider], ['meta', 'google', 'tiktok', 'snapchat', 'x']);
    }

    #[DataProvider('providers')]
    public function test_guest_callback_cannot_persist_sensitive_query_or_create_effects(string $provider): void
    {
        Http::preventStrayRequests();
        $before = [];
        foreach (['provider_access', 'source_registry', 'security_action_grants'] as $table) {
            $before[$table] = DB::connection('pgsql_migrations')->table($table)->count();
        }
        $query = ['code' => 'synthetic-confidential-code', 'auth_code' => 'synthetic-confidential-code',
            'state' => str_repeat('s', 43), 'oauth_token' => 'synthetic-private-token',
            'oauth_verifier' => 'synthetic-private-verifier', 'error_description' => 'synthetic-private-error'];
        $response = $this->get('/providers/'.$provider.'/callback?'.http_build_query($query));
        $response->assertRedirect(route('login'));
        $session = json_encode(session()->all(), JSON_THROW_ON_ERROR);
        foreach ($query as $value) {
            $this->assertStringNotContainsString($value, $session);
            $this->assertStringNotContainsString($value, $response->headers->get('Location'));
        }
        $this->assertStringNotContainsString('?', (string) session('url.intended'));
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::connection('pgsql_migrations')->table($table)->count());
        }
        Http::assertNothingSent();
    }
}
