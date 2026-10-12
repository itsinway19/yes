<?php

namespace App\Http\Controllers;

use App\Security\IdentityAuthentication;
use App\Security\SessionAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SecurityDevicesController extends Controller
{
    public function index(Request $request, SessionAuthority $sessions): Response
    {
        return Inertia::render('Security/Devices', [
            'internalContext' => $request->is('internal/*'),
            'sessions' => $sessions->activeFor($request, $request->user()),
            'passkeys' => $request->user()->passkeys()
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                ->map(fn ($passkey) => [
                    'id' => $passkey->id,
                    'name' => $passkey->name,
                    'provider' => $passkey->authenticator,
                    'created_at' => $passkey->created_at,
                    'last_used_at' => $passkey->last_used_at,
                ]),
        ]);
    }

    public function destroy(Request $request, SessionAuthority $sessions, int $session): RedirectResponse
    {
        $sessions->revokeSelected($request, $request->user(), $session);

        return back();
    }

    public function destroyOthers(Request $request, SessionAuthority $sessions): RedirectResponse
    {
        $sessions->revokeOthers($request, $request->user());

        return back();
    }

    public function destroyCurrent(Request $request, IdentityAuthentication $identity): JsonResponse
    {
        return $identity->close($request);
    }
}
