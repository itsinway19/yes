<?php

use App\Appearance\GlobalAppearance;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\IdentityContextController;
use App\Http\Controllers\Auth\InternalAuthenticatedSessionController;
use App\Http\Controllers\Auth\PasskeySessionController;
use App\Http\Controllers\BusinessFindingController;
use App\Http\Controllers\BusinessFoundationController;
use App\Http\Controllers\BusinessSurfaceController;
use App\Http\Controllers\CommercialController;
use App\Http\Controllers\CommercialMarketPreparationController;
use App\Http\Controllers\GlobalAppearanceController;
use App\Http\Controllers\GoogleConnectionController;
use App\Http\Controllers\GoogleReportController;
use App\Http\Controllers\InquiryEvidenceController;
use App\Http\Controllers\InternalAdministrationController;
use App\Http\Controllers\InternalBusinessTypeController;
use App\Http\Controllers\InternalCustomerAdministrationController;
use App\Http\Controllers\InternalServiceController;
use App\Http\Controllers\KnowledgeConsumerController;
use App\Http\Controllers\LegacyPurposeHistoryController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MarketKnowledgeController;
use App\Http\Controllers\MarketOperationsController;
use App\Http\Controllers\MarketProcessingController;
use App\Http\Controllers\MetaConnectionController;
use App\Http\Controllers\MetaReportController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationTeamController;
use App\Http\Controllers\PasskeyManagementController;
use App\Http\Controllers\PlatformMaintenanceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RetiredWorkspaceCommercialController;
use App\Http\Controllers\SecurityDevicesController;
use App\Http\Controllers\SnapchatConnectionController;
use App\Http\Controllers\SnapchatReportController;
use App\Http\Controllers\StaffOnboardingController;
use App\Http\Controllers\TikTokConnectionController;
use App\Http\Controllers\TikTokReportController;
use App\Http\Controllers\WorkspaceCommercialHistoryController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceFoundationController;
use App\Http\Controllers\WorkspaceServiceController;
use App\Http\Controllers\WorkspaceSourceController;
use App\Http\Controllers\XConnectionController;
use App\Http\Controllers\XReportController;
use App\Http\Middleware\EnsureCurrentPrincipal;
use App\Http\Middleware\EnsureFreshAuthentication;
use App\Http\Middleware\EnsurePendingStaffEnrollment;
use App\Http\Middleware\RequireActionGrant;
use App\Security\IdentityDocument;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->view('welcome', ['locale' => app()->getLocale(),
        'appearanceTokens' => GlobalAppearance::tokens(app(GlobalAppearance::class)->current()),
        'maintenance' => request()->attributes->get('platform.maintenance'),
        'publicIdentity' => app(IdentityDocument::class)->metadata(request())])->header('Cache-Control', 'no-store');
});

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
    Route::get('/workspaces/{workspace}/commercial-history', WorkspaceCommercialHistoryController::class)->whereUuid('workspace')->name('workspaces.commercial-history');
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
    Route::get('/workspaces/{workspace}/goals', RetiredWorkspaceCommercialController::class)
        ->whereUuid('workspace')->name('workspaces.goals.edit');
    Route::get('/workspaces/{workspace}/goals/review', RetiredWorkspaceCommercialController::class)
        ->whereUuid('workspace')->name('workspaces.goals.review');
    Route::get('/workspaces/{workspace}/goals/context', RetiredWorkspaceCommercialController::class)
        ->whereUuid('workspace')->name('workspaces.goals.context');
    Route::post('/workspaces/{workspace}/goals', RetiredWorkspaceCommercialController::class)
        ->whereUuid('workspace')->name('workspaces.goals.update');
    Route::get('/workspaces/{workspace}/object-purposes', RetiredWorkspaceCommercialController::class)
        ->whereUuid('workspace')->name('workspaces.object-purposes.index');
    Route::get('/workspaces/{workspace}/object-purposes/context', RetiredWorkspaceCommercialController::class)
        ->whereUuid('workspace')->name('workspaces.object-purposes.application.index');
    Route::get('/workspaces/{workspace}/object-purposes/{purpose}/context', RetiredWorkspaceCommercialController::class)
        ->whereUuid('workspace')->whereUuid('purpose')->name('workspaces.object-purposes.application.show');
    Route::get('/workspaces/{workspace}/object-purposes/{purpose}', RetiredWorkspaceCommercialController::class)
        ->whereUuid('workspace')->whereUuid('purpose')->name('workspaces.object-purposes.show');
    Route::post('/workspaces/{workspace}/object-purposes', RetiredWorkspaceCommercialController::class)
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->name('workspaces.object-purposes.store');
    Route::post('/workspaces/{workspace}/object-purposes/{purpose}/revisions', RetiredWorkspaceCommercialController::class)
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->whereUuid('purpose')->name('workspaces.object-purposes.revise');
    Route::get('/workspaces/{workspace}/purpose-history', [LegacyPurposeHistoryController::class, 'index'])
        ->whereUuid('workspace')->name('workspaces.purpose-history.index');
    Route::get('/workspaces/{workspace}/purpose-history/context', [LegacyPurposeHistoryController::class, 'applicationIndex'])
        ->whereUuid('workspace')->name('workspaces.purpose-history.application.index');
    Route::get('/workspaces/{workspace}/purpose-history/{purpose}/context', [LegacyPurposeHistoryController::class, 'applicationShow'])
        ->whereUuid('workspace')->whereUuid('purpose')->name('workspaces.purpose-history.application.show');
    Route::get('/workspaces/{workspace}/purpose-history/{purpose}', [LegacyPurposeHistoryController::class, 'show'])
        ->whereUuid('workspace')->whereUuid('purpose')->name('workspaces.purpose-history.show');
    Route::post('/workspaces/{workspace}/purpose-history/{purpose}/revisions', [LegacyPurposeHistoryController::class, 'revise'])
        ->middleware(EnsureFreshAuthentication::class)->whereUuid('workspace')->whereUuid('purpose')->name('workspaces.purpose-history.revise');
    Route::get('/organizations/new', [OrganizationController::class, 'create'])->name('organizations.create');
    Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::get('/api/organizations/{organization}/team', [OrganizationTeamController::class, 'index'])->whereUuid('organization')->name('organization-team.index');
    Route::post('/api/organizations/{organization}/team', [OrganizationTeamController::class, 'store'])->whereUuid('organization')->name('organization-team.store');
    Route::redirect('/business/start', '/organizations/new')->name('business.start');
    Route::post('/business/start', fn () => response()->json(['message' => 'This workspace business-creation workflow is retired. Register an organization or create an independent Business.'], 410))->name('business.start.store');
    Route::get('/workspaces/{workspace}/business', RetiredWorkspaceCommercialController::class)
        ->whereUuid('workspace')->name('workspaces.business.edit');
    Route::post('/workspaces/{workspace}/business', RetiredWorkspaceCommercialController::class)
        ->whereUuid('workspace')->name('workspaces.business.update');
    Route::post('/workspaces/{workspace}/business/target-markets', RetiredWorkspaceCommercialController::class)
        ->whereUuid('workspace')->name('workspaces.business.target-markets.update');
    Route::post('/workspaces/{workspace}/business/current-intent', RetiredWorkspaceCommercialController::class)
        ->whereUuid('workspace')->name('workspaces.business.current-intent.update');
    Route::post('/workspaces/{workspace}/business/authority', RetiredWorkspaceCommercialController::class)
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
    // Business-native custody uses the same qualified provider controllers.
    Route::post('/businesses/{business}/source-audit/{obligation}/recover', [WorkspaceSourceController::class, 'recoverBusiness'])
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
    Route::get('/providers/snapchat/callback', [SnapchatConnectionController::class, 'callback'])->name('providers.snapchat.callback');
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
    Route::get('/providers/google/callback', [GoogleConnectionController::class, 'callback'])->name('providers.google.callback');
    Route::get('/providers/tiktok/callback', [TikTokConnectionController::class, 'callback'])->name('providers.tiktok.callback');
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
    Route::get('/providers/meta/callback', [MetaConnectionController::class, 'callback'])->name('providers.meta.callback');
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

