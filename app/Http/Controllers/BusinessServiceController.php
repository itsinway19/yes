<?php

namespace App\Http\Controllers;

use App\Foundation\Tenancy\PrincipalContext;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BusinessServiceController
{
    public function enroll(Request $request, string $business, PrincipalContext $principals): JsonResponse
    {
        $input = $this->validate($request, ['command_id' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted'],
            'authority_reference' => ['required', 'string', 'min:8', 'max:512'], 'expires_at' => ['required', 'date'],
            'mandate_grant_id' => ['required', 'regex:/^[1-9][0-9]{0,18}$/'], 'authority_selection' => ['required', 'array']]);
        if ($input['confirmed'] !== true) {
            throw ValidationException::withMessages(['confirmed' => 'Explicit service approval responsibility is required.']);
        }

        return response()->json(['data' => $this->invoke($request, $principals,
            'SELECT builderad_business_service_enroll(?,?::uuid,?::uuid,true,?,?::timestamptz,?::bigint,?::jsonb)',
            [$business, $input['command_id'], $input['authority_reference'], $input['expires_at'], $input['mandate_grant_id'],
                json_encode($input['authority_selection'], JSON_THROW_ON_ERROR)])]);
    }

    public function approve(Request $request, string $business, PrincipalContext $principals): JsonResponse
    {
        return $this->consent($request, $business, $principals, null);
    }

    public function revoke(Request $request, string $business, string $service, PrincipalContext $principals): JsonResponse
    {
        return $this->consent($request, $business, $principals, $service);
    }

    private function consent(Request $request, string $business, PrincipalContext $principals, ?string $revoke): JsonResponse
    {
        $input = $this->validate($request, ['command_id' => ['required', 'uuid'], 'service' => ['required', 'uuid'],
            'purpose' => ['required', 'string', 'min:10', 'max:128'], 'expected_revision' => ['required', 'integer', 'min:0'],
            'business_revision' => ['required', 'integer', 'min:0'], 'authority_selection' => ['required', 'array']]);
        abort_if($revoke !== null && $revoke !== $input['service'], 422);

        return response()->json($this->invoke($request, $principals,
            'SELECT builderad_business_service_consent(?,?::uuid,?::uuid,?::uuid,?,?,?,?::jsonb,?::boolean)',
            [$business, $input['command_id'], $input['service'], $input['purpose'], $input['expected_revision'], $input['business_revision'],
                json_encode($input['authority_selection'], JSON_THROW_ON_ERROR), $revoke !== null]));
    }

    private function validate(Request $request, array $rules): array
    {
        if (! $request->isJson() || array_diff(array_keys($request->all()), array_keys($rules))) {
            throw ValidationException::withMessages(['command' => 'Exact service command required.']);
        }

        return $request->validate($rules);
    }

    private function invoke(Request $request, PrincipalContext $principals, string $query, array $parameters): array
    {
        try {
            return DB::transaction(function () use ($request, $principals, $query, $parameters): array {
                $principals->activate($request->user());

                return json_decode(DB::scalar($query, [SessionAuthority::sessionKey($request), ...$parameters]), true, flags: JSON_THROW_ON_ERROR);
            });
        } catch (QueryException $error) {
            abort_if($error->getCode() === '42501', 403);
            abort_if(in_array($error->getCode(), ['40001', '23505'], true), 409);
            abort_if(in_array($error->getCode(), ['22023', '22P02', '23514'], true), 422);
            throw $error;
        }
    }
}
