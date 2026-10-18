<?php

if (config('spa.goals_enabled')) {
    require __DIR__.'/goals-spa.php';
}

use App\Http\Controllers\AdvertiserGoalPreferencesController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\IdentityContextController;
use App\Http\Controllers\Auth\InternalAuthenticatedSessionController;
use App\Http\Controllers\Auth\PasskeySessionController;
use App\Http\Controllers\BusinessContextController;
use App\Http\Controllers\CommercialController;
use App\Http\Controllers\CommercialMarketPreparationController;
use App\Http\Controllers\GoalsSpaController;
use App\Http\Controllers\GoogleConnectionController;
use App\Http\Controllers\GoogleReportController;
use App\Http\Controllers\InternalAdministrationController;
use App\Http\Controllers\InternalBusinessTypeController;
use App\Http\Controllers\InternalCustomerAdministrationController;
use App\Http\Controllers\InternalServiceController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MetaConnectionController;
use App\Http\Controllers\MetaReportController;
use App\Http\Controllers\ObjectPurposeController;
use App\Http\Controllers\PasskeyManagementController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SecurityDevicesController;
use App\Http\Controllers\SnapchatConnectionController;
use App\Http\Controllers\SnapchatReportController;
use App\Http\Controllers\StaffOnboardingController;
use App\Http\Controllers\TikTokConnectionController;
use App\Http\Controllers\TikTokReportController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceFoundationController;
use App\Http\Controllers\WorkspaceServiceController;
use App\Http\Controllers\WorkspaceSourceController;
use App\Http\Controllers\XConnectionController;
use App\Http\Controllers\XReportController;
use App\Http\Middleware\EnsureBusinessContext;
use App\Http\Middleware\EnsureCurrentPrincipal;
use App\Http\Middleware\EnsureFreshAuthentication;
use App\Http\Middleware\EnsurePendingStaffEnrollment;
use App\Http\Middleware\RequireActionGrant;
use App\Http\Middleware\RestoreBusinessAfterCallback;
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

Route::post('/locale', LocaleController::class)->name('locale.update');
Route::post('/internal/locale', LocaleController::class)->name('internal.locale.update');

Route::get('/dashboard', [BusinessContextController::class, 'entry'])
    ->middleware(['auth', EnsureCurrentPrincipal::class])->name('dashboard');
