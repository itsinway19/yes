<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use LogicException;

/** Renewal is a read-only session consumer; other lifecycles retain Laravel saves. */
class StartIdentitySession extends StartSession
{
    public function __construct(SessionManager $manager, ?callable $cacheFactoryResolver = null)
    {
        parent::__construct($manager, $cacheFactoryResolver ?? fn () => app(Factory::class));
    }

    protected function handleStatefulRequest($request, $session, Closure $next)
    {
        return parent::handleStatefulRequest($request, $session, function ($request) use ($next) {
            $request->attributes->set('builderad.renewal_backend', 1);
            if ($request->isMethod('POST') && $request->is('identity/renew', 'internal/identity/renew')) {
                $request->attributes->set('builderad.renewal_input', [
                    'sid' => $request->session()->getId(), 'attributes' => $request->session()->all(),
                ]);
            }

            return $next($request);
        });
    }

    protected function saveSession($request)
    {
        $input = $request->attributes->get('builderad.renewal_input');
        $state = $request->attributes->get('builderad.identity_lifecycle');
        $operation = $request->attributes->get('builderad.identity_cookie_operation') ?? $state?->operation;
        if (! $input || $operation === 'terminal') {
            return parent::saveSession($request);
        }
        $changed = $input['sid'] !== $request->session()->getId() || $input['attributes'] !== $request->session()->all();
        if ($changed && ($state?->committed || $state?->failed || $state?->uncertain)) {
            // Expiration owns its explicit transition, including failed/unknown
            // outcomes. Preserve its persistence; this is not a renewal fallback.
            return parent::saveSession($request);
        }
        if ($changed) {
            // Do not silently discard a newly introduced security mutation or
            // fall back to publishing an obsolete full snapshot.
            throw new LogicException('A renewal request must not mutate its session.');
        }
        $request->attributes->set('builderad.renewal_readonly', true);
    }
}
