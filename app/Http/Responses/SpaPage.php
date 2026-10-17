<?php

namespace App\Http\Responses;

use App\Security\ClientVisibility;
use App\Security\IdentityDocument;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\Response;

final class SpaPage implements Responsable
{
    public function __construct(private array $data) {}

    public function toResponse($request): Response
    {
        if ($request->expectsJson()) {
            $visibility = app(ClientVisibility::class)->describe($request);
            if ($request->header('X-Builderad-Witness') === '1') {
                return response()->json(['visibility' => $visibility])->header('Cache-Control', 'no-store');
            }

            return response()->json(['data' => $this->data, 'locale' => app()->getLocale(), 'visibility' => $visibility])
                ->header('Cache-Control', 'no-store');
        }

        return response()->view('spa', [
            'identity' => app(IdentityDocument::class)->metadata($request), 'locale' => app()->getLocale(),
        ])->header('Cache-Control', 'no-store')->header('X-Builderad-Document-Location', $request->getRequestUri());
    }
}
