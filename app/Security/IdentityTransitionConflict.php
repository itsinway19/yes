<?php

namespace App\Security;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class IdentityTransitionConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Identity transition precondition is no longer current.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'identityConflict' => true,
            'context' => $request->is('internal/*') ? '/internal/identity/context' : '/identity/context'], 409)
            ->header('Cache-Control', 'no-store');
    }
}
