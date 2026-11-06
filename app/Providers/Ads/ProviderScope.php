<?php

namespace App\Providers\Ads;

use InvalidArgumentException;

/** Identity only: constructing a scope never grants permission to use it. */
final readonly class ProviderScope implements \JsonSerializable
{
    public function __construct(
        public ?int $workspaceId,
        public string $sourceId,
        public Provider $provider,
        public string $accountId,
        public string $operation,
        public string $purpose,
        public ?int $businessId = null,
    ) {
        $workspace = $workspaceId !== null && $workspaceId > 0 && $businessId === null;
        $business = $businessId !== null && $businessId > 0 && $workspaceId === null;
        if ((! $workspace && ! $business) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sourceId)) {
            throw new InvalidArgumentException('One exact native custody context and BB1 source identity are required.');
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
        return new self(null, $sourceId, $provider, $accountId, $operation, $purpose, $businessId);
    }

    public function sameCustodyAs(self $other): bool
    {
        return $this->workspaceId === $other->workspaceId && $this->businessId === $other->businessId;
    }

    /** Queued historical Workspace scopes predate the Business property. */
    public function __unserialize(array $values): void
    {
        $scope = new self($values['workspaceId'], $values['sourceId'], $values['provider'], $values['accountId'],
            $values['operation'], $values['purpose'], $values['businessId'] ?? null);
        foreach (get_object_vars($scope) as $name => $value) {
            $this->{$name} = $value;
        }
    }

    /** Strict native wire forms. The six-field historical Workspace form is unchanged. */
    public static function fromArray(array $value): self
    {
        $business = ($value['custody_kind'] ?? null) === 'business_native';
        $expected = $business
            ? ['custody_kind', 'business_id', 'source_id', 'provider', 'account_id', 'operation', 'purpose']
            : ['workspace_id', 'source_id', 'provider', 'account_id', 'operation', 'purpose'];
        $keys = array_keys($value);
        sort($keys);
        sort($expected);
        $owner = $value[$business ? 'business_id' : 'workspace_id'] ?? null;
        if ($keys !== $expected || ! is_int($owner) || $owner < 1) {
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

        return $business
            ? self::forBusiness($owner, $value['source_id'], $provider, $value['account_id'], $value['operation'], $value['purpose'])
            : new self($owner, $value['source_id'], $provider, $value['account_id'], $value['operation'], $value['purpose']);
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
        // Existing encrypted credential envelopes, hashes and OAuth bindings use
        // exact Workspace bytes. Never add fields to that historical branch.
        $custody = $this->businessId === null ? ['workspace_id' => $this->workspaceId]
            : ['custody_kind' => 'business_native', 'business_id' => $this->businessId];

        return [...$custody, 'source_id' => strtolower($this->sourceId),
            'provider' => $this->provider->value, 'account_id' => $this->accountId,
            'operation' => $this->operation, 'purpose' => $this->purpose];
    }
}
