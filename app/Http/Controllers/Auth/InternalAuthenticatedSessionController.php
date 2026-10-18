<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\AuthEntryPage;
use App\Security\IdentityAuthentication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InternalAuthenticatedSessionController extends Controller
{
    public function create(): AuthEntryPage
    {
        return new AuthEntryPage('login', ['status' => session('status')]);
    }

    public function destroy(Request $request, IdentityAuthentication $identity): JsonResponse
    {
        return $identity->close($request);
    }
}
