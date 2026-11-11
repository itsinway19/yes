<?php

namespace App\Security;

use App\Models\User;
use App\Notifications\RegistrationCode;
use App\Notifications\SecurityMail;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/** A registration attempt proves mailbox possession only. Identity publication consumes it. */
final class RegistrationEmailProof
{
    private const CODE_MINUTES = 10;

    private const PROOF_MINUTES = 20;

    private const RESEND_SECONDS = 60;

    private const MAX_ATTEMPTS = 5;

    public function state(Request $request): array
    {
        return $this->withGuest($request, fn (array $binding) => $this->projection($this->attempt($binding)));
    }

    public function send(Request $request, string $email): array
    {
        return $this->withGuest($request, function (array $binding) use ($email): array {
            $attempt = $this->attempt($binding);
            $now = now();
            $sameEmail = $attempt && Crypt::decryptString($attempt->email) === $email;
            if ($sameEmail && (Carbon::parse($attempt->resend_at)->isAfter($now)
                || ($attempt->verified_at && Carbon::parse($attempt->expires_at)->isAfter($now)))) {
                return $this->projection($attempt);
            }

            // Bound delivery to the target as well as the route's client/IP limiter.
            $rateKey = 'registration-email:'.hash_hmac('sha256', $email, (string) config('app.key'));
            if (RateLimiter::tooManyAttempts($rateKey, 5) || RateLimiter::increment($rateKey, 3600) > 5) {
                throw new TooManyRequestsHttpException(RateLimiter::availableIn($rateKey), __('registration.rate_limit'));
            }

            $id = $sameEmail ? $attempt->attempt_id : (string) Str::uuid7();
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $digest = $this->digest($id, $code);
            $expiry = $now->copy()->addMinutes(self::CODE_MINUTES);
            $values = [
                'attempt_id' => $id, 'epoch' => $binding['epoch'], 'credential_hash' => $binding['credential'],
                'email' => Crypt::encryptString($email), 'code_digest' => $digest, 'attempts' => 0,
                'resend_at' => $now->copy()->addSeconds(self::RESEND_SECONDS), 'expires_at' => $expiry,
                'verified_at' => null,
            ];
            $this->proofs()->updateOrInsert(['lineage_id' => $binding['lineage']], $values);

            // Existing and new addresses receive the same challenge and response.
            SecurityMail::afterCommit('registration_code', fn () => Notification::route('mail', $email)
                ->notify(new RegistrationCode($id, $code, $digest, $expiry->timestamp)));

            return $this->projection((object) $values);
        });
    }

    public function verify(Request $request, string $id, string $code): array
    {
        $state = $this->withGuest($request, function (array $binding) use ($id, $code): ?array {
            $attempt = $this->attempt($binding);
            $now = now();
            if (! $attempt || $attempt->attempt_id !== $id || $attempt->verified_at || ! $attempt->code_digest
                || Carbon::parse($attempt->expires_at)->lessThanOrEqualTo($now)) {
                return null;
            }
            if (! hash_equals($attempt->code_digest, $this->digest($id, $code))) {
                $failed = (int) $attempt->attempts + 1;
                $this->proofs()->where('attempt_id', $id)->update([
                    'attempts' => $failed,
                    'code_digest' => $failed >= self::MAX_ATTEMPTS ? null : $attempt->code_digest,
                ]);

                return null;
            }
            $attempt->verified_at = $now;
            $attempt->expires_at = $now->copy()->addMinutes(self::PROOF_MINUTES);
            $this->proofs()->where('attempt_id', $id)->update([
                'code_digest' => null, 'verified_at' => $now, 'expires_at' => $attempt->expires_at,
            ]);

            return $this->projection($attempt);
        });
        if ($state === null) {
            // Raise validation only after the failed attempt has committed.
            throw ValidationException::withMessages(['code' => __('registration.invalid_code')]);
        }

        return $state;
    }

    public function cancel(Request $request, string $id): array
    {
        return $this->withGuest($request, function (array $binding) use ($id): array {
            $attempt = $this->attempt($binding);
            if ($attempt?->attempt_id === $id) {
                $this->proofs()->where('attempt_id', $id)->delete();

                return ['state' => 'none'];
            }

            return $this->projection($attempt);
        });
    }

