<?php

namespace App\Security;

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Uses the Pwned Passwords k-anonymity range API; unavailable checks deny enrollment. */
final class BreachPasswordVerifier implements UncompromisedVerifier
{
    public function verify($data): bool
    {
        $password = (string) ($data['value'] ?? '');
        if ($password === '') {
            return false;
        }

        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        try {
            $response = Http::withOptions([
                'verify' => config('services.pwned_passwords.ca_bundle') ?: true,
            ])->withHeaders(['Add-Padding' => 'true'])
                ->timeout(4)
                ->get('https://api.pwnedpasswords.com/range/'.$prefix);
        } catch (ConnectionException $exception) {
            Log::warning('password_breach_check_unavailable', ['reason' => 'connection']);

            throw new BreachCheckUnavailable('Password breach check unavailable.', previous: $exception);
        }

        if (! $response->successful()) {
            Log::warning('password_breach_check_unavailable', [
                'reason' => 'http_status', 'status' => $response->status(),
            ]);

            throw new BreachCheckUnavailable('Password breach check unavailable.');
        }

        $received = false;
        foreach (explode("\n", $response->body()) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! preg_match('/\A([A-F0-9]{35}):([0-9]+)\z/i', $line, $parts)) {
                Log::warning('password_breach_check_unavailable', ['reason' => 'invalid_response']);

                throw new BreachCheckUnavailable('Password breach check returned an invalid response.');
            }
            $received = true;
            if (hash_equals($suffix, strtoupper($parts[1])) &&
                (int) $parts[2] > (int) ($data['threshold'] ?? 0)) {
                return false;
            }
        }

        if (! $received) {
            Log::warning('password_breach_check_unavailable', ['reason' => 'empty_response']);

            throw new BreachCheckUnavailable('Password breach check returned an empty response.');
        }

        return true;
    }
}
