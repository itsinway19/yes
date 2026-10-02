<?php

namespace App\Providers\Ads\Meta;

use Illuminate\Http\Client\Factory;
use LogicException;

/** Trusted installation settings, not evidence of app review, purpose approval or account permission. */
final readonly class MetaLoginSettings
{
    public function __construct(public string $version, public string $appId,
        #[\SensitiveParameter] private string $appSecret, public string $redirectUri,
        public string $configurationId, public MetaTokenKind $tokenKind)
    {
        if (! preg_match('/^v[1-9][0-9]\.0$/D', $version) || $appSecret === ''
            || ! preg_match('/^[1-9][0-9]{0,39}$/D', $appId)
            || ! preg_match('/^[1-9][0-9]{0,39}$/D', $configurationId)
            || filter_var($redirectUri, FILTER_VALIDATE_URL) === false
            || parse_url($redirectUri, PHP_URL_SCHEME) !== 'https'
            || parse_url($redirectUri, PHP_URL_USER) !== null
            || parse_url($redirectUri, PHP_URL_PASS) !== null
            || parse_url($redirectUri, PHP_URL_QUERY) !== null
            || parse_url($redirectUri, PHP_URL_FRAGMENT) !== null) {
            throw new LogicException('Explicit Meta installation settings and exact HTTPS callback are required.');
        }
    }

    public static function configured(): self
    {
        $kind = MetaTokenKind::tryFrom((string) config('services.meta_ads.token_kind'));
        if ($kind === null) {
            throw new LogicException('The Meta configuration must declare its native token kind.');
        }

        return new self((string) config('services.meta_ads.version'), (string) config('services.meta_ads.app_id'),
            (string) config('services.meta_ads.app_secret'), (string) config('services.meta_ads.redirect_uri'),
            (string) config('services.meta_ads.configuration_id'), $kind);
    }

    public function adapter(Factory $http): MetaAccessAdapter
    {
        // Coordinator replaces this fail-closed guard with the current signed scope for every operation.
        return new MetaAccessAdapter($http, $this->version, $this->appId, $this->appSecret,
            $this->redirectUri, $this->configurationId, static function (): never {
                throw new LogicException('Meta native transport requires current scoped authority.');
            }, $this->tokenKind);
    }

    public function __debugInfo(): array
    {
        return ['version' => $this->version, 'app_id' => $this->appId, 'configuration_id' => $this->configurationId,
            'token_kind' => $this->tokenKind->value, 'app_secret' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Meta installation settings cannot enter a queue or projection.');
    }
}
