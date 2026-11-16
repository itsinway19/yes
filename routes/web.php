<?php

use App\Appearance\GlobalAppearance;
use App\Content\PublicContent;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\IdentityContextController;
use App\Http\Controllers\Auth\InternalAuthenticatedSessionController;
use App\Http\Controllers\Auth\PasskeySessionController;
use App\Http\Controllers\BusinessActivityController;
use App\Http\Controllers\BusinessFindingController;
use App\Http\Controllers\BusinessFoundationController;
use App\Http\Controllers\BusinessServiceController;
use App\Http\Controllers\BusinessSourceController;
use App\Http\Controllers\BusinessSurfaceController;
use App\Http\Controllers\CommercialController;
use App\Http\Controllers\CommercialMarketPreparationController;
use App\Http\Controllers\GlobalAppearanceController;
use App\Http\Controllers\GoogleConnectionController;
use App\Http\Controllers\GoogleReportController;
use App\Http\Controllers\InquiryEvidenceController;
use App\Http\Controllers\InternalAdministrationController;
use App\Http\Controllers\InternalBusinessTypeController;
use App\Http\Controllers\InternalContentController;
use App\Http\Controllers\InternalCustomerAdministrationController;
use App\Http\Controllers\InternalServiceController;
use App\Http\Controllers\KnowledgeConsumerController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MarketKnowledgeController;
use App\Http\Controllers\MarketOperationsController;
use App\Http\Controllers\MarketProcessingController;
use App\Http\Controllers\MetaConnectionController;
use App\Http\Controllers\MetaReportController;
use App\Http\Controllers\PasskeyManagementController;
use App\Http\Controllers\PlatformMaintenanceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicContentController;
use App\Http\Controllers\SecurityDevicesController;
use App\Http\Controllers\SnapchatConnectionController;
use App\Http\Controllers\SnapchatReportController;
use App\Http\Controllers\StaffOnboardingController;
use App\Http\Controllers\TikTokConnectionController;
use App\Http\Controllers\TikTokReportController;
use App\Http\Controllers\XConnectionController;
use App\Http\Controllers\XReportController;
use App\Http\Middleware\EnsureCurrentPrincipal;
use App\Http\Middleware\EnsureFreshAuthentication;
use App\Http\Middleware\EnsurePendingStaffEnrollment;
use App\Http\Middleware\RequireActionGrant;
use App\Http\Middleware\RequireContentAction;
use App\Security\IdentityDocument;
use Illuminate\Support\Facades\Route;

Route::get('/', function (PublicContent $content) {
    return response()->view('welcome', ['locale' => app()->getLocale(),
        'navigation' => $content->navigation(),
        'footer' => $content->footer(),
        'appearanceTokens' => GlobalAppearance::tokens(app(GlobalAppearance::class)->current()),
        'maintenance' => request()->attributes->get('platform.maintenance'),
        'publicIdentity' => app(IdentityDocument::class)->metadata(request())])->header('Cache-Control', 'no-store');
});

Route::get('/p/{slug}', [PublicContentController::class, 'page'])->where('slug', '[^/]+')->name('content.page');
Route::get('/brand/assets/{hash}.png', [GlobalAppearanceController::class, 'image'])->where('hash', '[0-9a-f]{64}')
    ->withoutMiddleware('web')->name('appearance.image'); // Public immutable PNG bytes carry no session/identity headers.
Route::get('/articles', [PublicContentController::class, 'articles'])->name('content.articles');
Route::get('/articles/category/{slug}', [PublicContentController::class, 'category'])->where('slug', '[^/]+')->name('content.category');
Route::get('/articles/{slug}', [PublicContentController::class, 'article'])->where('slug', '[^/]+')->name('content.article');

Route::middleware(['auth:internal', EnsureCurrentPrincipal::class])->group(function () {
    Route::post('/internal/market-knowledge/publish', [MarketKnowledgeController::class, 'publish'])->name('internal.market-knowledge.publish');
    Route::post('/internal/market-knowledge/capture', [MarketKnowledgeController::class, 'capture'])->name('internal.market-knowledge.capture');
    Route::get('/internal/administration/market-knowledge', [MarketOperationsController::class, 'index'])->name('internal.market-knowledge.index');
    Route::get('/internal/administration/maintenance', [PlatformMaintenanceController::class, 'index'])->name('internal.maintenance.index');
    Route::post('/internal/administration/maintenance', [PlatformMaintenanceController::class, 'update'])->name('internal.maintenance.update');
});

