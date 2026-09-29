<?php

namespace App\Commercial;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Context owner: initial binding and assignment are one atomic formation command. */
final class RegisterCommercialScope
{
    public function __construct(private readonly CommercialContext $context) {}

    public function register(Request $request, array $input): array
    {
        return $this->context->run($request, static fn () => json_decode(DB::scalar(
            'SELECT builderad_register_commercial_scope(?,?,?,?,?,?,?,?)', [
                $input['command_id'], $input['scope_id'], $input['workspace_public_id'],
                $input['market_id'], $input['configuration_id'], $input['catalog_reference_id'],
                $input['denomination_code'], $input['reason_code'],
            ],
        ), true, flags: JSON_THROW_ON_ERROR), true);
    }

    public function receipt(Request $request, string $command): ?object
    {
        return $this->context->run($request, static fn () => DB::table('commercial_command_results')->where('command_id', $command)->first());
    }

    public function workspaceReference(Request $request, string $scope, string $workspace): ?object
    {
        return $this->context->run($request, static fn () => DB::selectOne('SELECT * FROM builderad_commercial_workspace_reference(?,?)', [$scope, $workspace]));
    }

    public function internalProjection(Request $request, string $scope): ?object
    {
        return $this->context->run($request, static fn () => DB::table('commercial_market_assignments as a')
            ->join('commercial_markets as m', 'm.id', '=', 'a.market_id')
            ->join('commercial_catalog_references as c', 'c.id', '=', 'a.catalog_reference_id')
            ->where('a.scope_id', $scope)->first(['a.scope_id', 'a.market_id', 'm.label as market_label',
                'a.configuration_id', 'c.catalog_identity', 'c.catalog_version', 'a.denomination_code',
                'a.decided_at', 'a.effective_at', 'a.recorded_at',
                DB::raw('builderad_workspace_public_by_id(a.workspace_id) AS workspace_public_id')]));
    }

    /** Call only within the already-selected Foundation Workspace transaction. */
    public function advertiserProjection(int $workspace): array
    {
        $record = DB::table('commercial_market_assignments as a')
            ->join('commercial_markets as m', 'm.id', '=', 'a.market_id')
            ->join('commercial_catalog_references as c', 'c.id', '=', 'a.catalog_reference_id')
            ->where('a.workspace_id', $workspace)->first([
                'a.scope_id', 'a.market_id', 'm.label as market_label', 'a.configuration_id',
                'c.catalog_identity', 'c.catalog_version', 'a.denomination_code', 'a.effective_at',
            ]);

        return ['state' => $record ? 'CONTEXT_RECORDED' : 'UNAVAILABLE', 'context' => $record,
            'agreement_state' => 'UNKNOWN', 'amount_state' => 'UNKNOWN'];
    }
}
