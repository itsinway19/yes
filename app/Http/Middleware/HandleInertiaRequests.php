<?php

namespace App\Http\Middleware;

use App\Security\ClientVisibility;
use App\Security\ExpiredSecuritySession;
use App\Security\GrantAuthority;
use App\Security\IdentityDocument;
use App\Security\SessionAuthority;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $probe = $request->isMethod('GET') && $request->header('X-Builderad-Visibility') === '1';
        if ($probe) {
            $context = app(ClientVisibility::class)->describe($request);
            // Bind this GET to a previously rendered path and current identity. This
            // digest grants nothing and cannot bypass the route/controller guards.
            if (! $context['protected'] || ! hash_equals($context['context'], (string) $request->header('X-Builderad-Visibility-Context'))) {
                return response()->json(['message' => 'Presentation context changed.'], 409)
                    ->header('Cache-Control', 'no-store');
            }
            $request->attributes->set('builderad.visibility_probe', true);
            // Public/auth-only pages may still contain an authenticated user's
            // profile. They must not become an expiry bypass during restoration.
            DB::transaction(function () use ($request): void {
                $session = DB::table('security_sessions')
                    ->where('session_key_hash', SessionAuthority::sessionKey($request))->first();
                if ($session?->auth_method === 'staff_enrollment') {
                    app(SessionAuthority::class)->assertStaffEnrollment($request, $request->user());
                } else {
                    $generation = (int) $request->session()->get('a001_generation', 0);
                    if (! DB::table('principal_lifecycles')->where('user_id', $request->user()->id)
                        ->where('generation', $generation)->where('status', 'current')->exists()) {
                        throw new ExpiredSecuritySession;
                    }
                    app(SessionAuthority::class)->assertCurrent($request, $request->user(), $generation);
                }
            });
            // A probe returns only authority metadata, not an Inertia page. It
            // uses the current route implementation even for an older asset build.
            $request->headers->set('X-Inertia-Version', $this->version($request) ?? '');
        }

        $response = parent::handle($request, $next);
        if ($probe && $response->getStatusCode() === 200 && $response->headers->get('X-Inertia') === 'true') {
            // The real page route has now passed all its existing checks, including
            // workspace/object-specific authorization. Never return its old data.
            return response()->json(app(ClientVisibility::class)->describe($request))
                ->header('Cache-Control', 'no-store');
        }

        return $response;
    }

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
            'identity' => Inertia::always(fn () => $request->attributes->has('builderad.identity_document')
                ? app(IdentityDocument::class)->metadata($request) : null),
            'locale' => app()->getLocale(),
            'clientVisibility' => fn () => app(ClientVisibility::class)->describe($request),
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
                'canManageCommercialPreparation' => fn () => $request->is('internal/*') && $request->user() &&
                    app(GrantAuthority::class)->canManageCommercialPreparation($request),
                'canManageCustomers' => fn () => $request->is('internal/*') && $request->user() &&
                    DB::table('internal_personnel as p')
                        ->join('principal_lifecycles as l', 'l.user_id', '=', 'p.user_id')
                        ->where('p.user_id', $request->user()->id)->where('p.kind', 'founder')->where('p.status', 'active')
                        ->where('l.principal_class', 'staff')->where('l.status', 'current')
                        ->where('l.generation', $request->session()->get('a001_generation'))->exists(),
            ],
        ];
    }
}
