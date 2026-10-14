<?php

namespace App\Security;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Signed transport evidence only; callers must separately admit canonical authority. */
final class IdentityReceipt
{
    private string $key;

    public function __construct(string $key = '', private ?Closure $clock = null)
    {
        $key = $key !== '' ? $key : (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: '';
        }
        if (strlen($key) < 32) {
            throw new InvalidArgumentException('Identity receipts require an application signing key.');
        }
        $this->key = hash_hmac('sha256', 'builderad.identity-receipt.v1', $key, true);
    }

    public function issue(array $binding, string $purpose, int $expiresAt): string
    {
        $this->validateBinding($binding, $purpose);
        $now = $this->now();
        if ($expiresAt <= $now || $expiresAt > $now + 7200) {
            throw new InvalidArgumentException('Identity receipt lifetime is bounded to two hours.');
        }
        $payload = $this->encode(json_encode([
            'v' => 1, 'purpose' => $purpose, 'binding' => $binding,
            'issued' => $now, 'expires' => $expiresAt,
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->encode(hash_hmac('sha256', $payload, $this->key, true));
    }

    /** Untrusted request fields are never substituted for the server's expected binding. */
    public function verify(string $receipt, array $expected, string $purpose): array
    {
        $claims = $this->read($receipt, $purpose);
        $this->validateBinding($expected, $purpose);
        foreach ($expected as $name => $value) {
            if (! array_key_exists($name, $claims['binding']) || $claims['binding'][$name] !== $value) {
                throw new IdentityTransitionConflict;
            }
        }

        return $claims['binding'];
    }

    /** Reading proves signature/shape/lifetime, never credential or business admission. */
    public function read(string $receipt, string $purpose): array
    {
        if (strlen($receipt) > 4096 || substr_count($receipt, '.') !== 1) {
            throw new IdentityTransitionConflict;
        }
        [$payload, $signature] = explode('.', $receipt, 2);
        if (! hash_equals($this->encode(hash_hmac('sha256', $payload, $this->key, true)), $signature)) {
            throw new IdentityTransitionConflict;
        }
        try {
            $claims = json_decode($this->decode($payload), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($claims) || array_keys($claims) !== ['v', 'purpose', 'binding', 'issued', 'expires']
                || $claims['v'] !== 1 || $claims['purpose'] !== $purpose
                || ! is_int($claims['issued']) || ! is_int($claims['expires'])
                || $claims['issued'] > $this->now() || $claims['expires'] <= $this->now()
                || $claims['expires'] <= $claims['issued'] || $claims['expires'] - $claims['issued'] > 7200
                || ! is_array($claims['binding'])) {
                throw new IdentityTransitionConflict;
            }
            $this->validateBinding($claims['binding'], $purpose);
        } catch (\Throwable $error) {
            if ($error instanceof IdentityTransitionConflict) {
                throw $error;
            }
            throw new IdentityTransitionConflict;
        }

        return $claims;
    }

    private function validateBinding(array $binding, string $purpose): void
    {
        $fields = ['lineage', 'epoch', 'boundary', 'credential', 'principal', 'lifecycle', 'context', 'generation'];
        if ($purpose === 'transition') {
            $fields[] = 'nonce';
        } elseif ($purpose !== 'document') {
            throw new IdentityTransitionConflict;
        }
        $actual = array_keys($binding);
        sort($actual);
        sort($fields);
        if ($actual !== $fields
            || ! is_string($binding['lineage']) || ! Str::isUuid($binding['lineage'])
            || ! is_int($binding['epoch']) || $binding['epoch'] < 0
            || ! in_array($binding['boundary'], ['advertiser', 'internal'], true)
            || ! is_int($binding['principal']) || $binding['principal'] < 0
            || ! is_int($binding['lifecycle']) || $binding['lifecycle'] < 0) {
            throw new IdentityTransitionConflict;
        }
        foreach (['credential', 'context', 'generation', ...($purpose === 'transition' ? ['nonce'] : [])] as $name) {
            if (! is_string($binding[$name]) || ! preg_match('/^[0-9a-f]{64}$/D', $binding[$name])) {
                throw new IdentityTransitionConflict;
            }
        }
    }

    private function now(): int
    {
        return $this->clock ? ($this->clock)() : time();
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw new IdentityTransitionConflict;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || $this->encode($decoded) !== $value) {
            throw new IdentityTransitionConflict;
        }

        return $decoded;
    }
}
