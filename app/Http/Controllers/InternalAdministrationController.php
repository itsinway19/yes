<?php

namespace App\Http\Controllers;

use App\Http\Responses\SpaPage;
use App\Models\User;
use App\Notifications\SecurityMail;
use App\Providers\Ads\Google\GoogleAccessAdapter;
use App\Providers\Ads\Meta\MetaConnectionNotice;
use App\Providers\Ads\Meta\MetaLoginSettings;
use App\Providers\Ads\Snapchat\SnapchatOAuthClient;
use App\Providers\Ads\TikTok\TikTokAccessAdapter;
use App\Providers\Ads\X\XOAuthClient;
use App\Security\GrantAuthority;
use App\Security\StaffAuthority;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;

final class InternalAdministrationController extends Controller
{
    public function index(Request $request, GrantAuthority $grants): SpaPage
    {
        if (! SpaPage::needsData($request)) {
            return new SpaPage([]);
        }

        $canManageServices = $grants->has($request->user(), (int) $request->session()->get('a001_generation', 0), 'service.manage');
        $people = DB::table('internal_personnel as personnel')
            ->join('users', 'users.id', '=', 'personnel.user_id')
            ->orderBy('users.name')
            ->get(['users.public_id', 'users.name', 'users.email', 'users.email_verified_at',
                'personnel.kind', 'personnel.status', 'personnel.attested_at',
                'personnel.factor_independence_attested_at',
                DB::raw('(SELECT count(*) FROM passkeys WHERE passkeys.user_id = users.id) AS passkey_count')]);
        $staffGrants = DB::table('security_action_grants as grants')
            ->join('users', 'users.id', '=', 'grants.subject_user_id')
            ->where('grants.source', 'human')->where('grants.status', 'active')
            ->orderBy('users.name')->orderBy('grants.action')
            ->get(['grants.id', 'grants.action', 'grants.reason', 'users.public_id', 'users.name']);

        return new SpaPage([
            'canManageCommercialPreparation' => $grants->canManageCommercialPreparation($request),
            'metaOperatorStatus' => $canManageServices ? $this->metaOperatorStatus() : null,
            'googleOperatorStatus' => $canManageServices ? $this->googleOperatorStatus() : null,
            'tiktokOperatorStatus' => $canManageServices ? $this->tiktokOperatorStatus() : null,
            'snapchatOperatorStatus' => $canManageServices ? $this->snapchatOperatorStatus() : null,
            'xOperatorStatus' => $canManageServices ? $this->xOperatorStatus() : null,
            'people' => $people,
            'staffGrants' => $staffGrants,
            'grantableActions' => DB::table('internal_personnel')
                ->where('user_id', $request->user()->id)
                ->where('kind', 'founder')->where('status', 'active')->exists()
                ? GrantAuthority::STAFF_ACTIONS
                : array_values(array_diff(GrantAuthority::STAFF_ACTIONS, ['grant.manage', 'service.manage', 'platform.maintenance.manage'])),
            'canAttestFactors' => DB::table('internal_personnel')
                ->where('user_id', $request->user()->id)
                ->where('kind', 'founder')->where('status', 'active')->exists(),
            'permissions' => collect(['staff.invite', 'staff.attest', 'staff.activate', 'staff.revoke', 'grant.manage', 'service.manage'])
                ->mapWithKeys(fn (string $action) => [$action => $grants->has(
                    $request->user(), (int) $request->session()->get('a001_generation', 0), $action,
                )])->all(),
        ]);
    }

    /** Installation checks only; no provider approval, policy acceptance or Workspace access is inferred. */
    private function metaOperatorStatus(): array
    {
        $applicationConfigured = false;
        $noticeLinksConfigured = false;

        try {
            $settings = MetaLoginSettings::configured();
            $applicationConfigured = $settings->redirectUri === rtrim((string) config('app.url'), '/').route('providers.meta.callback', absolute: false);
        } catch (LogicException) {
            // Missing or invalid installation settings remain unavailable.
        }

        try {
            MetaConnectionNotice::configured();
            $noticeLinksConfigured = true;
        } catch (LogicException) {
            // URL syntax is only an installation check, never policy approval.
        }

        return ['application_configured' => $applicationConfigured, 'notice_links_configured' => $noticeLinksConfigured];
    }

