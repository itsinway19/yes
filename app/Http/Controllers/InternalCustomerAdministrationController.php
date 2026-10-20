<?php

namespace App\Http\Controllers;

use App\Foundation\Tenancy\PrincipalContext;
use App\Http\Responses\SpaPage;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class InternalCustomerAdministrationController
{
    public function index(Request $request, PrincipalContext $context)
    {
        $input = $request->validate([
            'kind' => ['sometimes', Rule::in(['users', 'workspaces'])],
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['pending', 'current', 'disabled', 'active', 'suspended', 'archived'])],
            'page' => ['sometimes', 'integer', 'between:1,100000'],
            'selected' => ['nullable', 'uuid'],
        ]);
        $context->activate($request->user());
        try {
            if (! SpaPage::needsData($request)) {
                DB::selectOne('SELECT builderad_customer_directory_witness()');

                return new SpaPage([]);
            }
            $result = DB::selectOne('SELECT builderad_customer_directory(?,?,?,?,?::uuid) AS result', [
                $input['kind'] ?? 'users', $input['search'] ?? '', $input['status'] ?? '', $input['page'] ?? 1, $input['selected'] ?? null,
            ]);
        } catch (QueryException $error) {
            if ($error->getCode() === '42501') {
                abort(403);
            }
            throw $error;
        }

        return new SpaPage([
            ...json_decode($result->result, true, flags: JSON_THROW_ON_ERROR),
            'filters' => ['kind' => $input['kind'] ?? 'users', 'search' => $input['search'] ?? '', 'status' => $input['status'] ?? '', 'page' => (int) ($input['page'] ?? 1)],
            'freshAuthentication' => app(SessionAuthority::class)->hasFreshAuthentication($request, $request->user()),
        ]);
    }

    public function status(Request $request, PrincipalContext $context)
    {
        $input = $request->validate([
            'command_id' => ['required', 'uuid'], 'kind' => ['required', Rule::in(['users', 'workspaces'])],
            'target' => ['required', 'uuid'], 'decision' => ['required', Rule::in(['suspend', 'reactivate'])],
            'reason' => ['required', 'string', 'max:128'],
            'expected_status' => ['required', Rule::in(['pending', 'current', 'disabled', 'active', 'suspended'])],
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);
        try {
            $result = DB::transaction(function () use ($request, $context, $input) {
                $context->activate($request->user());

                return DB::selectOne('SELECT builderad_customer_status(?,?::uuid,?,?::uuid,?,?,?,?) AS result', [
                    SessionAuthority::sessionKey($request), $input['command_id'], $input['kind'], $input['target'],
                    $input['decision'], trim($input['reason']), $input['expected_status'], $input['expected_revision'],
                ]);
            });
        } catch (QueryException $error) {
            if ($error->getCode() === '42501') {
                abort(403);
            }
            if ($error->getCode() === 'P0002') {
                abort(404);
            }
            if ($error->getCode() === '40001') {
                return response()->json(['message' => 'State changed. Reload before starting another operation.'], 409);
            }
            if (in_array($error->getCode(), ['22023', '23514'], true)) {
                return response()->json(['message' => 'This status change is not available.'], 422);
            }
            throw $error;
        }

        return response()->json(json_decode($result->result, true, flags: JSON_THROW_ON_ERROR));
    }
}
