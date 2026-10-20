<?php

namespace App\Http\Controllers;

use App\Appearance\GlobalAppearance;
use App\Http\Responses\SpaPage;
use App\Security\GrantAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class GlobalAppearanceController
{
    public function index(Request $request, GlobalAppearance $appearance): SpaPage
    {
        $this->requireFounder($request);

        return new SpaPage(SpaPage::needsData($request) ? $appearance->current() : []);
    }

    public function publish(Request $request)
    {
        $this->requireFounder($request);
        if (array_diff(array_keys($request->all()), ['primary', 'secondary', 'revision']) !== []) {
            throw ValidationException::withMessages(['appearance' => 'Only primary, secondary and revision are supported.']);
        }
        $input = $request->validate([
            'primary' => ['required', 'string', 'size:7', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'secondary' => ['required', 'string', 'size:7', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'revision' => ['required', 'integer', 'between:0,2147483646'],
        ]);
        $db = DB::connection('pgsql_authority');
        $published = $db->transaction(function () use ($request, $input, $db) {
            // Same current Founder/grant owner as central administration; no new grant framework.
            $this->requireFounder($request, true);
            $db->table('global_appearance')->insertOrIgnore(['id' => 1, 'revision' => 0,
                'primary_color' => GlobalAppearance::DEFAULTS['primary'], 'secondary_color' => GlobalAppearance::DEFAULTS['secondary']]);
            $prior = $db->table('global_appearance')->where('id', 1)->lockForUpdate()->sole();
            if ((int) $prior->revision !== (int) $input['revision']) {
                throw new ConflictHttpException('Appearance changed. Review the published values before publishing again.');
            }
            $revision = (int) $prior->revision + 1;
            $db->table('global_appearance')->where('id', 1)->update([
                'primary_color' => strtolower($input['primary']), 'secondary_color' => strtolower($input['secondary']),
                'revision' => $revision, 'published_by' => $request->user()->id, 'published_at' => now(),
            ]);
            $generation = (int) $request->session()->get('a001_generation');
            $db->table('security_audit_events')->insert([
                'actor_user_id' => $request->user()->id, 'subject_user_id' => $request->user()->id,
                'event' => 'global_appearance_published', 'reason' => 'appearance_revision:'.$revision,
                'prior_status' => 'current', 'new_status' => 'current', 'prior_generation' => $generation,
                'new_generation' => $generation, 'occurred_at' => now(),
            ]);

            return ['revision' => $revision, 'primary' => strtolower($input['primary']),
                'secondary' => strtolower($input['secondary']), 'defaults' => GlobalAppearance::DEFAULTS];
        });

        return response()->json(['saved' => true, 'appearance' => $published])->header('Cache-Control', 'no-store');
    }

    private function requireFounder(Request $request, bool $lock = false): void
    {
        abort_unless($request->is('internal/*') && $request->user()
            && app(GrantAuthority::class)->has($request->user(), (int) $request->session()->get('a001_generation', 0), 'grant.manage'), 403);
        $query = DB::connection('pgsql_authority')->table('internal_personnel')
            ->where('user_id', $request->user()->id)->where('kind', 'founder')->where('status', 'active');
        if ($lock) {
            $query->sharedLock();
        }
        abort_unless($query->first(), 403);
    }
}
