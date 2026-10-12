<?php

namespace App\Http\Middleware;

use App\Security\ExpiredSecuritySession;
use App\Security\IdentityCookies;
use App\Security\IdentityDocument;
use App\Security\IdentityLifecycle;
use App\Security\IdentityTransitionConflict;
use App\Security\SessionAuthority;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Candidate integration: register after CSRF, before route/controller effects. */
final class AdmitIdentityDocument
{
    public function __construct(private IdentityDocument $documents) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->attributes->has('builderad.identity_cookies')) {
            throw new \LogicException('Identity input must be captured before CSRF/admission.');
        }
        $receipt = (string) ($request->header('X-Builderad-Document') ?? $request->input('_identity_receipt', ''));
        try {
            if ($request->isMethod('GET') && $request->is('identity/context', 'internal/identity/context', 'identity/transition', 'internal/identity/transition')) {
                // Context routes separately validate the actual current credential.
                return $next($request);
            }
            if ($receipt !== '') {
                $this->documents->admit($request, $receipt);
            } elseif ($this->nativeDocument($request)) {
                $this->documents->fresh($request);
                if (! $request->user()) {
                    $request->attributes->set('builderad.identity_cookie_operation', 'anonymous');
                }
            } else {
                throw new IdentityTransitionConflict;
            }
        } catch (IdentityTransitionConflict|AuthorizationException $error) {
            if ($error instanceof AuthorizationException && $request->user()) {
                try {
                    app(IdentityLifecycle::class)->expire($request);
                } catch (IdentityTransitionConflict) {
                    // Superseded input cannot retire the newer lineage owner.
                    // A canonical eligibility denial is not an expired session:
                    // retirement rechecks the actual deadlines under its lock.
                    if (! $error instanceof ExpiredSecuritySession) {
                        throw $error;
                    }
                }
            }
            if ($this->nativeDocument($request) && $request->is('login', 'internal/login')) {
                $this->documents->reauthenticate($request);
                $request->attributes->set('builderad.identity_cookie_operation', 'anonymous');

                return $next($request);
            }
            if ($this->nativeDocument($request)) {
                return redirect($this->documents->boundary($request) === 'internal' ? '/internal/login' : '/login');
            }

            return response()->json(['message' => 'Identity context changed.', 'identityConflict' => true,
                'context' => $this->documents->boundary($request) === 'internal' ? '/internal/identity/context' : '/identity/context'], 409)
                ->header('Cache-Control', 'no-store');
        }

        $response = $next($request);
        $state = $request->attributes->get('builderad.identity_lifecycle');
        $operation = $request->attributes->get('builderad.identity_cookie_operation') ?? $state?->operation;
        if ($operation === 'terminal' || $state?->failed || $state?->uncertain || $state?->replayed) {
            return $response;
        }
        $captured = $request->attributes->get('builderad.identity_cookies');
        if ($operation === null && ($captured['credential'] !== SessionAuthority::sessionKey($request)
            || $captured['csrf'] !== $request->session()->token())) {
            IdentityCookies::own($request, 'failed');

            return response()->json(['message' => 'Identity lifecycle requires recovery.', 'identityConflict' => true,
                'context' => $this->documents->boundary($request) === 'internal' ? '/internal/identity/context' : '/identity/context'], 409)
                ->header('Cache-Control', 'no-store');
        }
        if ($request->attributes->has('builderad.identity_document')) {
            // Immutable input document ownership, including partial/deferred
            // responses; never derive this from a later server identity.
            $response->headers->set('X-Builderad-Identity', json_encode($this->documents->metadata($request), JSON_THROW_ON_ERROR));
        }

        return $response;
    }

    private function nativeDocument(Request $request): bool
    {
        return $request->isMethod('GET') && ! $request->headers->has('X-Inertia')
            && ! $request->ajax() && ($request->header('Sec-Fetch-Dest') === 'document'
                || str_contains((string) $request->header('Accept'), 'text/html'));
    }
}
