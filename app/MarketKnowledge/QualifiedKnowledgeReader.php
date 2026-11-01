<?php

namespace App\MarketKnowledge;

use App\Foundation\Business\BusinessScope;
use Illuminate\Http\Request;

/** Consumers obtain their input from the qualified material boundary, never from a caller-supplied finding. */
final class QualifiedKnowledgeReader
{
    public function read(Request $request, string $business, array $body): array
    {
        abort_unless(in_array($body['material_ref']['namespace'] ?? null, ['market.knowledge', 'market.business_finding'], true), 422);
        $result = app(BusinessScope::class)->material($request, $business, $body['command_id'], [$body['material_ref']], 'display', $body['purpose'], $request->user()->public_id, $body['authority_selection']);

        return $result['materials'][0]['fields']['finding'];
    }
}
