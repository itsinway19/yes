<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class RetiredWorkspaceCommercialController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['error' => 'workspace_commercial_route_retired', 'businesses' => '/businesses'], 410)
            ->header('Cache-Control', 'no-store');
    }
}
