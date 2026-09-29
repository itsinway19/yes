<?php

namespace App\Commercial;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Catalog owner: immutable approved provenance only; no pricing/Offer publication. */
final class ApprovedCatalogReference
{
    public function __construct(private readonly CommercialContext $context) {}

    public function record(Request $request, array $input): array
    {
        return $this->context->run($request, static fn () => json_decode(DB::scalar(
            'SELECT builderad_record_commercial_catalog(?,?,?,?,?,?,?,?)', [
                $input['command_id'], $input['market_id'], $input['catalog_reference_id'],
                $input['catalog_identity'], $input['catalog_version'], $input['approval_reference'],
                $input['source_sha256'], $input['reason_code'],
            ],
        ), true, flags: JSON_THROW_ON_ERROR), true);
    }
}
