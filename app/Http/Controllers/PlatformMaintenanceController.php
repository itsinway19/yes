<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureFreshAuthentication;
use App\Http\Responses\SpaPage;
use App\Platform\PlatformMaintenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlatformMaintenanceController
{
    public function index(Request $request, PlatformMaintenance $maintenance): SpaPage
    {
        abort_unless($maintenance->allowed($request), 403);

        return new SpaPage(SpaPage::needsData($request) ? [
            'maintenance' => $maintenance->current(),
            'history' => DB::connection('pgsql_authority')->table('platform_maintenance_commands')->orderByDesc('resulting_revision')->limit(20)
                ->get(['command_id', 'actor_user_id', 'previous_revision', 'resulting_revision', 'reason', 'occurred_at'])->all(),
        ] : []);
    }

    public function update(Request $request, PlatformMaintenance $maintenance, EnsureFreshAuthentication $fresh)
    {
        abort_unless($maintenance->allowed($request), 403);

        return $fresh->handle($request, function () use ($request, $maintenance) {
            if (array_diff(array_keys($request->all()), ['command_id', 'revision', 'active', 'message_ar', 'message_en', 'retry_after', 'reason']) !== []) {
                throw ValidationException::withMessages(['maintenance' => 'Unsupported fields.']);
            }
            $input = $request->validate([
                'command_id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'between:0,2147483646'],
                'active' => ['required', 'boolean:strict'], 'message_ar' => ['required', 'string', 'max:500'],
                'message_en' => ['required', 'string', 'max:500'], 'reason' => ['required', 'string', 'max:500'],
                'retry_after' => ['required', 'integer', 'between:30,3600'],
            ]);
            $input['revision'] = (int) $input['revision'];
            $input['retry_after'] = (int) $input['retry_after'];

            return response()->json($maintenance->change($request, $input))->header('Cache-Control', 'no-store');
        });
    }
}
