<?php

namespace App\Http\Responses;

use App\Appearance\GlobalAppearance;
use App\Platform\AdministrationNavigation;
use App\Security\ClientVisibility;
use App\Security\IdentityDocument;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SpaPage implements Responsable
{
    public function __construct(private array $data) {}

    /** Call only after the route's actual authorization boundary. */
    public static function needsData(Request $request): bool
    {
        return $request->expectsJson() && $request->header('X-Builderad-Witness') !== '1';
    }

    public function toResponse($request): Response
    {
        if ($request->expectsJson()) {
            return $this->resource($request);
        }

        $identity = app(IdentityDocument::class)->metadata($request);
        $locale = app()->getLocale();

        return response()->view('spa', [
            'appearanceTokens' => GlobalAppearance::tokens(app(GlobalAppearance::class)->current()),
            'identity' => $identity, 'locale' => $locale,
            'bootstrap' => ['identity' => $identity, 'locale' => $locale, 'application' => true,
                'documentState' => $identity['binding']['principal'] > 0 ? 'authenticated' : 'guest',
                'navigation' => app(AdministrationNavigation::class)->paths($request),
                'staffEnrollment' => $request->is('staff/onboarding')],
        ])->header('Cache-Control', 'no-store')->header('X-Builderad-Document-Location', $request->getRequestUri());
    }

    public function resource(Request $request): Response
    {
        $visibility = app(ClientVisibility::class)->describe($request);
        if ($request->header('X-Builderad-Witness') === '1') {
            return response()->json(['visibility' => $visibility])->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $this->data, 'locale' => app()->getLocale(), 'visibility' => $visibility])
            ->header('Cache-Control', 'no-store');
    }
}
