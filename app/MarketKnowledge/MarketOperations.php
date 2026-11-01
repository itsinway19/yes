<?php

namespace App\MarketKnowledge;

use App\Foundation\Tenancy\PrincipalContext;
use App\MarketKnowledge\Processing\BusinessFeasibility;
use App\MarketKnowledge\Processing\OfferEquivalence;
use App\MarketKnowledge\Processing\QualifiedInterestChange;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class MarketOperations
{
    public const ACTION = 'market.knowledge.observe';

    public function read(Request $request, ?string $cursor): array
    {
        // EnsureCurrentPrincipal owns this runtime transaction. Never use owner or raw authority table reads.
        $context = DB::selectOne('SELECT actor_user_id FROM builderad_signed_context()');
        if (! $context) {
            app(PrincipalContext::class)->activate($request->user());
        } else {
            abort_unless((int) $context->actor_user_id === $request->user()->id, 403);
        }
        $data = json_decode(DB::scalar('SELECT builderad_v2_market_operations_read(?,?::uuid)', [SessionAuthority::sessionKey($request), $cursor]), true, flags: JSON_THROW_ON_ERROR);
        $data['methods'] = [];
        foreach ([new OfferEquivalence, new QualifiedInterestChange, new BusinessFeasibility, new QualifiedMarketFact('price'), new QualifiedMarketFact('geography')] as $method) {
            try {
                $available = $method->available() ? 'available' : 'unavailable';
            } catch (Throwable) {
                $available = 'unknown';
            }
            $data['methods'][] = ['method_ref' => $method->reference(), 'availability' => $available];
        }

        return [...$data, 'private_processing' => 'business_scoped', 'connection_status' => 'not_observed'];
    }
}
