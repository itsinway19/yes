<?php

namespace App\Http\Controllers;

use App\MarketKnowledge\KnowledgeCapture;
use App\MarketKnowledge\KnowledgePublisher;
use App\Security\GrantAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class MarketKnowledgeController
{
    public function publish(Request $request, KnowledgePublisher $publisher): JsonResponse
    {
        abort_unless(app(GrantAuthority::class)->has($request->user(), (int) $request->session()->get('a001_generation', 0), 'market.knowledge.publish'), 403);
        abort_unless(strlen($request->getContent()) <= 65536, 422);
        $body = json_decode($request->getContent(), true);
        abort_unless(is_array($body), 422);
        $keys = array_keys($body);
        sort($keys);
        abort_unless($keys === ['command_id', 'output_scope_id', 'process_selection', 'publication_selection', 'run_ref']
            && is_string($body['command_id']) && Str::isUuid($body['command_id'])
            && is_string($body['run_ref']) && Str::isUuid($body['run_ref'])
            && is_string($body['output_scope_id']) && Str::isUuid($body['output_scope_id'])
            && is_array($body['process_selection']) && is_array($body['publication_selection']), 422);

        return $this->respond(fn () => $publisher->publish($request, $body['run_ref'], $body));
    }

    public function capture(Request $request, KnowledgeCapture $capture): JsonResponse
    {
        // Early disclosure guard; the SQL effect independently qualifies the current signed actor, licence and purpose.
        abort_unless(app(GrantAuthority::class)->has($request->user(), (int) $request->session()->get('a001_generation', 0), 'market.knowledge.capture'), 403);
        abort_unless(strlen($request->getContent()) <= 65536, 422);
        $body = json_decode($request->getContent(), true);
        abort_unless(is_array($body), 422);
        $keys = array_keys($body);
        sort($keys);
        abort_unless($keys === ['authority_selection', 'command_id', 'material_refs', 'purpose', 'question']
            && is_string($body['command_id']) && Str::isUuid($body['command_id'])
            && is_string($body['question']) && is_string($body['purpose'])
            && is_array($body['material_refs']) && is_array($body['authority_selection']), 422);

        return $this->respond(fn () => $capture->capture($request, $body));
    }

    private function respond(callable $command): JsonResponse
    {
        try {
            return response()->json(['data' => $command()])->header('Cache-Control', 'no-store');
        } catch (QueryException $error) {
            $status = match ($error->errorInfo[0] ?? '') {
                '42501' => 403, '40001', '23505' => 409, '22023', '22007', '22008' => 422, default => throw $error,
            };

            return response()->json(['error' => match ($status) {
                403 => 'market_authority_denied', 409 => 'market_command_conflict', default => 'invalid_market_command',
            }], $status)->header('Cache-Control', 'no-store');
        }
    }
}