Route::middleware(['auth', EnsureCurrentPrincipal::class])->group(function () {
    Route::get('/workspaces/{workspace}/goals', [AdvertiserGoalPreferencesController::class, 'edit'])
        ->whereUuid('workspace')->name('workspaces.goals.edit');
    Route::get('/workspaces/{workspace}/goals/review', [AdvertiserGoalPreferencesController::class, 'edit'])
        ->whereUuid('workspace')->name('workspaces.goals.review');
    Route::get('/workspaces/{workspace}/goals/context', [GoalsSpaController::class, 'context'])
        ->whereUuid('workspace')->name('workspaces.goals.context');
    Route::post('/workspaces/{workspace}/goals', [AdvertiserGoalPreferencesController::class, 'update'])
        ->whereUuid('workspace')->name('workspaces.goals.update');
    Route::get('/workspaces/{workspace}/object-purposes', [ObjectPurposeController::class, 'index'])
        ->whereUuid('workspace')->name('workspaces.object-purposes.index');
    Route::get('/workspaces/{workspace}/object-purposes/context', [ObjectPurposeController::class, 'applicationIndex'])
        ->whereUuid('workspace')->name('workspaces.object-purposes.application.index');
    Route::get('/workspaces/{workspace}/object-purposes/{purpose}/context', [ObjectPurposeController::class, 'applicationShow'])
        ->whereUuid('workspace')->whereUuid('purpose')->name('workspaces.object-purposes.application.show');
    Route::get('/workspaces/{workspace}/object-purposes/{purpose}', [ObjectPurposeController::class, 'show'])
        ->whereUuid('workspace')->whereUuid('purpose')->name('workspaces.object-purposes.show');
    Route::post('/workspaces/{workspace}/object-purposes', [ObjectPurposeController::class, 'store'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->name('workspaces.object-purposes.store');
    Route::post('/workspaces/{workspace}/object-purposes/{purpose}/revisions', [ObjectPurposeController::class, 'revise'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->whereUuid('purpose')->name('workspaces.object-purposes.revise');
    Route::get('/business/start', [BusinessContextController::class, 'start'])->name('business.start');
    Route::post('/business/start', [BusinessContextController::class, 'store'])->name('business.start.store');
    Route::get('/workspaces/{workspace}/business', [BusinessContextController::class, 'edit'])
        ->whereUuid('workspace')->name('workspaces.business.edit');
    Route::post('/workspaces/{workspace}/business', [BusinessContextController::class, 'update'])
        ->whereUuid('workspace')->name('workspaces.business.update');
    Route::post('/workspaces/{workspace}/business/target-markets', [BusinessContextController::class, 'updateTargetMarkets'])
        ->whereUuid('workspace')->name('workspaces.business.target-markets.update');
    Route::post('/workspaces/{workspace}/business/current-intent', [BusinessContextController::class, 'updateCurrentIntent'])
        ->whereUuid('workspace')->name('workspaces.business.current-intent.update');
    Route::post('/workspaces/{workspace}/business/authority', [BusinessContextController::class, 'authority'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->name('workspaces.business.authority');
});

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
    Route::get('/providers/x/callback', [XConnectionController::class, 'callback'])->middleware(RestoreBusinessAfterCallback::class)->name('providers.x.callback');
    Route::get('/workspaces/{workspace}/providers/x/{source}/campaigns', [XReportController::class, 'campaigns'])
        ->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.x.campaigns');
    Route::get('/workspaces/{workspace}/providers/x/{source}/report', [XReportController::class, 'status'])
        ->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.x.report.status');
    Route::post('/workspaces/{workspace}/providers/x/{source}/report', [XReportController::class, 'store'])
        ->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.x.report.store');
    Route::get('/workspaces/{workspace}/providers/x', [XConnectionController::class, 'index'])
        ->whereUuid('workspace')->name('workspaces.providers.x.index');
    Route::get('/workspaces/{workspace}/providers/x/discovery/{flow}', [XConnectionController::class, 'discovery'])
        ->whereUuid('workspace')->where('flow', '[A-Za-z0-9_-]{43}')->name('workspaces.providers.x.discovery');
    Route::post('/workspaces/{workspace}/providers/x/connect', [XConnectionController::class, 'connect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->name('workspaces.providers.x.connect');
    Route::post('/workspaces/{workspace}/providers/x/select', [XConnectionController::class, 'select'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->name('workspaces.providers.x.select');
    Route::post('/workspaces/{workspace}/providers/x/{source}/disconnect', [XConnectionController::class, 'disconnect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.x.disconnect');
    Route::get('/providers/snapchat/callback', [SnapchatConnectionController::class, 'callback'])->middleware(RestoreBusinessAfterCallback::class)->name('providers.snapchat.callback');
    Route::get('/workspaces/{workspace}/providers/snapchat/{source}/report', [SnapchatReportController::class, 'status'])
        ->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.snapchat.report.status');
    Route::post('/workspaces/{workspace}/providers/snapchat/{source}/report', [SnapchatReportController::class, 'store'])
        ->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.snapchat.report.store');
    Route::get('/workspaces/{workspace}/providers/snapchat', [SnapchatConnectionController::class, 'index'])
        ->whereUuid('workspace')->name('workspaces.providers.snapchat.index');
    Route::get('/workspaces/{workspace}/providers/snapchat/discovery/{flow}', [SnapchatConnectionController::class, 'discovery'])
        ->whereUuid('workspace')->where('flow', '[A-Za-z0-9_-]{43}')->name('workspaces.providers.snapchat.discovery');
    Route::post('/workspaces/{workspace}/providers/snapchat/connect', [SnapchatConnectionController::class, 'connect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->name('workspaces.providers.snapchat.connect');
    Route::post('/workspaces/{workspace}/providers/snapchat/select', [SnapchatConnectionController::class, 'select'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->name('workspaces.providers.snapchat.select');
    Route::post('/workspaces/{workspace}/providers/snapchat/{source}/disconnect', [SnapchatConnectionController::class, 'disconnect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.snapchat.disconnect');
    Route::post('/workspaces/{workspace}/providers/snapchat/{source}/renew', [SnapchatConnectionController::class, 'renew'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.snapchat.renew');
    Route::get('/providers/google/callback', [GoogleConnectionController::class, 'callback'])->middleware(RestoreBusinessAfterCallback::class)->name('providers.google.callback');
    Route::get('/providers/tiktok/callback', [TikTokConnectionController::class, 'callback'])->middleware(RestoreBusinessAfterCallback::class)->name('providers.tiktok.callback');
    Route::get('/workspaces/{workspace}/providers/tiktok/{source}/report', [TikTokReportController::class, 'status'])
        ->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.tiktok.report.status');
    Route::post('/workspaces/{workspace}/providers/tiktok/{source}/report', [TikTokReportController::class, 'store'])
        ->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.tiktok.report.store');
    Route::get('/workspaces/{workspace}/providers/tiktok', [TikTokConnectionController::class, 'index'])
        ->whereUuid('workspace')->name('workspaces.providers.tiktok.index');
    Route::get('/workspaces/{workspace}/providers/tiktok/discovery/{flow}', [TikTokConnectionController::class, 'discovery'])
        ->whereUuid('workspace')->where('flow', '[A-Za-z0-9_-]{43}')->name('workspaces.providers.tiktok.discovery');
    Route::post('/workspaces/{workspace}/providers/tiktok/connect', [TikTokConnectionController::class, 'connect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->name('workspaces.providers.tiktok.connect');
    Route::post('/workspaces/{workspace}/providers/tiktok/select', [TikTokConnectionController::class, 'select'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->name('workspaces.providers.tiktok.select');
    Route::post('/workspaces/{workspace}/providers/tiktok/{source}/disconnect', [TikTokConnectionController::class, 'disconnect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.tiktok.disconnect');
    Route::get('/workspaces/{workspace}/providers/google/{source}/report', [GoogleReportController::class, 'status'])
        ->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.google.report.status');
    Route::get('/workspaces/{workspace}/providers/meta/{source}/report', [MetaReportController::class, 'status'])
        ->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.meta.report.status');
    Route::post('/workspaces/{workspace}/providers/meta/{source}/report', [MetaReportController::class, 'store'])
        ->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.meta.report.store');
    Route::get('/workspaces/{workspace}/providers/google/{source}/report/{generation}/download', [GoogleReportController::class, 'download'])
        ->whereUuid('workspace')->whereUuid('source')->where('generation', '[1-9][0-9]{0,17}')->name('workspaces.providers.google.report.download');
    Route::post('/workspaces/{workspace}/providers/google/{source}/report', [GoogleReportController::class, 'store'])
        ->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.google.report.store');
    Route::get('/workspaces/{workspace}/providers/google', [GoogleConnectionController::class, 'index'])
        ->whereUuid('workspace')->name('workspaces.providers.google.index');
    Route::get('/workspaces/{workspace}/providers/google/discovery/{flow}', [GoogleConnectionController::class, 'discovery'])
        ->whereUuid('workspace')->where('flow', '[A-Za-z0-9_-]{43}')->name('workspaces.providers.google.discovery');
    Route::get('/workspaces/{workspace}/providers/google/hierarchy/{flow}', [GoogleConnectionController::class, 'hierarchy'])
        ->whereUuid('workspace')->where('flow', '[A-Za-z0-9_-]{43}')->name('workspaces.providers.google.hierarchy');
    Route::post('/workspaces/{workspace}/providers/google/connect', [GoogleConnectionController::class, 'connect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->name('workspaces.providers.google.connect');
    Route::post('/workspaces/{workspace}/providers/google/select', [GoogleConnectionController::class, 'select'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->name('workspaces.providers.google.select');
    Route::post('/workspaces/{workspace}/providers/google/{source}/disconnect', [GoogleConnectionController::class, 'disconnect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.google.disconnect');
    Route::post('/workspaces/{workspace}/providers/google/{source}/renew', [GoogleConnectionController::class, 'renew'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.google.renew');
    Route::get('/providers/meta/callback', [MetaConnectionController::class, 'callback'])->middleware(RestoreBusinessAfterCallback::class)->name('providers.meta.callback');
    Route::get('/workspaces/{workspace}/providers/meta', [MetaConnectionController::class, 'index'])
        ->whereUuid('workspace')->name('workspaces.providers.meta.index');
    Route::get('/workspaces/{workspace}/providers/meta/discovery/{flow}', [MetaConnectionController::class, 'discovery'])
        ->whereUuid('workspace')->where('flow', '[A-Za-z0-9_-]{43}')->name('workspaces.providers.meta.discovery');
    Route::post('/workspaces/{workspace}/providers/meta/connect', [MetaConnectionController::class, 'connect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->name('workspaces.providers.meta.connect');
    Route::post('/workspaces/{workspace}/providers/meta/select', [MetaConnectionController::class, 'select'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->name('workspaces.providers.meta.select');
    Route::post('/workspaces/{workspace}/providers/meta/{source}/disconnect', [MetaConnectionController::class, 'disconnect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->whereUuid('source')->name('workspaces.providers.meta.disconnect');
    Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::post('/workspaces/{workspace}/reassess-owner-grant', [WorkspaceController::class, 'reassessOwnerGrant'])
        ->middleware(EnsureFreshAuthentication::class)
        ->whereUuid('workspace')->name('workspaces.reassess-owner-grant');
    Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show'])
        ->middleware(EnsureBusinessContext::class)->whereUuid('workspace')->name('workspaces.show');
    Route::post('/workspaces/{workspace}/services', [WorkspaceServiceController::class, 'store'])
        ->middleware(EnsureFreshAuthentication::class)
        ->whereUuid('workspace')->name('workspaces.services.store');
    Route::delete('/workspaces/{workspace}/services/{service}', [WorkspaceServiceController::class, 'destroy'])
        ->middleware(EnsureFreshAuthentication::class)
        ->whereUuid('workspace')->whereUuid('service')->name('workspaces.services.destroy');
    Route::get('/workspaces/{workspace}/foundation', WorkspaceFoundationController::class)
        ->whereUuid('workspace')
        ->name('workspaces.foundation');

    Route::middleware(EnsureFreshAuthentication::class)->group(function () {
        Route::post('/workspaces/{workspace}/source-grants', [WorkspaceSourceController::class, 'grant'])
            ->whereUuid('workspace')->name('workspaces.sources.grant');
        Route::post('/workspaces/{workspace}/sources', [WorkspaceSourceController::class, 'command'])
            ->whereUuid('workspace')->name('workspaces.sources.command');
        Route::post('/workspaces/{workspace}/source-audit/{obligation}/recover', [WorkspaceSourceController::class, 'recover'])
            ->whereUuid('workspace')->whereUuid('obligation')->name('workspaces.sources.recover');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/security/devices', [SecurityDevicesController::class, 'index'])->name('security.devices');
    Route::delete('/security/sessions/{session}', [SecurityDevicesController::class, 'destroy'])->middleware(EnsureFreshAuthentication::class)->whereNumber('session')->name('security.sessions.destroy');
    Route::post('/security/sessions/revoke-others', [SecurityDevicesController::class, 'destroyOthers'])->middleware(EnsureFreshAuthentication::class)->name('security.sessions.revoke-others');
    Route::post('/security/sessions/sign-out-this-device', [SecurityDevicesController::class, 'destroyCurrent'])->name('security.sessions.sign-out-current');
    Route::get('/security/passkeys/options', [PasskeyManagementController::class, 'options'])->middleware(EnsureFreshAuthentication::class)->name('security.passkeys.options');
    Route::post('/security/passkeys', [PasskeyManagementController::class, 'store'])->middleware(EnsureFreshAuthentication::class)->name('security.passkeys.store');
    Route::delete('/security/passkeys/{passkey}', [PasskeyManagementController::class, 'destroy'])->middleware(EnsureFreshAuthentication::class)->whereNumber('passkey')->name('security.passkeys.destroy');
});

Route::middleware('guest:internal')->group(function () {
    Route::get('/internal/login', [InternalAuthenticatedSessionController::class, 'create'])->name('internal.login');
    Route::get('/internal/auth/passkey/options', [PasskeySessionController::class, 'options'])
        ->middleware('throttle:12,1')->name('internal.auth.passkey.options');
    Route::post('/internal/auth/passkey', [PasskeySessionController::class, 'store'])
        ->middleware('throttle:6,1')->name('internal.auth.passkey.store');
});

Route::middleware(['auth:internal', EnsureCurrentPrincipal::class])->group(function () {
    Route::get('/internal/business-types', [InternalBusinessTypeController::class, 'index'])
        ->middleware(RequireActionGrant::class.':business.manage')->name('internal.business-types.index');
    Route::middleware([RequireActionGrant::class.':business.manage', EnsureFreshAuthentication::class])->group(function () {
        Route::post('/internal/business-types', [InternalBusinessTypeController::class, 'store'])->name('internal.business-types.store');
        Route::post('/internal/business-types/{type}', [InternalBusinessTypeController::class, 'update'])->whereNumber('type')->name('internal.business-types.update');
        Route::post('/internal/business-types/{type}/guidance', [InternalBusinessTypeController::class, 'guidance'])->whereNumber('type')->name('internal.business-types.guidance');
        Route::post('/internal/business-types/{type}/prior', [InternalBusinessTypeController::class, 'prior'])->whereNumber('type')->name('internal.business-types.prior');
    });
    Route::post('/internal/logout', [InternalAuthenticatedSessionController::class, 'destroy'])
        ->name('internal.logout');
    Route::get('/internal/security/devices', [SecurityDevicesController::class, 'index'])
        ->name('internal.security.devices');
    Route::delete('/internal/security/sessions/{session}', [SecurityDevicesController::class, 'destroy'])
        ->middleware(EnsureFreshAuthentication::class)->whereNumber('session')->name('internal.security.sessions.destroy');
    Route::post('/internal/security/sessions/revoke-others', [SecurityDevicesController::class, 'destroyOthers'])
        ->middleware(EnsureFreshAuthentication::class)->name('internal.security.sessions.revoke-others');
    Route::post('/internal/security/sessions/sign-out-this-device', [SecurityDevicesController::class, 'destroyCurrent'])
        ->name('internal.security.sessions.sign-out-current');
    Route::get('/internal/security/passkeys/options', [PasskeyManagementController::class, 'options'])
        ->middleware(EnsureFreshAuthentication::class)->name('internal.security.passkeys.options');
    Route::post('/internal/security/passkeys', [PasskeyManagementController::class, 'store'])
        ->middleware(EnsureFreshAuthentication::class)->name('internal.security.passkeys.store');
    Route::delete('/internal/security/passkeys/{passkey}', [PasskeyManagementController::class, 'destroy'])
        ->middleware(EnsureFreshAuthentication::class)->whereNumber('passkey')->name('internal.security.passkeys.destroy');

    Route::get('/internal/administration', [InternalAdministrationController::class, 'index'])
        ->middleware(RequireActionGrant::class.':staff.view')->name('internal.administration');
    Route::get('/internal/administration/customers', [InternalCustomerAdministrationController::class, 'index'])->name('internal.customers.index');
    Route::post('/internal/administration/customers/status', [InternalCustomerAdministrationController::class, 'status'])
        ->middleware(EnsureFreshAuthentication::class)->name('internal.customers.status');
    Route::get('/internal/administration/commercial-markets', [CommercialMarketPreparationController::class, 'index'])
        ->name('internal.commercial-market-preparation.index');
    Route::middleware(EnsureFreshAuthentication::class)->group(function () {
        Route::post('/internal/administration/commercial-markets', [CommercialMarketPreparationController::class, 'store'])->name('internal.commercial-market-preparation.store');
        Route::post('/internal/administration/commercial-markets/delegate', [CommercialMarketPreparationController::class, 'delegate'])->name('internal.commercial-market-preparation.delegate');
        Route::post('/internal/administration/commercial-markets/new-intent', [CommercialMarketPreparationController::class, 'newIntent'])->name('internal.commercial-market-preparation.new-intent');
        Route::post('/internal/administration/commercial-markets/{approval}/operator', [CommercialMarketPreparationController::class, 'operator'])->whereUuid('approval')->name('internal.commercial-market-preparation.operator');
    });
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

Route::middleware(['auth:internal', EnsureCurrentPrincipal::class])->prefix('internal/commercial')->name('internal.commercial.')->group(function () {
    Route::get('/', [CommercialController::class, 'index'])->name('index');
    Route::get('/workspace-reference', [CommercialController::class, 'workspace'])->name('workspace');
    Route::get('/receipts/{command}', [CommercialController::class, 'receipt'])->whereUuid('command')->name('receipt');
    Route::get('/contexts/{scope}', [CommercialController::class, 'context'])->whereUuid('scope')->name('context');
    Route::middleware(EnsureFreshAuthentication::class)->group(function () {
        Route::post('/markets', [CommercialController::class, 'market'])->name('market');
        Route::post('/catalog-references', [CommercialController::class, 'catalog'])->name('catalog');
        Route::post('/configurations', [CommercialController::class, 'configuration'])->name('configuration');
        Route::post('/assignability', [CommercialController::class, 'assignability'])->name('assignability');
        Route::post('/registrations', [CommercialController::class, 'register'])->name('register');
        Route::post('/audit/{obligation}/recover', [CommercialController::class, 'recover'])->whereUuid('obligation')->name('recover');
        Route::post('/grants', [CommercialController::class, 'grant'])->name('grant');
        Route::delete('/grants/{grant}', [CommercialController::class, 'revokeGrant'])->whereNumber('grant')->name('revoke-grant');
        Route::post('/owner-mandate', [CommercialController::class, 'mandate'])->name('mandate');
    });
});

require __DIR__.'/auth.php';

foreach (['' => 'advertiser', '/internal' => 'internal'] as $prefix => $boundary) {
    Route::get($prefix.'/identity/context', [IdentityContextController::class, 'show']);
    Route::get($prefix.'/identity/transition', [IdentityContextController::class, 'resolve']);
    Route::post($prefix.'/identity/renew', [IdentityContextController::class, 'renew']);
    Route::post($prefix.'/identity/rebind', [IdentityContextController::class, 'rebind']);
}
Route::middleware('auth')->group(function () {
    Route::get('/identity/switch', [AuthenticatedSessionController::class, 'create']);
    Route::post('/identity/switch', [AuthenticatedSessionController::class, 'store']);
    Route::get('/identity/passkey/options', [PasskeySessionController::class, 'options']);
    Route::post('/identity/passkey', [PasskeySessionController::class, 'store']);
});
Route::middleware('auth:internal')->group(function () {
    Route::get('/internal/identity/switch', [InternalAuthenticatedSessionController::class, 'create']);
    Route::get('/internal/identity/passkey/options', [PasskeySessionController::class, 'options']);
    Route::post('/internal/identity/passkey', [PasskeySessionController::class, 'store']);
});
