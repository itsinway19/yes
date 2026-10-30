<?php

namespace App\MarketKnowledge\Processing;

use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Called only by the trusted coordinator after computation/validation, never an ingress. */
final class TrustedCompletion
{
    public function issue(Request $request, string $business, array $run, string $command, string $effect, array $outcome, int $elapsed, array $selection, ?array $context): string
    {
        $runtime = DB::connection();
        if ($runtime->transactionLevel() !== 1 || ! $request->user()) {
            throw new LogicException('Completion attestation requires its owned authenticated commit transaction.');
        }
        $binding = $runtime->selectOne(<<<'SQL'
            SELECT pg_backend_pid() AS backend_pid, pg_current_xact_id()::text AS transaction_id,
                encode(sha256(convert_to(jsonb_build_object('run',?::uuid,'command',?::uuid,'effect',?::text,'outcome',?::jsonb,
                    'elapsed',?::bigint,'selection',?::jsonb,'context_selection',?::jsonb)::text,'UTF8')),'hex') AS digest
            SQL, [$run['run_ref'], $command, $effect, json_encode($outcome, JSON_THROW_ON_ERROR), $elapsed,
            json_encode($selection, JSON_THROW_ON_ERROR), $context === null ? null : json_encode($context, JSON_THROW_ON_ERROR)]);
        $token = bin2hex(random_bytes(32));
        $authority = DB::connection('pgsql_authority');
        $issuer = $authority->transactionLevel() > 0 ? DB::build(config('database.connections.pgsql_authority')) : $authority;
        try {
            $issuer->transaction(function () use ($issuer, $token, $request, $business, $run, $command, $binding): void {
                $issuer->table('market_processing_completion_tickets')->whereRaw('expires_at <= clock_timestamp()')->delete();
                $issuer->table('market_processing_completion_tickets')->insert([
                    'token_hash' => hash('sha256', $token), 'run_id' => $run['run_ref'], 'command_id' => $command,
                    'actor_user_id' => $request->user()->getKey(), 'generation' => (int) $request->session()->get('a001_generation', 0),
                    'business_public_id' => $business, 'method_ref' => $run['method_ref'],
                    'session_digest' => '\\x'.hash('sha256', SessionAuthority::sessionKey($request)), 'binding_digest' => '\\x'.$binding->digest,
                    'backend_pid' => $binding->backend_pid, 'transaction_id' => $binding->transaction_id,
                    'expires_at' => DB::raw("clock_timestamp() + interval '20 seconds'"),
                ]);
            });
        } finally {
            if ($issuer !== $authority) {
                $issuer->disconnect();
            }
        }

        return $token;
    }
}
