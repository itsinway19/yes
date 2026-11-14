<?php

namespace App\Providers\Ads;

use InvalidArgumentException;

/** Identity only: constructing a scope never grants permission to use it. */
final readonly class ProviderScope implements \JsonSerializable
{
    private function __construct(
        public string $sourceId,
        public Provider $provider,
        public string $accountId,
        public string $operation,
        public string $purpose,
        public int $businessId,
    ) {
        if ($businessId < 1 || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sourceId)) {
            throw new InvalidArgumentException('An exact Business custody context and BB1 source identity are required.');
        }
        if ($accountId === '' || strlen($accountId) > 180 || trim($accountId) !== $accountId
            || preg_match('/[\x00-\x1f\x7f]/', $accountId)
            || ! preg_match('/^[a-z][a-z0-9_.]{0,79}$/', $operation)
            || ! preg_match('/^[a-z][a-z0-9_.]{0,127}$/', $purpose)) {
            throw new InvalidArgumentException('Invalid provider resource scope.');
        }
    }

    public static function forBusiness(int $businessId, string $sourceId, Provider $provider,
        string $accountId, string $operation, string $purpose): self
    {
        return new self($sourceId, $provider, $accountId, $operation, $purpose, $businessId);
    }

    public function sameCustodyAs(self $other): bool
    {
        return $this->businessId === $other->businessId;
    }

    /** Restore only the current typed Business queue identity. */
    public function __unserialize(array $values): void
    {
        $expected = ['sourceId', 'provider', 'accountId', 'operation', 'purpose', 'businessId'];
        $keys = array_keys($values);
        sort($keys);
        sort($expected);
        if ($keys !== $expected || ! is_int($values['businessId'] ?? null)) {
            throw new InvalidArgumentException('An exact Business queue scope is required.');
        }
        $scope = self::forBusiness($values['businessId'], $values['sourceId'], $values['provider'], $values['accountId'],
            $values['operation'], $values['purpose']);
        foreach (get_object_vars($scope) as $name => $value) {
            $this->{$name} = $value;
        }
    }

    /** Strict Business-native wire form; retired custody is never reinterpreted. */
    public static function fromArray(array $value): self
    {
        $expected = ['custody_kind', 'business_id', 'source_id', 'provider', 'account_id', 'operation', 'purpose'];
        $keys = array_keys($value);
        sort($keys);
        sort($expected);
        $owner = $value['business_id'] ?? null;
        if (($value['custody_kind'] ?? null) !== 'business_native' || $keys !== $expected || ! is_int($owner) || $owner < 1) {
            throw new InvalidArgumentException('An exact typed native custody scope is required.');
        }
        foreach (['source_id', 'provider', 'account_id', 'operation', 'purpose'] as $field) {
            if (! is_string($value[$field])) {
                throw new InvalidArgumentException('Native scope fields must retain their declared types.');
            }
        }
        $provider = Provider::tryFrom($value['provider']);
        if ($provider === null) {
            throw new InvalidArgumentException('An implemented native provider is required.');
        }

        return self::forBusiness($owner, $value['source_id'], $provider, $value['account_id'], $value['operation'], $value['purpose']);
    }

    /** Provider, source, resource, effect and purpose cannot share another stream's state. */
    public function key(string $stream): string
    {
        if ($stream === '' || strlen($stream) > 512 || preg_match('/[\x00-\x1f\x7f]/', $stream)) {
            throw new InvalidArgumentException('A bounded stream identity is required.');
        }

        return 'bb2:'.hash('sha256', json_encode([$this->jsonSerialize(), $stream], JSON_THROW_ON_ERROR));
    }

    public function jsonSerialize(): array
    {
        $custody = ['custody_kind' => 'business_native', 'business_id' => $this->businessId];

        return [...$custody, 'source_id' => strtolower($this->sourceId),
            'provider' => $this->provider->value, 'account_id' => $this->accountId,
            'operation' => $this->operation, 'purpose' => $this->purpose];
    }
}