    /** The identity publisher already owns the lineage lock and the outer SQL transaction. */
    public function createAdvertiser(Request $request, object $lineage, array $input, LifecycleAuthority $lifecycles): User
    {
        $authority = DB::connection('pgsql_authority');
        if ($authority->transactionLevel() !== 1) {
            throw new LogicException('Registration proof must be consumed inside identity publication.');
        }
        $binding = $this->guestBinding($request);
        $this->assertLineage($lineage, $binding);
        $attempt = $this->attempt($binding);
        $now = now();
        if (! $attempt || $attempt->attempt_id !== $input['attempt_id'] || ! $attempt->verified_at
            || Carbon::parse($attempt->expires_at)->lessThanOrEqualTo($now)
            || Crypt::decryptString($attempt->email) !== $input['email']) {
            throw ValidationException::withMessages(['attempt_id' => __('registration.proof_required')]);
        }

        try {
            // Use a savepoint for a concurrent registration/profile update on the unique email.
            $user = $authority->transaction(fn () => User::on('pgsql_authority')->create([
                'name' => $input['name'], 'email' => $input['email'], 'password' => $input['password'],
            ]));
        } catch (UniqueConstraintViolationException $error) {
            if (! User::on('pgsql_authority')->whereRaw('lower(email) = ?', [$input['email']])->exists()) {
                throw $error;
            }
            // Only the holder of a verified proof reaches this response.
            throw ValidationException::withMessages(['email' => __('registration.sign_in_to_continue')]);
        }

        $lifecycles->registerPending($user);
        $lifecycles->verifyPrimaryEmail($user);
        $user->refresh();

        // Keep a short, opaque recovery handoff bound only to the created principal.
        $this->proofs()->where('attempt_id', $attempt->attempt_id)->update([
            'lineage_id' => null, 'epoch' => null, 'credential_hash' => null, 'email' => null,
            'code_digest' => null, 'attempts' => 0, 'resend_at' => null, 'verified_at' => null,
            'user_id' => $user->id, 'expires_at' => $now->copy()->addDays(7),
        ]);

        return $user;
    }

    public function businessAttempt(User $user): ?string
    {
        return $this->proofs()->where('user_id', $user->getKey())->where('expires_at', '>', now())->value('attempt_id');
    }

    public function shouldDeliver(string $id, string $digest): bool
    {
        return $this->proofs()->where('attempt_id', $id)->where('code_digest', $digest)
            ->where('expires_at', '>', now())->exists();
    }

    public function pruneExpired(): void
    {
        $this->proofs()->where('expires_at', '<=', now())->delete();
    }

    private function withGuest(Request $request, Closure $operation): ?array
    {
        $binding = $this->guestBinding($request);
        $authority = DB::connection('pgsql_authority');

        return $authority->transaction(function () use ($authority, $binding, $operation): ?array {
            $row = $authority->table('browser_identity_transitions')
                ->where('lineage_id', $binding['lineage'])->lockForUpdate()->first();
            $this->assertLineage($row, $binding);

            return $operation($binding);
        });
    }

    private function guestBinding(Request $request): array
    {
        $binding = $request->attributes->get('builderad.identity_document');
        if (! is_array($binding) || $binding['principal'] !== 0 || $binding['boundary'] !== 'advertiser'
            || $request->user() || ! hash_equals($binding['credential'], SessionAuthority::sessionKey($request))) {
            throw new IdentityTransitionConflict;
        }

        return $binding;
    }

    private function assertLineage(?object $row, array $binding): void
    {
        if (! $row || $row->lineage_id !== $binding['lineage'] || $row->boundary !== 'advertiser'
            || $row->status === 'active' || (int) $row->epoch !== $binding['epoch']) {
            throw new IdentityTransitionConflict;
        }
    }

    private function attempt(array $binding): ?object
    {
        return $this->proofs()->where('lineage_id', $binding['lineage'])->where('epoch', $binding['epoch'])
            ->where('credential_hash', $binding['credential'])->lockForUpdate()->first();
    }

    private function projection(?object $attempt): array
    {
        if (! $attempt || Carbon::parse($attempt->expires_at)->lessThanOrEqualTo(now())) {
            return ['state' => 'none'];
        }

        return [
            'state' => $attempt->verified_at ? 'verified' : 'code_required',
            'email' => Crypt::decryptString($attempt->email),
            'attempt_id' => $attempt->attempt_id,
            'resend_after' => max(0, Carbon::parse($attempt->resend_at)->timestamp - now()->timestamp),
        ];
    }

    private function digest(string $attempt, string $code): string
    {
        return hash_hmac('sha256', $attempt.':'.$code, (string) config('app.key'));
    }

    private function proofs(): Builder
    {
        return DB::connection('pgsql_authority')->table('registration_email_proofs');
    }
}
