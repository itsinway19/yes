<?php

namespace App\MarketKnowledge;

use App\Foundation\Tenancy\PrincipalContext;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Operational custody uses a real current principal and an exact data-use grant. */
final class MarketEvidence
{
    public function __construct(private readonly PrincipalContext $principals) {}

    public function write(Request $request, string $evidence, string $command, string $scope, int $expected, \stdClass $payload, array $selection): array
    {
        if (DB::connection()->transactionLevel() !== 0 || ! $request->user()) {
            throw new LogicException('Market evidence requires its own authenticated transaction.');
        }

        return DB::transaction(function () use ($request, $evidence, $command, $scope, $expected, $payload, $selection): array {
            $this->principals->activate($request->user());

            return json_decode(DB::scalar('SELECT builderad_v2_market_evidence_write(?,?::uuid,?::uuid,?::uuid,?,?::jsonb,?::jsonb)', [
                SessionAuthority::sessionKey($request), $command, $evidence, $scope, $expected,
                json_encode($payload, JSON_THROW_ON_ERROR), json_encode($selection, JSON_THROW_ON_ERROR),
            ]), true, flags: JSON_THROW_ON_ERROR);
        }, 1);
    }
}
