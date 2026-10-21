<?php

namespace App\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class PasskeyCeremonyTrace
{
    public static function record(Request $request, string $phase): void
    {
        if (! app()->environment('testing', 'local')) {
            return;
        }

        $id = $request->query('webauthn_probe');
        if (! is_string($id) || ! preg_match('/\A[0-9a-f]{16}\z/', $id)) {
            return;
        }

        $started = $request->attributes->get('builderad.passkey_trace_started', hrtime(true));
        $request->attributes->set('builderad.passkey_trace_started', $started);
        Log::info('builderad_webauthn', ['operation' => $id, 'phase' => $phase,
            'elapsed_ms' => round((hrtime(true) - $started) / 1e6, 3)]);
    }
}
