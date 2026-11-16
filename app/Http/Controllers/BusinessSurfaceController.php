<?php

namespace App\Http\Controllers;

use App\Business\BusinessContext;
use App\Foundation\Business\BusinessScope;
use App\Http\Responses\SpaPage;
use App\Security\RegistrationEmailProof;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Web presentation adapter. Resource authority belongs to BusinessScope, never to Vue. */
final class BusinessSurfaceController
{
    public function setupCatalog(BusinessContext $context): JsonResponse
    {
        return response()->json(['data' => $context->setupCatalog()])->header('Cache-Control', 'no-store');
    }

    public function index(Request $request, BusinessScope $scope, RegistrationEmailProof $registration): SpaPage
    {
        $input = $request->validate(['after' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX]]);

        return $this->page($request, function () use ($request, $scope, $registration, $input): array {
            $data = $scope->choices($request, (int) ($input['after'] ?? 0));

            if ($request->query('entry') === '1') {
                $data['registration_attempt'] = $registration->businessAttempt($request->user());
            }

            return $data;
        });
    }

    public function create(Request $request, BusinessScope $scope, RegistrationEmailProof $registration): SpaPage
    {
        return $this->page($request, fn () => [
            'catalog' => $scope->catalog($request),
            'registration_attempt' => $registration->businessAttempt($request->user()),
        ]);
    }

    public function boundaries(Request $request, BusinessScope $scope): SpaPage
    {
        $input = $request->validate(['after' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX]]);

        return $this->page($request, fn () => $scope->boundaries($request, (int) ($input['after'] ?? 0)));
    }

    public function sources(Request $request, BusinessScope $scope): SpaPage
    {
        $input = $request->validate(['after' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX]]);

        return $this->page($request, fn () => $scope->sources($request, (int) ($input['after'] ?? 0)));
    }

    public function source(Request $request, string $source, BusinessScope $scope): SpaPage
    {
        $input = $request->validate(['access' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX]]);

        return $this->page($request, fn () => $scope->source($request, $source, (int) $input['access']));
    }

    public function boundary(Request $request, string $boundary, BusinessScope $scope): SpaPage
    {
        $input = $request->validate(['access' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX]]);

        return $this->page($request, fn () => $scope->boundary($request, $boundary, (int) $input['access']));
    }

    public function show(Request $request, string $business, BusinessScope $scope): SpaPage
    {
        $input = $request->validate([
            'access' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'focus_practice' => ['sometimes', 'required', 'uuid'],
        ]);

        return $this->page($request, fn () => $scope->surface($request, $business, (int) $input['access'], $input['focus_practice'] ?? null));
    }

    private function page(Request $request, callable $read): SpaPage
    {
        try {
            $data = $read();
        } catch (QueryException $error) {
            abort(match ($error->errorInfo[0] ?? null) {
                '42501' => 403, '40001' => 409, '22023' => 422, default => throw $error,
            });
        }
        // Only a server-qualified resource can contribute to the presentation witness.
        $request->attributes->set('builderad.qualified_visibility', hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)));

        return new SpaPage($data);
    }
}
