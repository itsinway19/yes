<?php

namespace App\Http\Middleware;

use App\Security\GrantAuthority;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'locale' => app()->getLocale(),
            'auth' => [
                'user' => $request->user(),
                // Presentation-only binding for tab-local unfinished commands. Never an authority token.
                'sessionContextId' => $request->user()
                    ? hash_hmac('sha256', $request->session()->getId(), (string) config('app.key')) : null,
                'canInternalAdministration' => fn () => $request->is('internal/*') && $request->user() &&
                    app(GrantAuthority::class)->has(
                        $request->user(),
                        (int) $request->session()->get('a001_generation', 0),
                        'staff.view',
                    ),
                'canManageServices' => fn () => $request->is('internal/*') && $request->user() &&
                    app(GrantAuthority::class)->has(
                        $request->user(),
                        (int) $request->session()->get('a001_generation', 0),
                        'service.manage',
                    ),
                'canManageBusinessTypes' => fn () => $request->is('internal/*') && $request->user() &&
                    app(GrantAuthority::class)->has($request->user(),
                        (int) $request->session()->get('a001_generation', 0), 'business.manage'),
            ],
        ];
    }
}
