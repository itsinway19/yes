<?php

namespace App\Http\Controllers;

use App\Foundation\Tenancy\WorkspaceContext;
use App\Http\Responses\SpaPage;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class WorkspaceCommercialHistoryController
{
    public function __invoke(Request $request, string $workspace, WorkspaceContext $context): SpaPage
    {
        $input = $request->validate(['kind' => ['sometimes', Rule::in(['context', 'markets', 'intent', 'preferences'])], 'after' => ['sometimes', 'integer', 'min:0']]);

        return $context->run($request->user(), $workspace, function (Workspace $organization) use ($request, $input): SpaPage {
            if (! SpaPage::needsData($request)) {
                return new SpaPage([]);
            }
            // Keep the original native read qualification. A drained/transitioned
            // history uses qualified Business legacy-history, never membership inference.
            $head = DB::table('workspaces')->leftJoin('legacy_workspace_commercial_names as preserved', 'preserved.workspace_id', '=', 'workspaces.id')
                ->where('workspaces.id', $organization->id)->select(['workspaces.business_type_id', 'workspaces.business_context_version', 'workspaces.current_intent'])
                ->selectRaw('coalesce(preserved.name,workspaces.name) as name')->first();
            abort_unless($head, 403);
            $kind = $input['kind'] ?? 'context';
            [$table, $fields] = match ($kind) {
                'context' => ['business_context_events', ['id', 'version', 'prior_name', 'new_name', 'prior_type_id', 'new_type_id', 'occurred_at']],
                'markets' => ['business_target_market_events', ['id', 'version', 'before_codes', 'after_codes', 'occurred_at']],
                'intent' => ['business_current_intent_events', ['id', 'before_version', 'after_version', 'before_value', 'after_value', 'occurred_at']],
                'preferences' => ['advertiser_goal_preference_events', ['id', 'version', 'before_value', 'after_value', 'occurred_at']],
            };
            $rows = DB::table($table)->where('workspace_id', $organization->id)->where('id', '>', $input['after'] ?? 0)->orderBy('id')->limit(51)->get($fields);
            $hasMore = $rows->count() > 50;
            $items = $rows->take(50)->map(function ($row): array {
                $item = (array) $row;
                foreach (['before_value', 'after_value'] as $field) {
                    if (isset($item[$field])) {
                        $item[$field] = json_decode($item[$field], true, flags: JSON_THROW_ON_ERROR);
                    }
                }

                return $item;
            })->values();

            return new SpaPage(['workspace' => $organization->only(['public_id', 'name']), 'kind' => $kind,
                'scope' => 'original_workspace_history_only', 'read_only' => true, 'current_business_inferred' => false,
                'head' => (array) $head, 'items' => $items->all(), 'next_after' => $hasMore ? (int) $items->last()['id'] : null]);
        }, $request);
    }
}
