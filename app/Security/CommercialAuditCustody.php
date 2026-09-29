<?php

namespace App\Security;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Independent P4-A custody acceptance; origin owner never manufactures an acknowledgment. */
final class CommercialAuditCustody
{
    public function accept(string $obligation, string $fingerprint, string $envelope): string
    {
        // A distinct physical transaction commits custody independently, including when the
        // protected HTTP executor is holding its ordinary authority/session transaction.
        $db = DB::build(config('database.connections.pgsql_authority'));
        try {
            return $db->transaction(function () use ($db, $obligation, $fingerprint, $envelope): string {
                $db->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['sa01.custody.'.$obligation]);
                $actual = $db->scalar("SELECT encode(sha256(convert_to(?::jsonb::text,'UTF8')),'hex')", [$envelope]);
                if (! hash_equals($fingerprint, $actual)) {
                    throw new RuntimeException('Custody envelope fingerprint conflict.');
                }
                $existing = $db->table('security_commercial_audit_custody')->where('obligation_id', $obligation)->first();
                if ($existing) {
                    if (! hash_equals($existing->fingerprint, $fingerprint)) {
                        throw new RuntimeException('Custody obligation fingerprint conflict.');
                    }

                    return $existing->acknowledgment_id;
                }

                return $db->table('security_commercial_audit_custody')->insertGetId([
                    'obligation_id' => $obligation, 'fingerprint' => $fingerprint, 'envelope' => $envelope,
                ], 'acknowledgment_id');
            });
        } finally {
            $db->disconnect();
        }
    }
}
