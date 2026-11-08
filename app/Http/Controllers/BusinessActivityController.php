<?php

namespace App\Http\Controllers;

use App\Foundation\Business\BusinessScope;
use App\MarketKnowledge\CustomerDiscoveryConsumer;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BusinessActivityController
{
    public function knowledgeOptions(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $input = $request->validate(['access' => ['required', 'integer', 'min:1'], 'after' => ['sometimes', 'integer', 'min:0', 'max:10000']]);

        return $this->respond(fn () => $scope->practiceKnowledgeOptions($request, $business, (int) $input['access'], (int) ($input['after'] ?? 0)));
    }

    public function assess(Request $request, string $business, CustomerDiscoveryConsumer $consumer): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 40000, 422);
        $raw = json_decode($request->getContent(), true);
        abort_unless(is_array($raw) && ! array_is_list($raw), 422);
        $input = validator($raw, [
            'command_id' => ['required', 'uuid'], 'practice_id' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:1'],
            'material_ref' => ['required', 'array'], 'purpose' => ['required', 'string', 'max:128'],
            'relation' => ['required', 'array'], 'question' => ['required', 'array'],
            'authority_selection' => ['required', 'array'], 'read_selection' => ['required', 'array'],
        ])->validate();
        abort_unless(count(array_diff(array_keys($request->all()), array_keys($input))) === 0 && is_int($input['expected_revision']), 422);

        return $this->respond(fn () => $consumer->inspectPractice($request, $business, $input));
    }

    public function index(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $input = $request->validate(['access' => ['required', 'integer', 'min:1'], 'practice' => ['nullable', 'uuid']]);

        return $this->respond(fn () => $scope->practices($request, $business, (int) $input['access'], $input['practice'] ?? null));
    }

    public function store(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 40000, 422);
        $raw = json_decode($request->getContent(), true);
        abort_unless(is_array($raw) && ! array_is_list($raw), 422);
        $input = validator($raw, [
            'command_id' => ['required', 'uuid'], 'practice_id' => ['required', 'uuid'],
            'operation' => ['required', 'in:create,update,correct,reclassify,deactivate'],
            'expected_revision' => ['required', 'integer', 'min:0'], 'value' => ['present', 'nullable', 'array'],
            'correction_reason' => ['sometimes', 'nullable', 'string', 'min:3', 'max:1000'],
            'authority_selection' => ['required', 'array'], 'read_selection' => ['required', 'array'],
        ])->validate();
        abort_unless(count(array_diff(array_keys($request->all()), array_keys($input))) === 0
            && is_int($input['expected_revision']), 422);

        return $this->respond(fn () => $scope->writePractice($request, $business, $input));
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return response()->json(['data' => $operation()])->header('Cache-Control', 'no-store');
        } catch (QueryException $error) {
            $code = $error->errorInfo[0] ?? '';
            $status = match ($code) {
                '42501' => 403, '40001', '23505', 'B4090' => 409, '22023', '22007', '22008', '22P02' => 422,
                default => throw $error,
            };

            return response()->json(['error' => match ($status) {
                403 => 'business_authority_denied', 409 => $code === 'B4090' ? 'practice_revision_rejected' : 'business_conflict', default => 'invalid_practice_command',
            }], $status)->header('Cache-Control', 'no-store');
        }
    }
}
