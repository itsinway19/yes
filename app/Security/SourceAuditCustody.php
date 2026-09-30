<?php

namespace App\Security;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Separate authority transaction; a local obligation is not a custody acknowledgment. */
class SourceAuditCustody
{
    public function accept(string $obligation, string $fingerprint, string $envelope): string
    {
        $db = DB::build(config('database.connections.pgsql_authority'));
        try {
            return $db->transaction(function () use ($db, $obligation, $fingerprint, $envelope): string {
                $db->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['bb1.custody.'.$obligation]);
                $actual = $db->scalar("SELECT encode(sha256(convert_to(?::jsonb::text,'UTF8')),'hex')", [$envelope]);
                if (! hash_equals($fingerprint, $actual)) {
                    throw new RuntimeException('Source custody fingerprint conflict.');
                }
                $existing = $db->table('security_source_audit_custody')->where('obligation_id', $obligation)->first();
                if ($existing) {
                    if (! hash_equals($existing->fingerprint, $fingerprint)) {
                        throw new RuntimeException('Source custody identity conflict.');
                    }

                    return $existing->acknowledgment_id;
                }

                return $db->table('security_source_audit_custody')->insertGetId([
                    'obligation_id' => $obligation, 'fingerprint' => $fingerprint, 'envelope' => $envelope,
                ], 'acknowledgment_id');
            });
        } finally {
            $db->disconnect();
        }
    }
}
