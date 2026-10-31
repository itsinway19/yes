<?php

namespace App\Http\Controllers;

use App\MarketKnowledge\CompetitiveIntelligenceConsumer;
use App\MarketKnowledge\CustomerDiscoveryConsumer;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class KnowledgeConsumerController
{
    public function customers(Request $request, string $business, CustomerDiscoveryConsumer $consumer): JsonResponse
    {
        return $this->respond($request, fn (array $body) => $consumer->inspect($request, $business, $body));
    }

    public function competition(Request $request, string $business, CompetitiveIntelligenceConsumer $consumer): JsonResponse
    {
        return $this->respond($request, fn (array $body) => $consumer->inspect($request, $business, $body));
    }

    private function respond(Request $request, callable $consumer): JsonResponse
    {
        abort_unless(strlen($request->getContent()) <= 65536, 422);
        $body = json_decode($request->getContent(), true);
        abort_unless(is_array($body), 422);
        $keys = array_keys($body);
        sort($keys);
        abort_unless($keys === ['authority_selection', 'command_id', 'material_ref', 'purpose']
            && is_string($body['command_id']) && Str::isUuid($body['command_id']) && is_string($body['purpose'])
            && is_array($body['material_ref']) && is_array($body['authority_selection']), 422);
        try {
            return response()->json(['data' => $consumer($body)])->header('Cache-Control', 'no-store');
        } catch (QueryException $error) {
            $status = match ($error->errorInfo[0] ?? '') {
                '42501' => 403, '40001', '23505' => 409, '22023', '22007', '22008' => 422, default => throw $error,
            };

            return response()->json(['error' => $status === 403 ? 'business_authority_denied' : 'invalid_knowledge_request'], $status)->header('Cache-Control', 'no-store');
        }
    }
}
