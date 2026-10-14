<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Security\IdentityAuthentication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class InternalAuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Internal/Login', ['status' => session('status')]);
    }

    public function destroy(Request $request, IdentityAuthentication $identity): JsonResponse
    {
        return $identity->close($request);
    }
}
