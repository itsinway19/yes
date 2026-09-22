<?php

namespace App\Foundation\Tenancy;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PrincipalContext
{
    public function activate(User $principal): void
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() !== 1) {
            throw new LogicException('Principal context requires the owned workspace transaction.');
        }

        $context = $connection->selectOne(<<<'SQL'
            select
                current_setting('app.authorization_phase', true) as phase,
                current_setting('app.principal_user_id', true) as principal,
                current_setting('app.workspace_id', true) as workspace
            SQL);

        foreach ([$context->phase, $context->principal, $context->workspace] as $value) {
            if ($value !== null && $value !== '') {
                throw new LogicException('Principal context requires a clean authorization transaction.');
            }
        }

        $principalId = $principal->getKey();

        if (! $principal->exists || ! is_int($principalId) || $principalId < 1) {
            throw new LogicException('Principal context requires a persisted user identifier.');
        }

        $connection->selectOne(
            "select set_config('app.principal_user_id', ?, true)",
            [(string) $principalId],
        );
        $connection->selectOne(
            "select set_config('app.authorization_phase', 'principal', true)",
        );
    }
}