    /** Local syntax only; no HTTP, credential projection or approval inference. */
    private function googleOperatorStatus(): array
    {
        $applicationConfigured = false;
        try {
            $redirect = (string) config('services.google_ads.redirect_uri');
            new GoogleAccessAdapter(new Factory, (string) config('services.google_ads.version'),
                (string) config('services.google_ads.client_id'), (string) config('services.google_ads.client_secret'),
                (string) config('services.google_ads.developer_token'), $redirect,
                static fn () => throw new LogicException('Operator status must never send provider HTTP.'));
            $applicationConfigured = $redirect === rtrim((string) config('app.url'), '/').route('providers.google.callback', absolute: false);
        } catch (InvalidArgumentException|LogicException) {
            // An incomplete installation remains unavailable; never expose supplied credentials.
        }
        $privacy = (string) config('services.google_ads.privacy_url');
        $noticeConfigured = filter_var($privacy, FILTER_VALIDATE_URL) !== false
            && parse_url($privacy, PHP_URL_SCHEME) === 'https'
            && parse_url($privacy, PHP_URL_USER) === null && parse_url($privacy, PHP_URL_PASS) === null
            && preg_match('/^[a-zA-Z0-9_.:-]{1,80}$/D', (string) config('services.google_ads.notice_revision')) === 1;

        return ['application_configured' => $applicationConfigured, 'notice_links_configured' => $noticeConfigured];
    }

    /** Local syntax only; no HTTP, credential projection or approval inference. */
    private function tiktokOperatorStatus(): array
    {
        $applicationConfigured = false;
        try {
            $redirect = (string) config('services.tiktok_ads.redirect_uri');
            new TikTokAccessAdapter(new Factory, (string) config('services.tiktok_ads.version'),
                (string) config('services.tiktok_ads.app_id'), (string) config('services.tiktok_ads.app_secret'),
                $redirect, (string) config('services.tiktok_ads.authorization_url'),
                static fn () => throw new LogicException('Operator status must never send provider HTTP.'));
            $applicationConfigured = $redirect === rtrim((string) config('app.url'), '/').route('providers.tiktok.callback', absolute: false);
        } catch (InvalidArgumentException|LogicException) {
            // An incomplete installation remains unavailable; never expose supplied credentials.
        }
        $privacy = (string) config('services.tiktok_ads.privacy_url');
        $noticeConfigured = filter_var($privacy, FILTER_VALIDATE_URL) !== false
            && parse_url($privacy, PHP_URL_SCHEME) === 'https'
            && parse_url($privacy, PHP_URL_USER) === null && parse_url($privacy, PHP_URL_PASS) === null
            && preg_match('/^[a-zA-Z0-9_.:-]{1,80}$/D', (string) config('services.tiktok_ads.notice_revision')) === 1;

        return ['application_configured' => $applicationConfigured, 'notice_links_configured' => $noticeConfigured];
    }

    /** Local configuration syntax only. Provider approval and policy approval remain separate. */
    private function snapchatOperatorStatus(): array
    {
        $applicationConfigured = false;
        try {
            $redirect = (string) config('services.snapchat_ads.redirect_uri');
            new SnapchatOAuthClient(new Factory,
                (string) config('services.snapchat_ads.client_id'), (string) config('services.snapchat_ads.client_secret'), $redirect,
                static fn () => throw new LogicException('Operator status must never send provider HTTP.'));
            $applicationConfigured = $redirect === rtrim((string) config('app.url'), '/').route('providers.snapchat.callback', absolute: false);
        } catch (InvalidArgumentException|LogicException) {
            // Missing settings are a local unavailable state, never proof of platform approval.
        }
        $privacy = (string) config('services.snapchat_ads.privacy_url');
        $noticeConfigured = filter_var($privacy, FILTER_VALIDATE_URL) !== false
            && parse_url($privacy, PHP_URL_SCHEME) === 'https'
            && parse_url($privacy, PHP_URL_USER) === null && parse_url($privacy, PHP_URL_PASS) === null
            && preg_match('/^[a-zA-Z0-9_.:-]{1,80}$/D', (string) config('services.snapchat_ads.notice_revision')) === 1;

        return ['application_configured' => $applicationConfigured, 'notice_links_configured' => $noticeConfigured];
    }

