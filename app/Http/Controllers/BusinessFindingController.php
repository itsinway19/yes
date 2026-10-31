<?php

namespace App\Http\Controllers;

use App\MarketKnowledge\BusinessFindingCapture;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class BusinessFindingController
{
    public function capture(Request $request, string $business, BusinessFindingCapture $capture): JsonResponse
    {
        abort_unless(strlen($request->getContent()) <= 65536, 422);
        $body = json_decode($request->getContent(), true);
        abort_unless(is_array($body), 422);
        $keys = array_keys($body);
        sort($keys);
        abort_unless($keys === ['authority_selection', 'command_id', 'context_selection', 'continuation_selection', 'output_scope_id', 'processing_run_ref', 'read_command_id'], 422);
        foreach (['command_id', 'processing_run_ref', 'read_command_id', 'output_scope_id'] as $key) {
            abort_unless(is_string($body[$key]) && Str::isUuid($body[$key]), 422);
        }
        abort_unless(is_array($body['authority_selection']) && is_array($body['continuation_selection']) && ($body['context_selection'] === null || is_array($body['context_selection'])), 422);
        try {
            return response()->json(['data' => $capture->capture($request, $business, $body)])->header('Cache-Control', 'no-store');
        } catch (QueryException $error) {
            $status = match ($error->errorInfo[0] ?? '') {
                '42501' => 403, '40001', '23505' => 409, '22023', '22007', '22008' => 422, default => throw $error,
            };

            return response()->json(['error' => match ($status) {
                403 => 'business_authority_denied', 409 => 'business_conflict', default => 'invalid_business_command',
            }], $status)->header('Cache-Control', 'no-store');
        }
    }
}
