<?php

use App\Http\Controllers\InternalAdministrationController;
use App\Http\Controllers\InternalServiceController;
use App\Http\Controllers\PasskeyManagementController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SecurityDevicesController;
use App\Http\Controllers\StaffOnboardingController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceFoundationController;
use App\Http\Controllers\WorkspaceServiceController;
use App\Http\Middleware\EnsureCurrentPrincipal;
use App\Http\Middleware\EnsureFreshAuthentication;
use App\Http\Middleware\EnsurePendingStaffEnrollment;
use App\Http\Middleware\RequireActionGrant;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', EnsureCurrentPrincipal::class])->name('dashboard');

Route::middleware(['auth', EnsurePendingStaffEnrollment::class])->group(function () {
    Route::get('/staff/onboarding', StaffOnboardingController::class)->name('staff.onboarding');
});
Route::middleware(['auth', EnsurePendingStaffEnrollment::class.':true'])->group(function () {
    Route::get('/staff/onboarding/passkeys/options', [PasskeyManagementController::class, 'options'])
        ->name('staff.onboarding.passkeys.options');
    Route::post('/staff/onboarding/passkeys', [PasskeyManagementController::class, 'store'])
        ->name('staff.onboarding.passkeys.store');
});

Route::middleware(['auth', EnsureCurrentPrincipal::class])->group(function () {
    Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::post('/workspaces/{workspace}/reassess-owner-grant', [WorkspaceController::class, 'reassessOwnerGrant'])
        ->middleware(EnsureFreshAuthentication::class)
        ->whereUuid('workspace')->name('workspaces.reassess-owner-grant');
    Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show'])
        ->whereUuid('workspace')->name('workspaces.show');
    Route::post('/workspaces/{workspace}/services', [WorkspaceServiceController::class, 'store'])
        ->middleware(EnsureFreshAuthentication::class)
        ->whereUuid('workspace')->name('workspaces.services.store');
    Route::delete('/workspaces/{workspace}/services/{service}', [WorkspaceServiceController::class, 'destroy'])
        ->middleware(EnsureFreshAuthentication::class)
        ->whereUuid('workspace')->whereUuid('service')->name('workspaces.services.destroy');
    Route::get('/workspaces/{workspace}/foundation', WorkspaceFoundationController::class)
        ->whereUuid('workspace')
        ->name('workspaces.foundation');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/security/devices', [SecurityDevicesController::class, 'index'])->name('security.devices');
    Route::delete('/security/sessions/{session}', [SecurityDevicesController::class, 'destroy'])->middleware(EnsureFreshAuthentication::class)->whereNumber('session')->name('security.sessions.destroy');
    Route::post('/security/sessions/revoke-others', [SecurityDevicesController::class, 'destroyOthers'])->middleware(EnsureFreshAuthentication::class)->name('security.sessions.revoke-others');
    Route::post('/security/sessions/sign-out-this-device', [SecurityDevicesController::class, 'destroyCurrent'])->name('security.sessions.sign-out-current');
    Route::get('/security/passkeys/options', [PasskeyManagementController::class, 'options'])->middleware(EnsureFreshAuthentication::class)->name('security.passkeys.options');
    Route::post('/security/passkeys', [PasskeyManagementController::class, 'store'])->middleware(EnsureFreshAuthentication::class)->name('security.passkeys.store');
    Route::delete('/security/passkeys/{passkey}', [PasskeyManagementController::class, 'destroy'])->middleware(EnsureFreshAuthentication::class)->whereNumber('passkey')->name('security.passkeys.destroy');
    Route::get('/internal/administration', [InternalAdministrationController::class, 'index'])
        ->middleware(RequireActionGrant::class.':staff.view')->name('internal.administration');
    Route::post('/internal/staff', [InternalAdministrationController::class, 'invite'])
        ->middleware([RequireActionGrant::class.':staff.invite', EnsureFreshAuthentication::class])
        ->name('internal.staff.invite');
    Route::post('/internal/staff/{staff}/resend-invitation', [InternalAdministrationController::class, 'resendInvitation'])
        ->middleware([RequireActionGrant::class.':staff.invite', EnsureFreshAuthentication::class, 'throttle:3,1'])
        ->whereUuid('staff')->name('internal.staff.resend-invitation');
    Route::post('/internal/staff/{staff}/attest', [InternalAdministrationController::class, 'attest'])
        ->middleware([RequireActionGrant::class.':staff.attest', EnsureFreshAuthentication::class])
        ->whereUuid('staff')->name('internal.staff.attest');
    Route::post('/internal/staff/{staff}/activate', [InternalAdministrationController::class, 'activate'])
        ->middleware([RequireActionGrant::class.':staff.activate', EnsureFreshAuthentication::class])
        ->whereUuid('staff')->name('internal.staff.activate');
    Route::post('/internal/staff/{staff}/revoke', [InternalAdministrationController::class, 'revoke'])
        ->middleware([RequireActionGrant::class.':staff.revoke', EnsureFreshAuthentication::class])
        ->whereUuid('staff')->name('internal.staff.revoke');
    Route::post('/internal/staff/{staff}/attest-factors', [InternalAdministrationController::class, 'attestFactors'])
        ->middleware([RequireActionGrant::class.':grant.manage', EnsureFreshAuthentication::class])
        ->whereUuid('staff')->name('internal.staff.attest-factors');
    Route::post('/internal/grants', [InternalAdministrationController::class, 'grant'])
        ->middleware([RequireActionGrant::class.':grant.manage', EnsureFreshAuthentication::class])
        ->name('internal.grants.store');
    Route::delete('/internal/grants/{grant}', [InternalAdministrationController::class, 'revokeGrant'])
        ->middleware([RequireActionGrant::class.':grant.manage', EnsureFreshAuthentication::class])
        ->whereNumber('grant')->name('internal.grants.destroy');
    Route::get('/internal/services', [InternalServiceController::class, 'index'])
        ->middleware(RequireActionGrant::class.':service.manage')->name('internal.services.index');
    Route::post('/internal/services', [InternalServiceController::class, 'create'])
        ->middleware([RequireActionGrant::class.':service.manage', EnsureFreshAuthentication::class])
        ->name('internal.services.create');
    Route::post('/internal/services/{service}/rotate', [InternalServiceController::class, 'rotate'])
        ->middleware([RequireActionGrant::class.':service.manage', EnsureFreshAuthentication::class])
        ->whereUuid('service')->name('internal.services.rotate');
    Route::post('/internal/services/{service}/revoke', [InternalServiceController::class, 'revoke'])
        ->middleware([RequireActionGrant::class.':service.manage', EnsureFreshAuthentication::class])
        ->whereUuid('service')->name('internal.services.revoke');
    Route::post('/internal/service-grants', [InternalServiceController::class, 'grant'])
        ->middleware([RequireActionGrant::class.':service.manage', EnsureFreshAuthentication::class])
        ->name('internal.service-grants.create');
    Route::delete('/internal/service-grants/{grant}', [InternalServiceController::class, 'revokeGrant'])
        ->middleware([RequireActionGrant::class.':service.manage', EnsureFreshAuthentication::class])
        ->whereNumber('grant')->name('internal.service-grants.destroy');
});

require __DIR__.'/auth.php';
