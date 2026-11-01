<?php

namespace App\MarketKnowledge;

use App\Foundation\Tenancy\PrincipalContext;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

final class BusinessFindingCapture
{
    public function capture(Request $request, string $business, array $body): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Private finding capture owns its runtime transaction.');
        }

        return DB::transaction(function () use ($request, $business, $body): array {
            app(PrincipalContext::class)->activate($request->user());

            return json_decode(DB::scalar('SELECT builderad_v2_business_finding_capture(?,?::uuid,?::uuid,?::uuid,?::uuid,?::uuid,?::jsonb,?::jsonb,?::jsonb)', [
                SessionAuthority::sessionKey($request), $business, $body['command_id'], $body['processing_run_ref'], $body['read_command_id'], $body['output_scope_id'],
                json_encode($body['authority_selection'], JSON_THROW_ON_ERROR), $body['context_selection'] === null ? null : json_encode($body['context_selection'], JSON_THROW_ON_ERROR),
                json_encode($body['continuation_selection'], JSON_THROW_ON_ERROR),
            ]), true, flags: JSON_THROW_ON_ERROR);
        }, 1);
    }
}
