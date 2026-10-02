<?php

namespace App\Providers\Ads;

use InvalidArgumentException;

/** Identity only: constructing a scope never grants permission to use it. */
final readonly class ProviderScope implements \JsonSerializable
{
    public function __construct(
        public int $workspaceId,
        public string $sourceId,
        public Provider $provider,
        public string $accountId,
        public string $operation,
        public string $purpose,
    ) {
        if ($workspaceId < 1 || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sourceId)) {
            throw new InvalidArgumentException('A Workspace and BB1 source identity are required.');
        }
        if ($accountId === '' || strlen($accountId) > 180 || trim($accountId) !== $accountId
            || preg_match('/[\x00-\x1f\x7f]/', $accountId)
            || ! preg_match('/^[a-z][a-z0-9_.]{0,79}$/', $operation)
            || ! preg_match('/^[a-z][a-z0-9_.]{0,127}$/', $purpose)) {
            throw new InvalidArgumentException('Invalid provider resource scope.');
        }
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
        return ['workspace_id' => $this->workspaceId, 'source_id' => strtolower($this->sourceId),
            'provider' => $this->provider->value, 'account_id' => $this->accountId,
            'operation' => $this->operation, 'purpose' => $this->purpose];
    }
}
