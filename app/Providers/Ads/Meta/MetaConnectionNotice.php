<?php

namespace App\Providers\Ads\Meta;

use LogicException;

/** Published operator documents, not a legal/approval attestation or a provider capability proof. */
final readonly class MetaConnectionNotice
{
    public function __construct(public string $privacyUrl, public string $deletionInstructionsUrl, public string $revision)
    {
        foreach ([$privacyUrl, $deletionInstructionsUrl] as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https'
                || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
                throw new LogicException('Published HTTPS privacy and deletion instructions are required.');
            }
        }
        if (! preg_match('/^[a-zA-Z0-9_.:-]{1,80}$/D', $revision)) {
            throw new LogicException('A published notice revision is required.');
        }
    }

    public static function configured(): self
    {
        return new self((string) config('services.meta_ads.privacy_url'),
            (string) config('services.meta_ads.deletion_instructions_url'), (string) config('services.meta_ads.notice_revision'));
    }

    public function binding(): string
    {
        return hash('sha256', json_encode([$this->privacyUrl, $this->deletionInstructionsUrl, $this->revision], JSON_THROW_ON_ERROR));
    }

    public function projection(): array
    {
        return ['privacy_url' => $this->privacyUrl, 'deletion_instructions_url' => $this->deletionInstructionsUrl,
            'revision' => $this->revision, 'purpose' => 'advertising_analysis'];
    }
}
