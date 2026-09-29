<?php

namespace App\Commercial;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Registry/Configuration owner; Catalog is materialized by its own command and transaction. */
final class MarketRegistry
{
    public function __construct(private readonly CommercialContext $context) {}

    public function inspect(Request $request): array
    {
        return $this->context->run($request, fn () => [
            'markets' => DB::table('commercial_markets as m')
                ->join('commercial_market_assignability as a', 'a.market_id', '=', 'm.id')
                ->orderBy('m.label')->get(['m.id', 'm.label', 'a.configuration_id', 'a.revision'])->all(),
            'configurations' => DB::table('commercial_market_configuration_versions')->orderBy('version')->get()
                ->map(CommercialMarketConfigurationVersion::fromRecord(...))->all(),
            'catalogReferences' => DB::table('commercial_catalog_references')->orderBy('recorded_at')
                ->get(['id', 'market_id', 'catalog_identity', 'catalog_version', 'approval_reference', 'source_sha256', 'recorded_at'])->all(),
        ]);
    }

    public function configure(Request $request, array $input): array
    {
        return $this->execute($request, 'builderad_configure_commercial_market', [
            $input['command_id'], $input['market_id'], $input['label'], $input['reason_code'],
        ]);
    }

    public function configuration(Request $request, array $input): array
    {
        return $this->execute($request, 'builderad_create_market_configuration', [
            $input['command_id'], $input['market_id'], $input['configuration_id'],
            $input['catalog_reference_id'] ?? null, $input['denomination_code'] ?? null, $input['reason_code'],
        ]);
    }

    public function assignability(Request $request, array $input): array
    {
        return $this->execute($request, 'builderad_set_market_assignability', [
            $input['command_id'], $input['market_id'], $input['configuration_id'] ?? null,
            $input['expected_revision'], $input['reason_code'],
        ]);
    }

    private function execute(Request $request, string $function, array $arguments): array
    {
        return $this->context->run($request, static fn () => json_decode(
            DB::scalar('SELECT '.$function.'('.implode(',', array_fill(0, count($arguments), '?')).')', $arguments),
            true, flags: JSON_THROW_ON_ERROR,
        ), true);
    }
}
