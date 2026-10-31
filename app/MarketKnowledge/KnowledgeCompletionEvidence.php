<?php

namespace App\MarketKnowledge;

use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Separate authority credentials attest only the coordinator's validated shared output. No HTTP issuer. */
final class KnowledgeCompletionEvidence
{
    public function issue(Request $request, array $run, array $body, array $finding): string
    {
        $runtime = DB::connection();
        if ($runtime->transactionLevel() !== 1 || ! $request->user()) {
            throw new LogicException('Shared completion requires its owned runtime transaction.');
        }
        $binding = $runtime->selectOne(<<<'SQL'
            SELECT pg_backend_pid() AS backend_pid, pg_current_xact_id()::text AS transaction_id,
                encode(sha256(convert_to(jsonb_build_object('run',?::uuid,'command',?::uuid,'output',?::uuid,
                    'finding',?::jsonb,'process',?::jsonb,'publication',?::jsonb)::text,'UTF8')),'hex') AS digest
            SQL, [$run['run_ref'], $body['command_id'], $body['output_scope_id'], json_encode($finding, JSON_THROW_ON_ERROR),
            json_encode($body['process_selection'], JSON_THROW_ON_ERROR), json_encode($body['publication_selection'], JSON_THROW_ON_ERROR)]);
        $token = bin2hex(random_bytes(32));
        $authority = DB::connection('pgsql_authority');
        $issuer = $authority->transactionLevel() > 0 ? DB::build(config('database.connections.pgsql_authority')) : $authority;
        try {
            $issuer->transaction(function () use ($issuer, $token, $request, $run, $body, $binding): void {
                $issuer->table('market_knowledge_completion_tickets')->whereRaw('expires_at <= clock_timestamp()')->delete();
                $issuer->table('market_knowledge_completion_tickets')->insert([
                    'token_hash' => hash('sha256', $token), 'run_id' => $run['run_ref'], 'command_id' => $body['command_id'],
                    'actor_user_id' => $request->user()->id, 'generation' => (int) $request->session()->get('a001_generation', 0),
                    'method_ref' => $run['method_ref'], 'session_digest' => '\\x'.hash('sha256', SessionAuthority::sessionKey($request)),
                    'binding_digest' => '\\x'.$binding->digest, 'backend_pid' => $binding->backend_pid, 'transaction_id' => $binding->transaction_id,
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
