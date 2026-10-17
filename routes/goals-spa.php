<?php

use App\Http\Controllers\GoalsSpaController;
use App\Http\Middleware\EnsureCurrentPrincipal;
use Illuminate\Support\Facades\Route;

// Included by web.php: retain the complete identity/session/CSRF middleware stack.
Route::middleware(['auth', EnsureCurrentPrincipal::class])->group(function () {
    Route::get('/spa/workspaces/{workspace}/goals/{view?}', [GoalsSpaController::class, 'show'])
        ->whereUuid('workspace')->where('view', 'review')->name('workspaces.goals.spa.shell');
    Route::get('/spa/api/workspaces/{workspace}/goals', [GoalsSpaController::class, 'show'])
        ->whereUuid('workspace')->name('workspaces.goals.spa.read');
});
