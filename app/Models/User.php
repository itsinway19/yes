<?php

namespace App\Models;

use App\Notifications\SecurityResetPassword;
use App\Notifications\SecurityVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret_encrypted', 'two_factor_recovery_codes_encrypted'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->public_id ??= (string) Str::uuid7();
            $user->email = Str::lower($user->email);
        });

        static::updating(function (User $user): void {
            if ($user->isDirty('email')) {
                $user->email = Str::lower($user->email);
            }
        });
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_memberships')
            ->using(WorkspaceMembership::class)
            ->withPivot(['role', 'status', 'created_at', 'revoked_at']);
    }

    public function sendEmailVerificationNotification(): void
    {
        $updatedAt = DB::table('principal_lifecycles')
            ->where('user_id', $this->id)->where('status', 'pending')->value('updated_at');
        if ($updatedAt !== null && ! $this->hasVerifiedEmail()) {
            $this->notify(new SecurityVerifyEmail($this->email, (string) $updatedAt));
        }
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new SecurityResetPassword($token, $this->email));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret_encrypted' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes_encrypted' => 'encrypted:array',
        ];
    }
}
