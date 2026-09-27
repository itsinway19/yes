<?php

namespace App\Http\Controllers;

use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceFoundationController
{
    public function __invoke(Request $request, string $workspace, WorkspaceContext $context): JsonResponse
    {
        return $context->run(
            $request->user(),
            $workspace,
            fn (Workspace $activeWorkspace) => response()->json([
                'workspace' => [
                    'public_id' => $activeWorkspace->public_id,
                    'name' => $activeWorkspace->name,
                    'slug' => $activeWorkspace->slug,
                ],
            ]),
            $request,
        );
    }
}
