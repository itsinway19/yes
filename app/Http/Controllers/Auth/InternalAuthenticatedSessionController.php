<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Security\SessionAuthority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class InternalAuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Internal/Login', ['status' => session('status')]);
    }

    public function destroy(Request $request, SessionAuthority $sessions): RedirectResponse
    {
        $sessions->revokeCurrent($request);
        Auth::guard('internal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Inertia::clearHistory();

        return redirect()->route('internal.login');
    }
}
