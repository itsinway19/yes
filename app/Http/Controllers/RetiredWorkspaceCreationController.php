<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/** New advertiser and agency ownership is created through Business only. */
final class RetiredWorkspaceCreationController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['error' => 'workspace_creation_retired', 'next' => '/businesses/new'], 410)
            ->header('Cache-Control', 'no-store');
    }
}
