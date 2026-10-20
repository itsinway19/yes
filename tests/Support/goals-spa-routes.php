<?php

use App\Business\AdvertiserGoalPreferences;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Http\Middleware\EnsureCurrentPrincipal;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Loaded by the explicit disposable server or integration test only.
Route::middleware(['web', 'auth', EnsureCurrentPrincipal::class])->group(function () {
    // Saves deliberately use the existing production POST route, controller and SQL writer.
    Route::get('/workspaces/{workspace}/goals/events', function (Request $request, string $workspace, WorkspaceContext $context, AdvertiserGoalPreferences $goals) {
        $event = $context->run($request->user(), $workspace, fn (Workspace $active) => [
            'workspace' => $active->public_id, 'version' => $goals->projection($active)['version'],
            'binding' => $request->attributes->get('builderad.identity_document'),
        ], $request);

        // Finite authorized SSE proof. No claim of durable delivery, resume or long-lived revocation.
        return response()->stream(function () use ($event) {
            echo 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n";
            flush();
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-store']);
    })->whereUuid('workspace')->name('workspaces.goals.proof.event');
});
