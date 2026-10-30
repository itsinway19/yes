<?php

namespace App\Http\Controllers;

use App\MarketKnowledge\Processing\MarketProcessing;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class MarketProcessingController
{
    public function prepare(Request $request, string $business, MarketProcessing $processing): JsonResponse
    {
        $body = $this->body($request, ['command_id', 'question', 'material_refs', 'purpose', 'authority_selection', 'context_selection']);
        abort_unless(in_array($body['question'], ['compare_offers', 'compare_interest', 'assess_business_feasibility'], true)
            && is_array($body['material_refs']) && array_is_list($body['material_refs']) && count($body['material_refs']) >= 1 && count($body['material_refs']) <= 2
            && is_string($body['purpose']) && mb_strlen($body['purpose']) >= 1 && mb_strlen($body['purpose']) <= 128, 422);

        return $this->respond(fn () => $processing->prepare($request, $business, $body));
    }

    public function complete(Request $request, string $business, string $run, MarketProcessing $processing): JsonResponse
    {
        $body = $this->body($request, ['command_id', 'effect', 'authority_selection', 'context_selection']);
        abort_unless(in_array($body['effect'], ['complete', 'cancel'], true), 422);

        return $this->respond(fn () => $processing->complete($request, $business, $run, $body));
    }

    private function body(Request $request, array $keys): array
    {
        abort_unless(strlen($request->getContent()) <= 100000, 422);
        $body = json_decode($request->getContent(), true);
        abort_unless(is_array($body), 422);
        $actual = array_keys($body);
        sort($actual);
        sort($keys);
        abort_unless($actual === $keys && is_string($body['command_id']) && Str::isUuid($body['command_id'])
            && is_array($body['authority_selection']) && ($body['context_selection'] === null || is_array($body['context_selection'])), 422);

        return $body;
    }

    private function respond(callable $command): JsonResponse
    {
        try {
            return response()->json(['data' => $command()])->header('Cache-Control', 'no-store');
        } catch (QueryException $error) {
            $status = match ($error->errorInfo[0] ?? '') {
                '42501' => 403, '40001', '23505' => 409, '22023', '22007', '22008' => 422, default => throw $error
            };

            return response()->json(['error' => match ($status) {
                403 => 'business_authority_denied', 409 => 'business_conflict', default => 'invalid_business_command'
            }], $status)->header('Cache-Control', 'no-store');
        }
    }
}
