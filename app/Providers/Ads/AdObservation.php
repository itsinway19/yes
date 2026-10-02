<?php

namespace App\Providers\Ads;

use DateTimeImmutable;
use InvalidArgumentException;

/** A campaign-period advertising snapshot, never an accepted business outcome. */
final readonly class AdObservation implements \JsonSerializable
{
    public function __construct(
        public ProviderScope $scope,
        public string $campaignId,
        public ReportWindow $window,
        public NativeAmount $spend,
        public array $nativeFacts,
        public array $provenance,
        public DateTimeImmutable $readAt,
        public ?DateTimeImmutable $freshUntil,
    ) {
        if ($campaignId === '' || strlen($campaignId) > 180 || preg_match('/[\x00-\x1f\x7f]/', $campaignId)
            || ($freshUntil !== null && $freshUntil <= $readAt)
            || ! isset($provenance['api_version'], $provenance['report'], $provenance['documentation_basis'])
            || count($provenance) !== 3 || count($nativeFacts) > 100) {
            throw new InvalidArgumentException('Invalid observation provenance or scope.');
        }
        foreach ($provenance as $value) {
            if (! is_string($value) || $value === '' || strlen($value) > 512 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new InvalidArgumentException('Invalid report provenance.');
            }
        }
        foreach ($nativeFacts as $name => $fact) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_.]{0,79}$/D', $name)
                || ! is_array($fact) || array_diff(array_keys($fact), ['value', 'unit', 'meaning', 'basis'])
                || ! array_key_exists('value', $fact) || ! isset($fact['unit'], $fact['meaning'], $fact['basis'])
                || ! in_array($fact['basis'], ['PROVIDER_REPORTED', 'MODELED', 'MIXED', 'UNKNOWN'], true)
                || ($fact['value'] !== null && (! is_string($fact['value']) || strlen($fact['value']) > 512))) {
                throw new InvalidArgumentException('Native facts require an explicit meaning and truth basis.');
            }
            foreach (['unit', 'meaning'] as $field) {
                if (! is_string($fact[$field]) || $fact[$field] === '' || strlen($fact[$field]) > 512) {
                    throw new InvalidArgumentException('Invalid native fact semantics.');
                }
            }
        }
    }

    /** The immutable identity of the measurement slot; restatements keep this identity. */
    public function identity(): string
    {
        return $this->scope->key('observation:'.hash('sha256', json_encode(
            [$this->campaignId, $this->window->jsonSerialize()], JSON_THROW_ON_ERROR,
        )));
    }

    /** Content identity excludes transport/read time so an identical reread is not a correction. */
    public function contentHash(): string
    {
        $facts = $this->nativeFacts;
        ksort($facts);
        foreach ($facts as &$fact) {
            ksort($fact);
        }
        unset($fact);
        $provenance = $this->provenance;
        ksort($provenance);

        return hash('sha256', json_encode([$this->spend->jsonSerialize(), $facts, $provenance], JSON_THROW_ON_ERROR));
    }

    public function freshness(DateTimeImmutable $at): string
    {
        if ($at < $this->readAt || $this->freshUntil === null) {
            return 'UNKNOWN';
        }

        return $this->freshUntil > $at ? 'FRESH' : 'STALE';
    }

    public function jsonSerialize(): array
    {
        return ['scope' => $this->scope->jsonSerialize(), 'campaign_id' => $this->campaignId,
            'window' => $this->window->jsonSerialize(), 'spend' => $this->spend->jsonSerialize(),
            'native_facts' => $this->nativeFacts, 'provenance' => $this->provenance,
            'read_at' => $this->readAt->format(DATE_ATOM), 'fresh_until' => $this->freshUntil?->format(DATE_ATOM)];
    }
}