Route::post('/locale', LocaleController::class)->name('locale.update');
Route::post('/internal/locale', LocaleController::class)->name('internal.locale.update');

Route::redirect('/dashboard', '/businesses?entry=1')
    ->middleware(['auth', EnsureCurrentPrincipal::class])->name('dashboard');
Route::middleware(['auth', EnsureCurrentPrincipal::class])->group(function () {
    Route::get('/api/businesses/{business}/inquiry-evidence/eligibility', [InquiryEvidenceController::class, 'eligibility'])->whereUuid('business')->name('business-foundation.inquiry-evidence.eligibility');
    Route::get('/api/businesses/{business}/inquiry-evidence', [InquiryEvidenceController::class, 'index'])->whereUuid('business')->name('business-foundation.inquiry-evidence.index');
    Route::get('/api/businesses/{business}/inquiry-evidence/{declaration}/history', [InquiryEvidenceController::class, 'history'])->whereUuid('business')->whereUuid('declaration')->name('business-foundation.inquiry-evidence.history');
    Route::post('/api/businesses/{business}/inquiry-evidence/enrollment', [InquiryEvidenceController::class, 'enroll'])->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('business-foundation.inquiry-evidence.enroll');
    Route::post('/api/businesses/{business}/inquiry-evidence/rights', [InquiryEvidenceController::class, 'revoke'])->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('business-foundation.inquiry-evidence.revoke');
    Route::post('/api/businesses/{business}/inquiry-evidence', [InquiryEvidenceController::class, 'store'])->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('business-foundation.inquiry-evidence.store');
    Route::post('/api/businesses/{business}/inquiry-evidence/{declaration}/revisions', [InquiryEvidenceController::class, 'revise'])->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->whereUuid('declaration')->name('business-foundation.inquiry-evidence.revise');

    Route::post('/api/businesses/{business}/knowledge/customer-discovery', [KnowledgeConsumerController::class, 'customers'])->whereUuid('business')->name('business-foundation.knowledge.customers');
    Route::post('/api/businesses/{business}/knowledge/competition', [KnowledgeConsumerController::class, 'competition'])->whereUuid('business')->name('business-foundation.knowledge.competition');
    Route::post('/api/businesses/{business}/market-findings/capture', [BusinessFindingController::class, 'capture'])->whereUuid('business')->name('business-foundation.market-findings.capture');
    Route::post('/api/businesses/{business}/market-analysis/prepare', [MarketProcessingController::class, 'prepare'])->whereUuid('business')->name('business-foundation.market-processing.prepare');
    Route::post('/api/businesses/{business}/market-analysis/{run}/complete', [MarketProcessingController::class, 'complete'])->whereUuid('business')->whereUuid('run')->name('business-foundation.market-processing.complete');
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
    // Business-native custody uses the same qualified provider controllers.
    Route::get('/businesses/{business}/source-registry', [BusinessSourceController::class, 'index'])->whereUuid('business')->name('businesses.providers.registry');
    Route::post('/businesses/{business}/services', [BusinessServiceController::class, 'approve'])->whereUuid('business')->middleware(EnsureFreshAuthentication::class)->name('businesses.providers.services.approve');
    Route::delete('/businesses/{business}/services/{service}', [BusinessServiceController::class, 'revoke'])->whereUuid(['business', 'service'])->middleware(EnsureFreshAuthentication::class)->name('businesses.providers.services.revoke');
    Route::post('/api/businesses/{business}/service-approval/enrollment', [BusinessServiceController::class, 'enroll'])->whereUuid('business')->middleware(EnsureFreshAuthentication::class)->name('business-foundation.services.enroll');
    Route::post('/businesses/{business}/sources', [BusinessSourceController::class, 'command'])->whereUuid('business')->middleware(EnsureFreshAuthentication::class)->name('businesses.providers.registry.command');
    Route::post('/businesses/{business}/source-grants', [BusinessSourceController::class, 'grant'])->whereUuid('business')->middleware(EnsureFreshAuthentication::class)->name('businesses.providers.registry.grant');
    Route::post('/businesses/{business}/source-audit/{obligation}/recover', [BusinessSourceController::class, 'recoverBusiness'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->whereUuid('obligation')->name('businesses.providers.audit.recover');
    Route::get('/businesses/{business}/providers/x/{source}/campaigns', [XReportController::class, 'campaigns'])
        ->whereUuid('business')->whereUuid('source')->name('businesses.providers.x.campaigns');
    Route::get('/businesses/{business}/providers/x/{source}/report', [XReportController::class, 'status'])
        ->whereUuid('business')->whereUuid('source')->name('businesses.providers.x.report.status');
    Route::post('/businesses/{business}/providers/x/{source}/report', [XReportController::class, 'store'])
        ->whereUuid('business')->whereUuid('source')->name('businesses.providers.x.report.store');
    Route::get('/businesses/{business}/providers/x', [XConnectionController::class, 'index'])
        ->whereUuid('business')->name('businesses.providers.x.index');
    Route::get('/businesses/{business}/providers/x/discovery/{flow}', [XConnectionController::class, 'discovery'])
        ->whereUuid('business')->where('flow', '[A-Za-z0-9_-]{43}')->name('businesses.providers.x.discovery');
    Route::post('/businesses/{business}/providers/x/connect', [XConnectionController::class, 'connect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('businesses.providers.x.connect');
    Route::post('/businesses/{business}/providers/x/select', [XConnectionController::class, 'select'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('businesses.providers.x.select');
    Route::post('/businesses/{business}/providers/x/{source}/disconnect', [XConnectionController::class, 'disconnect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->whereUuid('source')->name('businesses.providers.x.disconnect');
    Route::get('/businesses/{business}/providers/snapchat/{source}/report', [SnapchatReportController::class, 'status'])
        ->whereUuid('business')->whereUuid('source')->name('businesses.providers.snapchat.report.status');
    Route::post('/businesses/{business}/providers/snapchat/{source}/report', [SnapchatReportController::class, 'store'])
        ->whereUuid('business')->whereUuid('source')->name('businesses.providers.snapchat.report.store');
    Route::get('/businesses/{business}/providers/snapchat', [SnapchatConnectionController::class, 'index'])
        ->whereUuid('business')->name('businesses.providers.snapchat.index');
    Route::get('/businesses/{business}/providers/snapchat/discovery/{flow}', [SnapchatConnectionController::class, 'discovery'])
        ->whereUuid('business')->where('flow', '[A-Za-z0-9_-]{43}')->name('businesses.providers.snapchat.discovery');
    Route::post('/businesses/{business}/providers/snapchat/connect', [SnapchatConnectionController::class, 'connect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('businesses.providers.snapchat.connect');
    Route::post('/businesses/{business}/providers/snapchat/select', [SnapchatConnectionController::class, 'select'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('businesses.providers.snapchat.select');
    Route::post('/businesses/{business}/providers/snapchat/{source}/disconnect', [SnapchatConnectionController::class, 'disconnect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->whereUuid('source')->name('businesses.providers.snapchat.disconnect');
    Route::post('/businesses/{business}/providers/snapchat/{source}/renew', [SnapchatConnectionController::class, 'renew'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->whereUuid('source')->name('businesses.providers.snapchat.renew');
    Route::get('/businesses/{business}/providers/tiktok/{source}/report', [TikTokReportController::class, 'status'])
        ->whereUuid('business')->whereUuid('source')->name('businesses.providers.tiktok.report.status');
    Route::post('/businesses/{business}/providers/tiktok/{source}/report', [TikTokReportController::class, 'store'])
        ->whereUuid('business')->whereUuid('source')->name('businesses.providers.tiktok.report.store');
    Route::get('/businesses/{business}/providers/tiktok', [TikTokConnectionController::class, 'index'])
        ->whereUuid('business')->name('businesses.providers.tiktok.index');
    Route::get('/businesses/{business}/providers/tiktok/discovery/{flow}', [TikTokConnectionController::class, 'discovery'])
        ->whereUuid('business')->where('flow', '[A-Za-z0-9_-]{43}')->name('businesses.providers.tiktok.discovery');
    Route::post('/businesses/{business}/providers/tiktok/connect', [TikTokConnectionController::class, 'connect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('businesses.providers.tiktok.connect');
    Route::post('/businesses/{business}/providers/tiktok/select', [TikTokConnectionController::class, 'select'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('businesses.providers.tiktok.select');
    Route::post('/businesses/{business}/providers/tiktok/{source}/disconnect', [TikTokConnectionController::class, 'disconnect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->whereUuid('source')->name('businesses.providers.tiktok.disconnect');
    Route::get('/businesses/{business}/providers/google/{source}/report', [GoogleReportController::class, 'status'])
        ->whereUuid('business')->whereUuid('source')->name('businesses.providers.google.report.status');
    Route::get('/businesses/{business}/providers/meta/{source}/report', [MetaReportController::class, 'status'])
        ->whereUuid('business')->whereUuid('source')->name('businesses.providers.meta.report.status');
    Route::post('/businesses/{business}/providers/meta/{source}/report', [MetaReportController::class, 'store'])
        ->whereUuid('business')->whereUuid('source')->name('businesses.providers.meta.report.store');
    Route::get('/businesses/{business}/providers/google/{source}/report/{generation}/download', [GoogleReportController::class, 'download'])
        ->whereUuid('business')->whereUuid('source')->where('generation', '[1-9][0-9]{0,17}')->name('businesses.providers.google.report.download');
    Route::post('/businesses/{business}/providers/google/{source}/report', [GoogleReportController::class, 'store'])
        ->whereUuid('business')->whereUuid('source')->name('businesses.providers.google.report.store');
    Route::get('/businesses/{business}/providers/google', [GoogleConnectionController::class, 'index'])
        ->whereUuid('business')->name('businesses.providers.google.index');
    Route::get('/businesses/{business}/providers/google/discovery/{flow}', [GoogleConnectionController::class, 'discovery'])
        ->whereUuid('business')->where('flow', '[A-Za-z0-9_-]{43}')->name('businesses.providers.google.discovery');
    Route::get('/businesses/{business}/providers/google/hierarchy/{flow}', [GoogleConnectionController::class, 'hierarchy'])
        ->whereUuid('business')->where('flow', '[A-Za-z0-9_-]{43}')->name('businesses.providers.google.hierarchy');
    Route::post('/businesses/{business}/providers/google/connect', [GoogleConnectionController::class, 'connect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('businesses.providers.google.connect');
    Route::post('/businesses/{business}/providers/google/select', [GoogleConnectionController::class, 'select'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('businesses.providers.google.select');
    Route::post('/businesses/{business}/providers/google/{source}/disconnect', [GoogleConnectionController::class, 'disconnect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->whereUuid('source')->name('businesses.providers.google.disconnect');
    Route::post('/businesses/{business}/providers/google/{source}/renew', [GoogleConnectionController::class, 'renew'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->whereUuid('source')->name('businesses.providers.google.renew');
    Route::get('/businesses/{business}/providers/meta', [MetaConnectionController::class, 'index'])
        ->whereUuid('business')->name('businesses.providers.meta.index');
    Route::get('/businesses/{business}/providers/meta/discovery/{flow}', [MetaConnectionController::class, 'discovery'])
        ->whereUuid('business')->where('flow', '[A-Za-z0-9_-]{43}')->name('businesses.providers.meta.discovery');
    Route::post('/businesses/{business}/providers/meta/connect', [MetaConnectionController::class, 'connect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('businesses.providers.meta.connect');
    Route::post('/businesses/{business}/providers/meta/select', [MetaConnectionController::class, 'select'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('businesses.providers.meta.select');
    Route::post('/businesses/{business}/providers/meta/{source}/disconnect', [MetaConnectionController::class, 'disconnect'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->whereUuid('source')->name('businesses.providers.meta.disconnect');
    Route::get('/providers/x/callback', [XConnectionController::class, 'callback'])->name('providers.x.callback');
    Route::get('/providers/snapchat/callback', [SnapchatConnectionController::class, 'callback'])->name('providers.snapchat.callback');
    Route::get('/providers/google/callback', [GoogleConnectionController::class, 'callback'])->name('providers.google.callback');
    Route::get('/providers/tiktok/callback', [TikTokConnectionController::class, 'callback'])->name('providers.tiktok.callback');
    Route::get('/providers/meta/callback', [MetaConnectionController::class, 'callback'])->name('providers.meta.callback');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/security/devices', [SecurityDevicesController::class, 'application'])->name('security.devices');
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
    Route::get('/internal/administration/appearance', [GlobalAppearanceController::class, 'index'])->name('internal.appearance');
    Route::post('/internal/administration/appearance', [GlobalAppearanceController::class, 'publish'])
        ->name('internal.appearance.publish'); // Controller admits Founder authority before the same fresh-auth guard.
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

Route::middleware(['auth:internal', EnsureCurrentPrincipal::class, RequireContentAction::class])
    ->prefix('internal/administration/content')->name('internal.content.')->group(function () {
        Route::get('/sections', [InternalContentController::class, 'sections'])->name('sections');
        Route::get('/sections/{section}/links', [InternalContentController::class, 'sectionLinks'])->whereUuid('section')->name('section-links');
        Route::get('/pages', [InternalContentController::class, 'pages'])->name('pages');
        Route::get('/pages/{page}', [InternalContentController::class, 'pageEditor'])->whereUuid('page')->name('page');
        Route::get('/pages/{page}/preview', [InternalContentController::class, 'pagePreview'])->whereUuid('page')->name('page-preview');
        Route::get('/articles', [InternalContentController::class, 'articles'])->name('articles');
        Route::get('/articles/{article}', [InternalContentController::class, 'articleEditor'])->whereUuid('article')->name('article');
        Route::get('/articles/{article}/preview', [InternalContentController::class, 'articlePreview'])->whereUuid('article')->name('article-preview');
        Route::get('/categories', [InternalContentController::class, 'categories'])->name('categories');
        Route::get('/footer', [InternalContentController::class, 'footer'])->name('footer');

        // Routine content authoring uses a bounded 30-minute step-up window; security
        // operations keep the five-minute window in their own route groups.
        Route::middleware(EnsureFreshAuthentication::class.':1800')->group(function () {
            Route::post('/sections', [InternalContentController::class, 'storeSection'])->name('sections.store');
            Route::post('/sections/reorder', [InternalContentController::class, 'reorderSections'])->name('sections.reorder');
            Route::post('/sections/{section}', [InternalContentController::class, 'updateSection'])->whereUuid('section')->name('sections.update');
            Route::post('/sections/{section}/delete', [InternalContentController::class, 'deleteSection'])->whereUuid('section')->name('sections.delete');
            Route::post('/sections/{section}/links', [InternalContentController::class, 'storeLink'])->whereUuid('section')->name('links.store');
            Route::post('/sections/{section}/links/reorder', [InternalContentController::class, 'reorderLinks'])->whereUuid('section')->name('links.reorder');
            Route::post('/links/{link}', [InternalContentController::class, 'updateLink'])->whereUuid('link')->name('links.update');
            Route::post('/links/{link}/delete', [InternalContentController::class, 'deleteLink'])->whereUuid('link')->name('links.delete');
            Route::post('/pages', [InternalContentController::class, 'storePage'])->name('pages.store');
            Route::post('/pages/{page}', [InternalContentController::class, 'updatePage'])->whereUuid('page')->name('pages.update');
            Route::post('/pages/{page}/publish', [InternalContentController::class, 'publishPage'])->whereUuid('page')->name('pages.publish');
            Route::post('/pages/{page}/unpublish', [InternalContentController::class, 'unpublishPage'])->whereUuid('page')->name('pages.unpublish');
            Route::post('/pages/{page}/delete', [InternalContentController::class, 'deletePage'])->whereUuid('page')->name('pages.delete');
            Route::post('/pages/{page}/restore', [InternalContentController::class, 'restorePage'])->whereUuid('page')->name('pages.restore');
            Route::post('/pages/{page}/revisions/{revision}/restore', [InternalContentController::class, 'restorePageRevision'])
                ->whereUuid('page')->whereNumber('revision')->name('pages.revisions.restore');
            Route::post('/articles', [InternalContentController::class, 'storeArticle'])->name('articles.store');
            Route::post('/articles/{article}', [InternalContentController::class, 'updateArticle'])->whereUuid('article')->name('articles.update');
            Route::post('/articles/{article}/publish', [InternalContentController::class, 'publishArticle'])->whereUuid('article')->name('articles.publish');
            Route::post('/articles/{article}/unpublish', [InternalContentController::class, 'unpublishArticle'])->whereUuid('article')->name('articles.unpublish');
            Route::post('/articles/{article}/delete', [InternalContentController::class, 'deleteArticle'])->whereUuid('article')->name('articles.delete');
            Route::post('/articles/{article}/restore', [InternalContentController::class, 'restoreArticle'])->whereUuid('article')->name('articles.restore');
            Route::post('/articles/{article}/revisions/{revision}/restore', [InternalContentController::class, 'restoreArticleRevision'])
                ->whereUuid('article')->whereNumber('revision')->name('articles.revisions.restore');
            Route::post('/categories', [InternalContentController::class, 'storeCategory'])->name('categories.store');
            Route::post('/categories/{category}', [InternalContentController::class, 'updateCategory'])->whereUuid('category')->name('categories.update');
            Route::post('/categories/{category}/delete', [InternalContentController::class, 'deleteCategory'])->whereUuid('category')->name('categories.delete');
            Route::post('/footer', [InternalContentController::class, 'updateFooter'])->name('footer.update');
        });
    });

Route::middleware(['auth:internal', EnsureCurrentPrincipal::class])->prefix('internal/commercial')->name('internal.commercial.')->group(function () {
    Route::get('/', [CommercialController::class, 'index'])->name('index');
    Route::get('/business-reference', [CommercialController::class, 'business'])->name('business');
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

Route::get('/business-registration/catalog', [BusinessSurfaceController::class, 'setupCatalog'])
    ->name('business-registration.catalog');

Route::middleware(['auth', EnsureCurrentPrincipal::class])->prefix('api/businesses')->group(function () {
    Route::get('/{business}/practices', [BusinessActivityController::class, 'index'])->whereUuid('business')->name('business-activity.index');
    Route::post('/{business}/practices', [BusinessActivityController::class, 'store'])->whereUuid('business')->name('business-activity.store');
    Route::get('/{business}/practices/knowledge', [BusinessActivityController::class, 'knowledgeOptions'])->whereUuid('business')->name('business-activity.knowledge-options');
    Route::post('/{business}/knowledge/customer-discovery/practice', [BusinessActivityController::class, 'assess'])->whereUuid('business')->name('business-activity.assess');
    Route::get('/', [BusinessFoundationController::class, 'choices'])->name('business-foundation.choices');
    Route::get('/catalog', [BusinessFoundationController::class, 'catalog'])->name('business-foundation.catalog');
    Route::get('/{business}/surface', [BusinessFoundationController::class, 'surface'])->whereUuid('business')->name('business-foundation.surface');
    Route::get('/{business}/sources', [BusinessFoundationController::class, 'businessSources'])->whereUuid('business')->name('business-foundation.business-sources');
    Route::get('/{business}/purposes', [BusinessFoundationController::class, 'purposes'])->whereUuid('business')->name('business-foundation.purposes');
    Route::post('/{business}/purposes', [BusinessFoundationController::class, 'writePurpose'])->whereUuid('business')->name('business-foundation.write-purpose');
    Route::get('/{business}/history', [BusinessFoundationController::class, 'history'])->whereUuid('business')->name('business-foundation.history');
    Route::post('/', [BusinessFoundationController::class, 'store'])->name('business-foundation.store');
    Route::post('/{business}/read', [BusinessFoundationController::class, 'show'])->whereUuid('business')->name('business-foundation.show');
    Route::put('/{business}/context', [BusinessFoundationController::class, 'context'])->whereUuid('business')->name('business-foundation.context');
    Route::put('/{business}/markets', [BusinessFoundationController::class, 'markets'])->whereUuid('business')->name('business-foundation.markets');
    Route::put('/{business}/preferences', [BusinessFoundationController::class, 'preferences'])->whereUuid('business')->name('business-foundation.preferences');
    Route::put('/{business}/intent', [BusinessFoundationController::class, 'intent'])->whereUuid('business')->name('business-foundation.intent');
    Route::post('/{business}/data-use-bindings', [BusinessFoundationController::class, 'bindDataUse'])->whereUuid('business')->name('business-foundation.data-use-binding');
    Route::post('/{business}/data-use-licenses', [BusinessFoundationController::class, 'dataUseLicense'])->whereUuid('business')->name('business-foundation.data-use-license');
    Route::get('/{business}/data-use-journey', [BusinessFoundationController::class, 'dataLicenseJourney'])->whereUuid('business')->name('business-foundation.data-use-journey');
    Route::post('/{business}/operating-mandate', [BusinessFoundationController::class, 'operatingMandate'])->whereUuid('business')->name('business-foundation.operating-mandate');
    Route::post('/{business}/data-use-beneficiaries', [BusinessFoundationController::class, 'dataUseBeneficiary'])->whereUuid('business')->name('business-foundation.data-use-beneficiary');
    Route::post('/{business}/expenditure-summaries', [BusinessFoundationController::class, 'expenditureSummary'])->whereUuid('business')->name('business-foundation.expenditure-summary');
    Route::post('/{business}/materials/publish', [BusinessFoundationController::class, 'publish'])->whereUuid('business')->name('business-foundation.publish');
    Route::post('/{business}/materials/read', [BusinessFoundationController::class, 'material'])->whereUuid('business')->name('business-foundation.material');
    Route::post('/{business}/lifecycle', [BusinessFoundationController::class, 'lifecycle'])->whereUuid('business')->name('business-foundation.lifecycle');
    Route::post('/{business}/grants', [BusinessFoundationController::class, 'grant'])->whereUuid('business')->name('business-foundation.grant');
    Route::post('/{business}/internal-team', [BusinessFoundationController::class, 'internalTeam'])->whereUuid('business')->name('business-foundation.internal-team');
    Route::post('/{business}/direct-team', [BusinessFoundationController::class, 'enrollDirectTeam'])->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('business-foundation.direct-team');
    Route::post('/{business}/agency-grants', [BusinessFoundationController::class, 'agencyGrant'])->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('business-foundation.agency-grant');
    Route::get('/{business}/agency-options', [BusinessFoundationController::class, 'agencyOptions'])->whereUuid('business')->name('business-foundation.agency-options');
    Route::get('/{business}/team', [BusinessFoundationController::class, 'team'])->whereUuid('business')->name('business-foundation.team');
    Route::post('/{business}/team/recipient', [BusinessFoundationController::class, 'teamRecipient'])->middleware('throttle:30,1')->whereUuid('business')->name('business-foundation.team-recipient');
    Route::post('/{business}/team', [BusinessFoundationController::class, 'changeTeam'])->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('business-foundation.team-change');
    Route::get('/{business}/agency-mandates', [BusinessFoundationController::class, 'agencyMandates'])->whereUuid('business')->name('business-foundation.agency-mandates');
    Route::post('/{business}/agency-mandates', [BusinessFoundationController::class, 'withdrawAgencyMandates'])->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('business-foundation.agency-withdraw');
    Route::post('/{business}/purpose-enrollment', [BusinessFoundationController::class, 'enrollPurposeStatement'])->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('business-foundation.purpose-enrollment');
    Route::get('/{business}/internal-team', [BusinessFoundationController::class, 'internalTeamSurface'])->whereUuid('business')->name('business-foundation.internal-team-surface');
});
Route::post('/api/data-rights/commands', [BusinessFoundationController::class, 'resourceRight'])
    ->middleware(['auth', EnsureCurrentPrincipal::class])->name('business-foundation.resource-right');
Route::post('/api/market-evidence/{evidence}', [BusinessFoundationController::class, 'marketEvidence'])
    ->whereUuid('evidence')->middleware(['auth', EnsureCurrentPrincipal::class])->name('market-evidence.write');
Route::middleware(['auth', EnsureCurrentPrincipal::class])->group(function () {
    Route::get('/api/data-rights/boundaries', [BusinessFoundationController::class, 'boundaries'])->name('business-foundation.boundaries');
    Route::get('/api/data-rights/use-scopes', [BusinessFoundationController::class, 'dataUseChoices'])->name('business-foundation.data-use-choices');
    Route::get('/api/source-rights', [BusinessFoundationController::class, 'sources'])->name('business-foundation.sources');
    Route::get('/api/source-rights/{source}', [BusinessFoundationController::class, 'source'])->whereUuid('source')->name('business-foundation.source');
    Route::get('/source-rights', [BusinessSurfaceController::class, 'sources'])->name('business-foundation.sources-page');
    Route::get('/source-rights/{source}', [BusinessSurfaceController::class, 'source'])->whereUuid('source')->name('business-foundation.source-page');
    Route::get('/api/data-rights/boundaries/{boundary}', [BusinessFoundationController::class, 'boundary'])->whereUuid('boundary')->name('business-foundation.boundary');
    Route::get('/data-rights', [BusinessSurfaceController::class, 'boundaries'])->name('business-foundation.boundaries-page');
    Route::get('/data-rights/{boundary}', [BusinessSurfaceController::class, 'boundary'])->whereUuid('boundary')->name('business-foundation.boundary-page');
});

Route::middleware(['auth', EnsureCurrentPrincipal::class])->prefix('businesses')->group(function () {
    Route::get('/', [BusinessSurfaceController::class, 'index'])->name('business-foundation.index-page');
    Route::get('/new', [BusinessSurfaceController::class, 'create'])->name('business-foundation.create-page');
    Route::get('/{business}', [BusinessSurfaceController::class, 'show'])->whereUuid('business')->name('business-foundation.detail-page');
});
