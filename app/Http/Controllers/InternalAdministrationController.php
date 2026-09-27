<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\SecurityMail;
use App\Security\GrantAuthority;
use App\Security\StaffAuthority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class InternalAdministrationController extends Controller
{
    public function index(Request $request, GrantAuthority $grants): Response
    {
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

        return Inertia::render('Internal/Administration', [
            'people' => $people,
            'staffGrants' => $staffGrants,
            'grantableActions' => DB::table('internal_personnel')
                ->where('user_id', $request->user()->id)
                ->where('kind', 'founder')->where('status', 'active')->exists()
                ? GrantAuthority::STAFF_ACTIONS
                : array_values(array_diff(GrantAuthority::STAFF_ACTIONS, ['grant.manage', 'service.manage'])),
            'canAttestFactors' => DB::table('internal_personnel')
                ->where('user_id', $request->user()->id)
                ->where('kind', 'founder')->where('status', 'active')->exists(),
            'permissions' => collect(['staff.invite', 'staff.attest', 'staff.activate', 'staff.revoke', 'grant.manage', 'service.manage'])
                ->mapWithKeys(fn (string $action) => [$action => $grants->has(
                    $request->user(), (int) $request->session()->get('a001_generation', 0), $action,
                )])->all(),
        ]);
    }

    public function invite(Request $request, StaffAuthority $staff): RedirectResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
        ]);
        $user = DB::connection('pgsql_authority')->transaction(
            fn () => $staff->inviteEmployee($request, $input['name'], $input['email']),
        );
        $this->queueInvitationAfterCommit($user);

        return back()->with('status', 'أُنشئ حساب الموظف وطُلب إرسال رابط التهيئة.');
    }

    public function resendInvitation(Request $request, string $staff): RedirectResponse
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

        return back()->with('status', 'طُلب إعادة إرسال رابط التهيئة للموظف.');
    }

    public function attest(Request $request, string $staff, StaffAuthority $authority): RedirectResponse
    {
        DB::connection('pgsql_authority')->transaction(
            fn () => $authority->attest($request, $this->staff($staff)),
        );

        return back();
    }

    public function activate(Request $request, string $staff, StaffAuthority $authority): RedirectResponse
    {
        DB::connection('pgsql_authority')->transaction(
            fn () => $authority->activate($request, $this->staff($staff)),
        );

        return back();
    }

    public function revoke(Request $request, string $staff, StaffAuthority $authority): RedirectResponse
    {
        DB::connection('pgsql_authority')->transaction(
            fn () => $authority->revoke($request, $this->staff($staff)),
        );

        return back();
    }

    public function attestFactors(Request $request, string $staff, StaffAuthority $authority): RedirectResponse
    {
        DB::connection('pgsql_authority')->transaction(
            fn () => $authority->attestIndependentFactors($request, $this->staff($staff)),
        );

        return back();
    }

    public function grant(Request $request, GrantAuthority $grants): RedirectResponse
    {
        $input = $request->validate([
            'staff' => ['required', 'uuid', Rule::exists('users', 'public_id')],
            'action' => ['required', Rule::in(GrantAuthority::STAFF_ACTIONS)],
            'reason' => ['required', 'string', 'min:10', 'max:128'],
        ]);
        DB::connection('pgsql_authority')->transaction(fn () => $grants->issueStaffGrant(
            $request, $this->staff($input['staff']), $input['action'], $input['reason'],
        ));

        return back();
    }

    public function revokeGrant(Request $request, int $grant, GrantAuthority $grants): RedirectResponse
    {
        DB::connection('pgsql_authority')->transaction(fn () => $grants->revokeStaffGrant($request, $grant));

        return back();
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
