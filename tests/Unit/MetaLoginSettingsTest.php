<?php

namespace Tests\Unit;

use App\Providers\Ads\Meta\MetaLoginSettings;
use App\Providers\Ads\Meta\MetaTokenKind;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderScope;
use Illuminate\Http\Client\Factory;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MetaLoginSettingsTest extends TestCase
{
    public function test_installed_adapter_cannot_call_native_transport_before_scoped_authority_binding(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $settings = new MetaLoginSettings('v26.0', '777', 'synthetic-installation-secret',
            'https://builderad.example/meta/callback', '999', MetaTokenKind::BusinessIntegration);
        $adapter = $settings->adapter($http);
        $this->assertStringNotContainsString('synthetic-installation-secret', print_r($settings, true));
        $this->assertSame('999', $adapter->configurationId());
        try {
            $adapter->acquire(new ProviderScope(1, '019a0022-0000-7000-8000-000000000001', Provider::Meta,
                '999', 'discovery.read', 'advertising_analysis'), new ProviderCredentials(['code' => 'synthetic-code']), '{}');
            $this->fail('Unbound transport must fail before HTTP.');
        } catch (LogicException $error) {
            $this->assertSame('Meta native transport requires current scoped authority.', $error->getMessage());
        }
        $http->assertNothingSent();
        $this->expectException(LogicException::class);
        serialize($settings);
    }

    #[DataProvider('invalidCallbacks')]
    public function test_callback_settings_cannot_accept_insecure_or_ambiguous_targets(string $url): void
    {
        $this->expectException(LogicException::class);
        new MetaLoginSettings('v26.0', '777', 'synthetic-secret', $url, '999', MetaTokenKind::BusinessIntegration);
    }

    public static function invalidCallbacks(): array
    {
        return [['http://builderad.example/callback'], ['https://user:password@builderad.example/callback'],
            ['https://builderad.example/callback?workspace=1'], ['https://builderad.example/callback#state']];
    }
}
