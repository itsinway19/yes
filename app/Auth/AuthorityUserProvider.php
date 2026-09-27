<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class AuthorityUserProvider extends EloquentUserProvider
{
    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token): void
    {
        $user->setRememberToken($token);

        DB::connection('pgsql_authority')->table('users')
            ->where($user->getAuthIdentifierName(), $user->getAuthIdentifier())
            ->update([$user->getRememberTokenName() => $token]);
    }
}
