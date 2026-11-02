<?php

namespace App\Http\Controllers;

use App\Commercial\InquiryEvidence;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use JsonException;

final class InquiryEvidenceController
{
    public function eligibility(Request $request, string $business, InquiryEvidence $evidence): JsonResponse
    {
        return $this->respond(fn () => $evidence->eligibility($request, $business, $this->querySelection($request)));
    }

    public function index(Request $request, string $business, InquiryEvidence $evidence): JsonResponse
    {
        $input = $request->validate(['cursor' => ['sometimes', 'uuid']]);

        return $this->respond(fn () => $evidence->read($request, $business, $this->querySelection($request), cursor: $input['cursor'] ?? null));
    }

    public function history(Request $request, string $business, string $declaration, InquiryEvidence $evidence): JsonResponse
    {
        $input = $request->validate(['after' => ['sometimes', 'integer', 'min:0', 'max:1000000000']]);

        return $this->respond(fn () => $evidence->read($request, $business, $this->querySelection($request), $declaration, (int) ($input['after'] ?? 0)));
    }

    public function enroll(Request $request, string $business, InquiryEvidence $evidence): JsonResponse
    {
        $body = $this->body($request, ['basis_revision', 'confirm']);
        abort_unless(is_int($body['basis_revision']) && $body['basis_revision'] > 0 && $body['confirm'] === true, 422);

        return $this->respond(fn () => $evidence->enroll($request, $business, $body['command_id'], $body['basis_revision'], true, $body['authority_selection']));
    }

    public function store(Request $request, string $business, InquiryEvidence $evidence): JsonResponse
    {
        $body = $this->body($request, ['value']);
        abort_unless(is_array($body['value']) && ! array_is_list($body['value']), 422);

        return $this->respond(fn () => $evidence->write($request, $business, $body['command_id'], null, 0, 'record', $body['value'], $body['authority_selection']));
    }

    public function revise(Request $request, string $business, string $declaration, InquiryEvidence $evidence): JsonResponse
    {
        $body = $this->body($request, ['expected_revision', 'effect', 'value']);
        abort_unless(is_int($body['expected_revision']) && $body['expected_revision'] >= 1 && in_array($body['effect'], ['correct', 'withdraw'], true)
            && ($body['effect'] === 'withdraw' ? $body['value'] === null : is_array($body['value']) && ! array_is_list($body['value'])), 422);

        return $this->respond(fn () => $evidence->write($request, $business, $body['command_id'], $declaration, $body['expected_revision'], $body['effect'], $body['value'], $body['authority_selection']));
    }

    public function revoke(Request $request, string $business, InquiryEvidence $evidence): JsonResponse
    {
        $body = $this->body($request, ['action']);
        abort_unless(in_array($body['action'], ['business.inquiry_evidence.record', 'business.inquiry_evidence.correct', 'business.inquiry_evidence.read'], true), 422);

        return $this->respond(fn () => $evidence->revoke($request, $business, $body['command_id'], $body['action'], $body['authority_selection']));
    }

    private function querySelection(Request $request): array
    {
        $input = $request->validate(['access' => ['required', 'regex:/^[1-9][0-9]{0,17}$/']]);

        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => (string) $input['access']]]];
    }

    private function body(Request $request, array $extra): array
    {
        abort_if(strlen($request->getContent()) > 14000, 413);
        try {
            $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(422);
        }
        $keys = is_array($body) ? array_keys($body) : [];
        $expected = ['command_id', 'authority_selection', ...$extra];
        sort($keys);
        sort($expected);
        abort_unless($keys === $expected && is_string($body['command_id']) && Str::isUuid($body['command_id']) && is_array($body['authority_selection']), 422);

        return $body;
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            $data = $operation();
            if (array_key_exists('grants', $data)) {
                // A permission map remains a JSON object even when empty.
                $data['grants'] = (object) $data['grants'];
            }

            return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
        } catch (QueryException $error) {
            $status = match ($error->errorInfo[0] ?? '') {
                '42501' => 403, '40001', '23505', 'I4090' => 409, '22023', '22007', '22008', '23514', '22P02' => 422, default => throw $error,
            };

            return response()->json(['error' => match ($status) {
                403 => 'inquiry_access_unavailable', 409 => ($error->errorInfo[0] ?? '') === 'I4090' ? 'inquiry_revision_rejected' : 'inquiry_conflict', default => 'invalid_inquiry_command',
            }], $status)->header('Cache-Control', 'no-store');
        }
    }
}
