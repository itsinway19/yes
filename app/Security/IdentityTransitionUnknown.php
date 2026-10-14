<?php

namespace App\Security;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class IdentityTransitionUnknown extends RuntimeException
{
    public function __construct(public readonly string $lineage, public readonly string $nonce, Throwable $previous)
    {
        parent::__construct('Identity transition commit outcome requires nonce resolution.', 0, $previous);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'identityUnknown' => true,
            'resolve' => $request->is('internal/*') ? '/internal/identity/transition' : '/identity/transition'], 503)
            ->header('Cache-Control', 'no-store');
    }
}
