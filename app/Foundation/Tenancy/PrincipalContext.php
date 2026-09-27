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

        $transaction = $connection->selectOne('SELECT pg_backend_pid() AS backend_pid, pg_current_xact_id()::text AS transaction_id');
        $ticket = bin2hex(random_bytes(32));
        DB::connection('pgsql_authority')->table('builderad_context_tickets')
            ->whereRaw('expires_at <= clock_timestamp()')->delete();
        DB::connection('pgsql_authority')->table('builderad_context_tickets')->insert([
            'token_hash' => hash('sha256', $ticket),
            'actor_user_id' => $principalId,
            'backend_pid' => $transaction->backend_pid,
            'transaction_id' => $transaction->transaction_id,
            'expires_at' => DB::raw("clock_timestamp() + interval '20 seconds'"),
        ]);
        $connection->selectOne('SELECT builderad_activate_principal_context(?)', [$ticket]);
    }
}
