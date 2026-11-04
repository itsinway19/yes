<?php

namespace App\Http\Controllers;

use App\Foundation\Business\BusinessScope;
use App\MarketKnowledge\MarketEvidence;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BusinessFoundationController
{
    public function choices(Request $request, BusinessScope $scope): JsonResponse
    {
        $input = $request->validate(['after' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX]]);

        return $this->respond(fn () => $scope->choices($request, (int) ($input['after'] ?? 0)));
    }

    public function catalog(Request $request, BusinessScope $scope): JsonResponse
    {
        return $this->respond(fn () => $scope->catalog($request));
    }

    public function dataUseChoices(Request $request, BusinessScope $scope): JsonResponse
    {
        $input = $request->validate(['after' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX]]);

        return $this->respond(fn () => $scope->dataUseChoices($request, (int) ($input['after'] ?? 0)));
    }

    public function boundaries(Request $request, BusinessScope $scope): JsonResponse
    {
        $input = $request->validate(['after' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX]]);

        return $this->respond(fn () => $scope->boundaries($request, (int) ($input['after'] ?? 0)));
    }

    public function sources(Request $request, BusinessScope $scope): JsonResponse
    {
        $input = $request->validate(['after' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX]]);

        return $this->respond(fn () => $scope->sources($request, (int) ($input['after'] ?? 0)));
    }

    public function source(Request $request, string $source, BusinessScope $scope): JsonResponse
    {
        $input = $request->validate(['access' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX]]);

        return $this->respond(fn () => $scope->source($request, $source, (int) $input['access']));
    }

    public function businessSources(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $input = $request->validate([
            'access' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'after' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
        ]);

        return $this->respond(fn () => $scope->businessSources($request, $business, (int) $input['access'], (int) ($input['after'] ?? 0)));
    }

    public function boundary(Request $request, string $boundary, BusinessScope $scope): JsonResponse
    {
        $input = $request->validate(['access' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX]]);

        return $this->respond(fn () => $scope->boundary($request, $boundary, (int) $input['access']));
    }

    public function surface(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $input = $request->validate(['access' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX]]);

        return $this->respond(fn () => $scope->surface($request, $business, (int) $input['access']));
    }

    public function history(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $input = $request->validate([
            'access' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'kind' => ['required', 'in:context,markets,intent,preferences'],
            'after' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
        ]);

        return $this->respond(fn () => $scope->history($request, $business, (int) $input['access'], $input['kind'], (int) ($input['after'] ?? 0)));
    }

    public function legacyHistory(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $input = $request->validate([
            'access' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'kind' => ['required', 'in:context,markets,intent,preferences,object_purpose'],
            'after' => ['sometimes', 'uuid'],
        ]);

        return $this->respond(fn () => $scope->legacyHistory($request, $business, (int) $input['access'], $input['kind'], $input['after'] ?? null));
    }

    public function purposes(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $input = $request->validate(['access' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX], 'purpose' => ['sometimes', 'uuid'],
            'effective_at' => ['sometimes', 'string', 'max:40', 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(\\.\\d{1,6})?(Z|[+-]\\d{2}:\\d{2})$/D'],
            'known_at' => ['sometimes', 'string', 'max:40', 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(\\.\\d{1,6})?(Z|[+-]\\d{2}:\\d{2})$/D']]);
        $instant = static function (?string $value, string $field): ?\DateTimeImmutable {
            if ($value === null) {
                return null;
            }
            try {
                $date = new \DateTimeImmutable($value);
                $errors = \DateTimeImmutable::getLastErrors();
                if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                    throw new \InvalidArgumentException;
                }

                return $date;
            } catch (\Exception) {
                throw ValidationException::withMessages([$field => 'Valid timezone-qualified instant required.']);
            }
        };

        return $this->respond(fn () => $scope->purposes($request, $business, (int) $input['access'], $input['purpose'] ?? null,
            $instant($input['effective_at'] ?? null, 'effective_at'), $instant($input['known_at'] ?? null, 'known_at')));
    }

    public function writePurpose(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $this->exactJsonKeys($request, ['command', 'expected_context', 'authority_selection'], 220000);
        $input = $request->validate(['command' => ['required', 'array'], 'expected_context' => ['required', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
            'authority_selection' => ['required', 'array']]);

        return $this->respond(fn () => $scope->writePurpose($request, $business, $input['command'], (int) $input['expected_context'], $input['authority_selection']), 201);
    }

    public function store(Request $request, BusinessScope $scope): JsonResponse
    {
        $this->exactJsonKeys($request, ['command_id', 'name', 'business_type_id'], 32768);
        $input = $request->validate([
            'command_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'min:2', 'max:120'],
            'business_type_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->respond(fn () => $scope->create($request, $input['command_id'], $input['name'], $input['business_type_id'] ?? null), 201);
    }

    public function show(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $this->exactJsonKeys($request, ['authority_selection'], 70000);
        $input = $request->validate(['authority_selection' => ['required', 'array']]);

        return $this->respond(fn () => $scope->read($request, $business, $input['authority_selection']));
    }

    public function link(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $this->exactJsonKeys($request, ['command_id', 'workspace_public_id', 'kind', 'effect', 'expected_revision', 'authority_selection'], 80000);
        $input = $request->validate([
            'command_id' => ['required', 'uuid'], 'workspace_public_id' => ['required', 'uuid'],
            'kind' => ['sometimes', 'required', 'in:collaboration,agency'],
            'effect' => ['required', 'in:add,end'], 'expected_revision' => ['required', 'integer', 'min:0'],
            'authority_selection' => ['required', 'array'],
        ]);

        return $this->respond(fn () => $scope->link($request, $business, $input['workspace_public_id'],
            $input['command_id'], $input['effect'], $input['expected_revision'], $input['authority_selection'], $input['kind'] ?? 'collaboration'));
    }

    /** Optional fields stay optional; unexpected fields never acquire a meaning by being silently discarded. */
    private function exactJsonKeys(Request $request, array $allowed, int $limit): void
    {
        $invalid = fn () => ValidationException::withMessages(['command' => 'invalid_business_command']);
        if (! $request->isJson() || strlen($request->getContent()) > $limit) {
            throw $invalid();
        }
        try {
            $value = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $invalid();
        }
        if (! is_object($value) || array_diff(array_keys(get_object_vars($value)), $allowed)) {
            throw $invalid();
        }
    }

    private function commandBody(Request $request, array $fields, int $limit): object
    {
        $invalid = fn () => ValidationException::withMessages(['command' => 'invalid_business_command']);
        if (! $request->isJson() || strlen($request->getContent()) > $limit) {
            throw $invalid();
        }
        try {
            $raw = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $invalid();
        }
        if (! is_object($raw)) {
            throw $invalid();
        }
        $actual = array_keys(get_object_vars($raw));
        $expected = ['command_id', 'authority_selection', ...$fields];
        sort($actual);
        sort($expected);
        if ($actual !== $expected || ! is_string($raw->command_id) || ! Str::isUuid($raw->command_id)
            || ! is_object($raw->authority_selection)) {
            throw $invalid();
        }
        foreach ($fields as $field) {
            if (str_starts_with($field, 'expected_') && (! is_int($raw->{$field}) || $raw->{$field} < 0)) {
                throw $invalid();
            }
        }

        return $raw;
    }

    private function selection(object $raw): array
    {
        return json_decode(json_encode($raw->authority_selection, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    public function context(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $raw = $this->commandBody($request, ['name', 'business_type_id', 'expected_context'], 70000);
        abort_unless(is_string($raw->name) && ($raw->business_type_id === null || is_int($raw->business_type_id)), 422);

        return $this->respond(fn () => $scope->context($request, $business, $raw->command_id, $raw->name, $raw->business_type_id, $raw->expected_context, $this->selection($raw)));
    }

    public function markets(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $raw = $this->commandBody($request, ['codes', 'expected_context'], 70000);
        abort_unless(is_array($raw->codes) && array_is_list($raw->codes) && count($raw->codes) <= 300 && count(array_filter($raw->codes, 'is_string')) === count($raw->codes), 422);

        return $this->respond(fn () => $scope->markets($request, $business, $raw->command_id, $raw->codes, $raw->expected_context, $this->selection($raw)));
    }

    public function preferences(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $raw = $this->commandBody($request, ['value', 'expected_preference'], 80000);
        abort_unless(is_object($raw->value), 422);

        return $this->respond(fn () => $scope->preferences($request, $business, $raw->command_id, $raw->value, $raw->expected_preference, $this->selection($raw)));
    }

    public function intent(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $raw = $this->commandBody($request, ['value', 'confirmations', 'expected_context', 'expected_intent'], 160000);
        abort_unless(($raw->value === null || is_object($raw->value)) && is_object($raw->confirmations), 422);

        return $this->respond(fn () => $scope->intent($request, $business, $raw->command_id, $raw->value, (array) $raw->confirmations, $raw->expected_context, $raw->expected_intent, $this->selection($raw)));
    }

    public function grant(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $raw = $this->commandBody($request, ['effect', 'grantee_public_id', 'action', 'parent_grant_id', 'grant_id', 'expected_grant', 'expires_at', 'delegable', 'realm_workspace_public_id'], 80000);
        abort_unless(in_array($raw->effect, ['issue', 'revoke'], true) && is_string($raw->action) && strlen($raw->action) <= 80
            && is_string($raw->grantee_public_id) && Str::isUuid($raw->grantee_public_id)
            && ($raw->parent_grant_id === null || (is_int($raw->parent_grant_id) && $raw->parent_grant_id > 0))
            && ($raw->grant_id === null || (is_int($raw->grant_id) && $raw->grant_id > 0)) && is_bool($raw->delegable)
            && ($raw->realm_workspace_public_id === null || (is_string($raw->realm_workspace_public_id) && Str::isUuid($raw->realm_workspace_public_id)))
            && ($raw->expires_at === null || (is_string($raw->expires_at) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $raw->expires_at))), 422);

        return $this->respond(fn () => $scope->grant($request, $business, $raw->command_id, $raw->effect, $raw->grantee_public_id,
            $raw->action, $raw->parent_grant_id, $raw->grant_id, $raw->expected_grant, $raw->expires_at, $raw->delegable,
            $raw->realm_workspace_public_id, $this->selection($raw)));
    }

    public function internalTeam(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $raw = $this->commandBody($request, ['workspace_public_id', 'effect', 'recipient_public_id', 'expected_revision'], 80000);
        abort_unless(is_string($raw->workspace_public_id) && Str::isUuid($raw->workspace_public_id)
            && in_array($raw->effect, ['enroll', 'appoint', 'remove', 'end'], true)
            && ($raw->recipient_public_id === null || (is_string($raw->recipient_public_id) && Str::isUuid($raw->recipient_public_id))), 422);

        return $this->respond(fn () => $scope->internalTeam($request, $business, $raw->command_id, $raw->workspace_public_id,
            $raw->effect, $raw->recipient_public_id, $raw->expected_revision, $this->selection($raw)));
    }

    public function internalTeamSurface(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $input = $request->validate(['access' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX]]);

        return $this->respond(fn () => $scope->internalTeamSurface($request, $business, (int) $input['access']));
    }

    public function resourceRight(Request $request, BusinessScope $scope): JsonResponse
    {
        $raw = $this->commandBody($request, ['target_kind', 'target_ref', 'effect', 'payload', 'expected_revision'], 140000);
        abort_unless(is_string($raw->target_kind) && is_string($raw->target_ref) && Str::isUuid($raw->target_ref)
            && is_string($raw->effect) && is_object($raw->payload), 422);

        return $this->respond(fn () => $scope->resourceRight($request, $raw->command_id, $raw->target_kind, $raw->target_ref,
            $raw->effect, $raw->payload, $raw->expected_revision, $this->selection($raw)));
    }

    public function bindDataUse(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $raw = $this->commandBody($request, ['data_use_scope_id', 'effect', 'expected_relation'], 80000);
        abort_unless(is_string($raw->data_use_scope_id) && Str::isUuid($raw->data_use_scope_id)
            && in_array($raw->effect, ['add', 'end'], true), 422);

        return $this->respond(fn () => $scope->bindDataUse($request, $business, $raw->command_id, $raw->data_use_scope_id,
            $raw->effect, $raw->expected_relation, $this->selection($raw)));
    }

    public function marketEvidence(Request $request, string $evidence, MarketEvidence $owner): JsonResponse
    {
        $raw = $this->commandBody($request, ['data_use_scope_id', 'expected_revision', 'payload'], 280000);
        abort_unless(is_string($raw->data_use_scope_id) && Str::isUuid($raw->data_use_scope_id) && is_object($raw->payload), 422);

        return $this->respond(fn () => $owner->write($request, $evidence, $raw->command_id, $raw->data_use_scope_id,
            $raw->expected_revision, $raw->payload, $this->selection($raw)));
    }

    public function material(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $raw = $this->commandBody($request, ['material_refs', 'operation', 'purpose', 'recipient_ref'], 400000);
        abort_unless(is_array($raw->material_refs) && array_is_list($raw->material_refs) && count($raw->material_refs) >= 1 && count($raw->material_refs) <= 64
            && is_string($raw->operation) && in_array($raw->operation, ['retained_read', 'display', 'reuse', 'process', 'combine', 'export'], true)
            && is_string($raw->purpose) && mb_strlen($raw->purpose) >= 1 && mb_strlen($raw->purpose) <= 128
            && is_string($raw->recipient_ref) && Str::isUuid($raw->recipient_ref), 422);

        return $this->respond(fn () => $scope->material($request, $business, $raw->command_id, $raw->material_refs,
            $raw->operation, $raw->purpose, $raw->recipient_ref, $this->selection($raw)));
    }

    public function publish(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $raw = $this->commandBody($request, ['artifact_ref', 'dependencies', 'purpose', 'recipient_ref'], 400000);
        abort_unless(is_object($raw->artifact_ref) && is_array($raw->dependencies) && array_is_list($raw->dependencies) && count($raw->dependencies) >= 1 && count($raw->dependencies) <= 64
            && is_string($raw->purpose) && mb_strlen($raw->purpose) >= 1 && mb_strlen($raw->purpose) <= 128
            && is_string($raw->recipient_ref) && Str::isUuid($raw->recipient_ref), 422);

        return $this->respond(fn () => $scope->publish($request, $business, $raw->command_id, json_decode(json_encode($raw->artifact_ref, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
            json_decode(json_encode($raw->dependencies, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR), $raw->purpose, $raw->recipient_ref, $this->selection($raw)));
    }

    public function lifecycle(Request $request, string $business, BusinessScope $scope): JsonResponse
    {
        $raw = $this->commandBody($request, ['effect', 'target_ref', 'payload', 'expected_revision'], 70000);
        abort_unless(is_string($raw->effect) && ($raw->target_ref === null || (is_string($raw->target_ref) && Str::isUuid($raw->target_ref)))
            && is_object($raw->payload), 422);

        return $this->respond(fn () => $scope->lifecycle($request, $business, $raw->command_id, $raw->effect, $raw->target_ref,
            $raw->payload, $raw->expected_revision, $this->selection($raw)));
    }

    private function respond(callable $operation, int $status = 200): JsonResponse
    {
        try {
            return response()->json(['data' => $operation()], $status)->header('Cache-Control', 'no-store');
        } catch (QueryException $error) {
            $status = match ($error->errorInfo[0] ?? '') {
                '42501' => 403, '40001', 'B4090' => 409, '22023', '22007', '22008' => 422, default => throw $error,
            };

            return response()->json(['error' => match ($status) {
                403 => 'business_authority_denied', 409 => ($error->errorInfo[0] ?? '') === 'B4090' ? 'business_relation_revision_rejected' : 'business_conflict', default => 'invalid_business_command',
            }], $status)->header('Cache-Control', 'no-store');
        }
    }
}
