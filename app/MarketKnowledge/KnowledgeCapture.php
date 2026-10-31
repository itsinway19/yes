<?php

namespace App\MarketKnowledge;

use App\Foundation\Tenancy\PrincipalContext;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

final class KnowledgeCapture
{
    public function capture(Request $request, array $body): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Shared capture owns its signed runtime transaction.');
        }

        return DB::transaction(function () use ($request, $body): array {
            app(PrincipalContext::class)->activate($request->user());

            return json_decode(DB::scalar('SELECT builderad_v2_market_knowledge_capture(?,?::uuid,?,?::jsonb,?,?::jsonb)', [
                SessionAuthority::sessionKey($request), $body['command_id'], $body['question'],
                json_encode($body['material_refs'], JSON_THROW_ON_ERROR), $body['purpose'],
                json_encode($body['authority_selection'], JSON_THROW_ON_ERROR),
            ]), true, flags: JSON_THROW_ON_ERROR);
        }, 1);
    }
}
