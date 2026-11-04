<?php

namespace App\Http\Controllers;

use App\Foundation\Tenancy\OrganizationTeam;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class OrganizationTeamController
{
    public function index(Request $request, string $organization, OrganizationTeam $team): JsonResponse
    {
        return $this->respond(fn () => $team->read($request, $organization));
    }

    public function store(Request $request, string $organization, OrganizationTeam $team): JsonResponse
    {
        abort_unless($request->isJson() && strlen($request->getContent()) <= 4096, 422);
        $raw = json_decode($request->getContent());
        abort_unless(is_object($raw), 422);
        $keys = array_keys(get_object_vars($raw));
        sort($keys);
        abort_unless($keys === ['command_id', 'effect', 'expected_revision', 'recipient_public_id'] && is_int($raw->expected_revision), 422);
        $input = validator((array) $raw, ['command_id' => ['required', 'uuid'], 'effect' => ['required', Rule::in(['add', 'remove', 'leave'])],
            'recipient_public_id' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:0', 'max:'.PHP_INT_MAX]])->validate();

        return $this->respond(fn () => $team->command($request, $organization, $input));
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return response()->json(['data' => $operation()])->header('Cache-Control', 'no-store');
        } catch (QueryException $error) {
            $status = match ($error->errorInfo[0] ?? '') {
                '42501' => 403, '40001', 'B4090' => 409, '22023' => 422, default => throw $error,
            };

            return response()->json(['error' => 'organization_team_request_rejected'], $status)->header('Cache-Control', 'no-store');
        }
    }
}
