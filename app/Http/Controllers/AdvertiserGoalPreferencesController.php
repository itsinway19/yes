<?php

namespace App\Http\Controllers;

use App\Business\AdvertiserGoalPreferences;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Http\Responses\SpaPage;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AdvertiserGoalPreferencesController extends Controller
{
    public function edit(Request $request, string $workspace, WorkspaceContext $context,
        AdvertiserGoalPreferences $goals): mixed
    {
        return $context->run($request->user(), $workspace, function (Workspace $active) use ($request, $goals) {
            if ($request->expectsJson()) {
                return response()->json($goals->projection($active))->header('Cache-Control', 'no-store');
            }

            return new SpaPage([]);
        }, $request);
    }

    public function update(Request $request, string $workspace, WorkspaceContext $context, AdvertiserGoalPreferences $goals): mixed
    {
        $invalid = app()->getLocale() === 'ar' ? 'راجع الأهداف والأولوية والنتائج المحددة.' : 'Check the selected goals, starting priority and results.';
        if (! $request->isJson() || strlen($request->getContent()) > 8192) {
            throw ValidationException::withMessages(['value' => $invalid]);
        }
        try {
            $raw = json_decode($request->getContent(), false, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['value' => $invalid]);
        }
        if (! is_object($raw) || count(get_object_vars($raw)) !== 2 || ! isset($raw->value, $raw->version)
            || ! is_int($raw->version) || $raw->version < 0 || ! is_object($raw->value)
            || ! is_object($raw->value->results ?? null)) {
            throw ValidationException::withMessages(['value' => $invalid]);
        }
        // Preserve the raw exact shape: framework trimming must not turn invalid IDs into accepted choices.
        $value = json_decode(json_encode($raw->value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $value['results'] = (object) $value['results'];
        try {
            $saved = $context->run($request->user(), $workspace,
                fn (Workspace $active) => $goals->write($request, $active, $value, $raw->version), $request);

            return response()->json($saved);
        } catch (QueryException $error) {
            if ($error->getCode() === '42501') {
                abort(403);
            }
            if ($error->getCode() === '40001') {
                return response()->json(['message' => app()->getLocale() === 'ar'
                    ? 'تغيّرت التفضيلات المحفوظة. احتفظ بمسودتك وراجع النسخة الحالية قبل إعادة الحفظ.'
                    : 'Saved preferences changed. Keep your draft and review the current version before saving again.'], 409);
            }
            if (in_array($error->getCode(), ['22023', '22P05'], true)) {
                throw ValidationException::withMessages(['value' => $invalid]);
            }
            throw $error;
        }
    }
}
