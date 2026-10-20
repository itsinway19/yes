<?php

namespace App\Http\Controllers;

use App\Http\Responses\SpaPage;
use App\Security\IdentityAuthentication;
use App\Security\SessionAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SecurityDevicesController extends Controller
{
    public function index(Request $request, SessionAuthority $sessions): SpaPage
    {
        return new SpaPage(SpaPage::needsData($request) ? $this->projection($request, $sessions) : []);
    }

    public function application(Request $request, SessionAuthority $sessions): SpaPage
    {
        return new SpaPage(SpaPage::needsData($request) ? $this->projection($request, $sessions) : []);
    }

    private function projection(Request $request, SessionAuthority $sessions): array
    {
        $data = [
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
        ];

        return $data;
    }

    public function destroy(Request $request, SessionAuthority $sessions, int $session): RedirectResponse|JsonResponse
    {
        $sessions->revokeSelected($request, $request->user(), $session);

        return $this->acknowledge($request);
    }

    public function destroyOthers(Request $request, SessionAuthority $sessions): RedirectResponse|JsonResponse
    {
        $sessions->revokeOthers($request, $request->user());

        return $this->acknowledge($request);
    }

    public function destroyCurrent(Request $request, IdentityAuthentication $identity): JsonResponse
    {
        return $identity->close($request);
    }

    private function acknowledge(Request $request): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['saved' => true])->header('Cache-Control', 'no-store') : back();
    }
}