    private function xOperatorStatus(): array
    {
        $applicationConfigured = false;
        try {
            $redirect = (string) config('services.x_ads.redirect_uri');
            new XOAuthClient(new Factory,
                (string) config('services.x_ads.consumer_key'), (string) config('services.x_ads.consumer_secret'), $redirect,
                static fn () => throw new LogicException('Operator status must never send provider HTTP.'));
            $applicationConfigured = $redirect === rtrim((string) config('app.url'), '/').route('providers.x.callback', absolute: false);
        } catch (InvalidArgumentException|LogicException) {
            // Missing settings are a local unavailable state, never proof of platform approval.
        }
        $privacy = (string) config('services.x_ads.privacy_url');
        $noticeConfigured = filter_var($privacy, FILTER_VALIDATE_URL) !== false
            && parse_url($privacy, PHP_URL_SCHEME) === 'https'
            && parse_url($privacy, PHP_URL_USER) === null && parse_url($privacy, PHP_URL_PASS) === null
            && preg_match('/^[a-zA-Z0-9_.:-]{1,80}$/D', (string) config('services.x_ads.notice_revision')) === 1;

        return ['application_configured' => $applicationConfigured, 'notice_links_configured' => $noticeConfigured];
    }

    public function invite(Request $request, StaffAuthority $staff): RedirectResponse|JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
        ]);
        $user = DB::connection('pgsql_authority')->transaction(
            fn () => $staff->inviteEmployee($request, $input['name'], $input['email']),
        );
        $this->queueInvitationAfterCommit($user);

        return $this->acknowledge($request, 'أُنشئ حساب الموظف وطُلب إرسال رابط التهيئة.');
    }

    public function resendInvitation(Request $request, string $staff): RedirectResponse|JsonResponse
    {
        $user = $this->staff($staff);
        $pending = DB::table('internal_personnel')->where('user_id', $user->id)
            ->where('kind', 'employee')->where('status', 'pending')->exists() &&
            DB::table('principal_lifecycles')->where('user_id', $user->id)
                ->where('principal_class', 'staff')->where('status', 'pending')->exists();
        if (! $pending || $user->id === $request->user()->id) {
            abort(403);
        }
        $this->queueInvitationAfterCommit($user);

        return $this->acknowledge($request, 'طُلب إعادة إرسال رابط التهيئة للموظف.');
    }

    public function attest(Request $request, string $staff, StaffAuthority $authority): RedirectResponse|JsonResponse
    {
        DB::connection('pgsql_authority')->transaction(
            fn () => $authority->attest($request, $this->staff($staff)),
        );

        return $this->acknowledge($request);
    }

    public function activate(Request $request, string $staff, StaffAuthority $authority): RedirectResponse|JsonResponse
    {
        DB::connection('pgsql_authority')->transaction(
            fn () => $authority->activate($request, $this->staff($staff)),
        );

        return $this->acknowledge($request);
    }

    public function revoke(Request $request, string $staff, StaffAuthority $authority): RedirectResponse|JsonResponse
    {
        DB::connection('pgsql_authority')->transaction(
            fn () => $authority->revoke($request, $this->staff($staff)),
        );

        return $this->acknowledge($request);
    }

    public function attestFactors(Request $request, string $staff, StaffAuthority $authority): RedirectResponse|JsonResponse
    {
        DB::connection('pgsql_authority')->transaction(
            fn () => $authority->attestIndependentFactors($request, $this->staff($staff)),
        );

        return $this->acknowledge($request);
    }

    public function grant(Request $request, GrantAuthority $grants): RedirectResponse|JsonResponse
    {
        $input = $request->validate([
            'staff' => ['required', 'uuid', Rule::exists('users', 'public_id')],
            'action' => ['required', Rule::in(GrantAuthority::STAFF_ACTIONS)],
            'reason' => ['required', 'string', 'min:10', 'max:128'],
        ]);
        DB::connection('pgsql_authority')->transaction(fn () => $grants->issueStaffGrant(
            $request, $this->staff($input['staff']), $input['action'], $input['reason'],
        ));

        return $this->acknowledge($request);
    }

    public function revokeGrant(Request $request, int $grant, GrantAuthority $grants): RedirectResponse|JsonResponse
    {
        DB::connection('pgsql_authority')->transaction(fn () => $grants->revokeStaffGrant($request, $grant));

        return $this->acknowledge($request);
    }

    private function acknowledge(Request $request, ?string $status = null): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['saved' => true])->header('Cache-Control', 'no-store');
        }

        return $status === null ? back() : back()->with('status', $status);
    }

    private function staff(string $publicId): User
    {
        return User::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function queueInvitationAfterCommit(User $user): void
    {
        SecurityMail::afterCommit('staff_invitation',
            fn () => Password::sendResetLink(['email' => $user->email]));
    }
}