Route::middleware(['auth', EnsureCurrentPrincipal::class])->prefix('api/businesses')->group(function () {
    Route::get('/', [BusinessFoundationController::class, 'choices'])->name('business-foundation.choices');
    Route::get('/catalog', [BusinessFoundationController::class, 'catalog'])->name('business-foundation.catalog');
    Route::get('/{business}/surface', [BusinessFoundationController::class, 'surface'])->whereUuid('business')->name('business-foundation.surface');
    Route::get('/{business}/sources', [BusinessFoundationController::class, 'businessSources'])->whereUuid('business')->name('business-foundation.business-sources');
    Route::get('/{business}/purposes', [BusinessFoundationController::class, 'purposes'])->whereUuid('business')->name('business-foundation.purposes');
    Route::post('/{business}/purposes', [BusinessFoundationController::class, 'writePurpose'])->whereUuid('business')->name('business-foundation.write-purpose');
    Route::get('/{business}/history', [BusinessFoundationController::class, 'history'])->whereUuid('business')->name('business-foundation.history');
    Route::get('/{business}/legacy-history', [BusinessFoundationController::class, 'legacyHistory'])->whereUuid('business')->name('business-foundation.legacy-history');
    Route::post('/', [BusinessFoundationController::class, 'store'])->name('business-foundation.store');
    Route::post('/{business}/read', [BusinessFoundationController::class, 'show'])->whereUuid('business')->name('business-foundation.show');
    Route::put('/{business}/context', [BusinessFoundationController::class, 'context'])->whereUuid('business')->name('business-foundation.context');
    Route::put('/{business}/markets', [BusinessFoundationController::class, 'markets'])->whereUuid('business')->name('business-foundation.markets');
    Route::put('/{business}/preferences', [BusinessFoundationController::class, 'preferences'])->whereUuid('business')->name('business-foundation.preferences');
    Route::put('/{business}/intent', [BusinessFoundationController::class, 'intent'])->whereUuid('business')->name('business-foundation.intent');
    Route::post('/{business}/data-use-bindings', [BusinessFoundationController::class, 'bindDataUse'])->whereUuid('business')->name('business-foundation.data-use-binding');
    Route::post('/{business}/materials/publish', [BusinessFoundationController::class, 'publish'])->whereUuid('business')->name('business-foundation.publish');
    Route::post('/{business}/materials/read', [BusinessFoundationController::class, 'material'])->whereUuid('business')->name('business-foundation.material');
    Route::post('/{business}/lifecycle', [BusinessFoundationController::class, 'lifecycle'])->whereUuid('business')->name('business-foundation.lifecycle');
    Route::post('/{business}/grants', [BusinessFoundationController::class, 'grant'])->whereUuid('business')->name('business-foundation.grant');
    Route::post('/{business}/internal-team', [BusinessFoundationController::class, 'internalTeam'])->whereUuid('business')->name('business-foundation.internal-team');
    Route::post('/{business}/direct-team', [BusinessFoundationController::class, 'enrollDirectTeam'])->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('business-foundation.direct-team');
    Route::post('/{business}/purpose-enrollment', [BusinessFoundationController::class, 'enrollPurposeStatement'])->middleware(EnsureFreshAuthentication::class)->whereUuid('business')->name('business-foundation.purpose-enrollment');
    Route::get('/{business}/internal-team', [BusinessFoundationController::class, 'internalTeamSurface'])->whereUuid('business')->name('business-foundation.internal-team-surface');
    Route::post('/{business}/workspace-links', [BusinessFoundationController::class, 'link'])->whereUuid('business')->name('business-foundation.link');
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
