<?php

namespace App\Http\Controllers;

use App\Business\BusinessContext;
use App\Business\ObjectPurpose;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Http\Requests\ObjectPurposeCommandRequest;
use App\Http\Responses\SpaPage;
use App\Models\Workspace;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ObjectPurposeController extends Controller
{
    public function index(Request $request, string $workspace, WorkspaceContext $context, ObjectPurpose $purpose, BusinessContext $business): mixed
    {
        return $this->read($request, $workspace, null, $context, $purpose, $business);
    }

    public function show(Request $request, string $workspace, string $purpose, WorkspaceContext $context, ObjectPurpose $service, BusinessContext $business): mixed
    {
        return $this->read($request, $workspace, $purpose, $context, $service, $business);
    }

    public function applicationIndex(Request $request, string $workspace, WorkspaceContext $context, ObjectPurpose $purpose, BusinessContext $business): mixed
    {
        return $this->read($request, $workspace, null, $context, $purpose, $business, true);
    }

    public function applicationShow(Request $request, string $workspace, string $purpose, WorkspaceContext $context, ObjectPurpose $service, BusinessContext $business): mixed
    {
        return $this->read($request, $workspace, $purpose, $context, $service, $business, true);
    }

    private function read(Request $request, string $workspace, ?string $id, WorkspaceContext $context, ObjectPurpose $service, BusinessContext $business, bool $application = false): mixed
    {
        $effective = $this->instant($request->query('effective_at'), 'effective_at');
        $known = $this->instant($request->query('known_at'), 'known_at');

        return $this->guard(function () use ($request, $workspace, $id, $context, $service, $business, $effective, $known, $application) {
            return $context->run($request->user(), $workspace, function (Workspace $active) use ($request, $id, $service, $business, $effective, $known, $application) {
                if (! SpaPage::needsData($request)) {
                    $service->assertReadable($request, $active, $id, $known);

                    return $application ? (new SpaPage([]))->resource($request) : new SpaPage([]);
                }
                $projection = $service->projection($request, $active, $id, $effective, $known);
                if ($request->expectsJson() && ! $application) {
                    return response()->json($projection)->header('Cache-Control', 'no-store');
                }

                return $application
                    ? (new SpaPage(['workspace' => ['public_id' => $active->public_id, 'name' => $active->name],
                        'projection' => $projection, 'selectedPurpose' => $id, 'business' => $business->projection($request, $active)]))->resource($request)
                    : new SpaPage([]);
            }, $request);
        });
    }

    public function store(ObjectPurposeCommandRequest $request, string $workspace, WorkspaceContext $context, ObjectPurpose $purpose): mixed
    {
        abort_unless($request->input('purpose_id') === null && $request->input('operation') === 'declare', 422);

        return $this->write($request, $workspace, $context, $purpose);
    }

    public function revise(ObjectPurposeCommandRequest $request, string $workspace, string $purpose, WorkspaceContext $context, ObjectPurpose $service): mixed
    {
        abort_unless($request->input('purpose_id') === $purpose && $request->input('operation') !== 'declare', 422);

        return $this->write($request, $workspace, $context, $service);
    }

    private function write(ObjectPurposeCommandRequest $request, string $workspace, WorkspaceContext $context, ObjectPurpose $service): mixed
    {
        return $this->guard(function () use ($request, $workspace, $context, $service) {
            // Preserve all input: unknown fields must be rejected, not silently discarded.
            $result = $context->run($request->user(), $workspace,
                fn (Workspace $active) => $service->write($request, $active, $request->all()), $request);
            if ($request->expectsJson()) {
                return response()->json($result, 201);
            }

            return redirect()->route('workspaces.object-purposes.show', [$workspace, $result['purpose_id']]);
        });
    }

    private function guard(callable $action): mixed
    {
        try {
            return $action();
        } catch (QueryException $error) {
            if ($error->getCode() === '42501') {
                abort(403);
            }
            if ($error->getCode() === '40001') {
                return response()->json(['message' => 'The reviewed declaration or current relationship changed. Reload, review and reconfirm.'], 409);
            }
            if (in_array($error->getCode(), ['22023', '22P05'], true)) {
                throw ValidationException::withMessages(['declaration' => 'Check the exact declaration, references, effective standing and confirmations.']);
            }
            throw $error;
        }
    }

    private function instant(mixed $value, string $field): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/D', $value, $parts) ||
            ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw ValidationException::withMessages([$field => 'Provide a valid timezone-qualified instant.']);
        }
        try {
            $instant = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                throw new \InvalidArgumentException;
            }

            return $instant;
        } catch (\Exception) {
            throw ValidationException::withMessages([$field => 'Provide a valid timezone-qualified instant.']);
        }
    }
}
