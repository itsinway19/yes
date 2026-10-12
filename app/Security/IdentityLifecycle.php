<?php

namespace App\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class IdentityLifecycle
{
    public function __construct(private IdentityTransition $transitions, private IdentityDocument $documents) {}

    /** Retirement is scoped to the loaded input; it never follows a newer identity. */
    public function expire(Request $request): void
    {
        $lineage = $request->session()->get('builderad.identity_lineage');
        $epoch = $request->session()->get('builderad.identity_epoch');
        $credential = SessionAuthority::sessionKey($request);
        $principal = $request->user()?->getAuthIdentifier();
        if (! is_string($lineage) || ! is_int($epoch) || ! $principal) {
            throw new IdentityTransitionConflict;
        }
        $this->transitions->close($request, $lineage, $this->documents->boundary($request), $epoch,
            bin2hex(random_bytes(32)), 'expired', function (object $row) use ($credential, $principal): void {
                if ($row->status !== 'active' || (int) $row->principal_id !== (int) $principal
                    || ! hash_equals((string) $row->current_session_key_hash, $credential)) {
                    throw new IdentityTransitionConflict;
                }
                $connection = DB::connection('pgsql_authority');
                $session = $connection->table('security_sessions')->where('session_key_hash', $credential)
                    ->where('user_id', $principal)->lockForUpdate()->first();
                $lifecycle = $connection->table('principal_lifecycles')->where('user_id', $principal)->first();
                if (! $session || ! $lifecycle) {
                    throw new IdentityTransitionConflict;
                }
                $invalid = $session->revoked_at !== null
                    || Carbon::parse($session->idle_expires_at)->lessThanOrEqualTo(now())
                    || Carbon::parse($session->absolute_expires_at)->lessThanOrEqualTo(now())
                    || (int) $session->lifecycle_generation !== (int) $lifecycle->generation
                    || ! in_array($lifecycle->status, ['pending', 'current'], true);
                if (! $invalid) {
                    throw new IdentityTransitionConflict;
                }
            });
        IdentityCookies::own($request, 'terminal');
    }
}
