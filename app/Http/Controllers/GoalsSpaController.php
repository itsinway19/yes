<?php

namespace App\Http\Controllers;

use App\Business\AdvertiserGoalPreferences;
use App\Business\BusinessContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\Workspace;
use App\Security\ClientVisibility;
use Illuminate\Http\Request;

/** Contextual native SPA read; writes retain their canonical controller. */
final class GoalsSpaController extends Controller
{
    public function context(Request $request, string $workspace, WorkspaceContext $context,
        AdvertiserGoalPreferences $goals, BusinessContext $business): mixed
    {
        $witness = $request->header('X-Goals-Witness') === '1';
        if ($witness || $request->header('X-Goals-Passive') === '1') {
            $request->attributes->set('builderad.visibility_probe', true);
        }

        return $context->run($request->user(), $workspace, function (Workspace $active) use ($request, $goals, $business, $witness) {
            $visibility = app(ClientVisibility::class)->describe($request);
            if ($witness) {
                return response()->json(['visibility' => $visibility])->header('Cache-Control', 'no-store');
            }

            return response()->json([
                'workspace' => $active->only(['public_id', 'name']),
                'preferences' => $goals->projection($active),
                'business' => $business->projection($request, $active),
                'catalog' => AdvertiserGoalPreferences::catalog(),
                'visibility' => $visibility,
            ])->header('Cache-Control', 'no-store');
        }, $request);
    }
}
