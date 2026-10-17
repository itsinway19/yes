<?php

namespace App\Security;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Runs outside StartSession, queued-cookie delivery and encryption. */
final class IdentityCookies
{
    public function capture(Request $request, string $recaller): void
    {
        // Laravel FormRequest copies attribute bags. A shared request-local
        // object carries publication ownership back to the outer cookie layer.
        $request->attributes->set('builderad.identity_lifecycle', (object) [
            'operation' => null, 'committed' => null, 'output' => null,
            'failed' => false, 'uncertain' => false, 'replayed' => false,
        ]);
        $request->attributes->set('builderad.identity_cookies', [
            'names' => [(string) config('session.cookie'), 'XSRF-TOKEN', $recaller, (string) config('session.cookie').'-lineage'],
            'path' => (string) config('session.path'), 'domain' => (string) config('session.domain'),
            'credential' => SessionAuthority::sessionKey($request),
            'epoch' => $request->session()->get('builderad.identity_epoch'),
            'csrf' => $request->session()->token(),
        ]);
    }

    public static function own(Request $request, string $operation): void
    {
        $request->attributes->set('builderad.identity_cookie_operation', $operation);
        if ($state = $request->attributes->get('builderad.identity_lifecycle')) {
            $state->operation = $operation;
        }
    }

    public function finish(Request $request, Response $response): Response
    {
        $captured = $request->attributes->get('builderad.identity_cookies');
        if (! is_array($captured)) {
            throw new \LogicException('Cookie ownership must be captured after session loading.');
        }
        $state = $request->attributes->get('builderad.identity_lifecycle');
        $operation = $request->attributes->get('builderad.identity_cookie_operation') ?? $state?->operation;
        $committed = $request->attributes->get('builderad.identity_committed') ?? $state?->committed;
        $owned = $request->attributes->get('builderad.identity_output') ?? $state?->output;
        $healthy = ! ($request->attributes->get('builderad.identity_failed') || $state?->failed)
            && ! ($request->attributes->get('builderad.identity_uncertain') || $state?->uncertain)
            && ! ($request->attributes->get('builderad.identity_replayed') || $state?->replayed)
            && $response->getStatusCode() < 400;
        if ($healthy && $operation === 'renewal') {
            try {
                app(IdentityRenewal::class)->refresh($request);
                if (! $response instanceof JsonResponse) {
                    throw new \LogicException('Renewal must return its publication metadata as JSON.');
                }
                $metadata = app(IdentityDocument::class)->metadata($request);
                $response->setData(['identity' => $metadata]);
                $response->headers->set('X-Builderad-Identity', json_encode($metadata, JSON_THROW_ON_ERROR));
            } catch (Throwable $error) {
                $conflict = $error instanceof IdentityTransitionConflict || $error instanceof AuthorizationException;
                $response->setStatusCode($conflict ? 409 : 503);
                $response->setContent(json_encode(['identityConflict' => $conflict, 'message' => 'Identity renewal requires recovery.'], JSON_THROW_ON_ERROR));
                $response->headers->remove('X-Builderad-Identity');
                $response->headers->set('Cache-Control', 'no-store');
                if (! $conflict) {
                    report($error);
                }
                $healthy = false;
            }
        }
        $issue = $healthy && (
            ($operation === 'transition' && is_array($committed) && $committed['status'] === 'active'
                && is_array($owned) && $owned['credential'] === SessionAuthority::sessionKey($request)
                && $owned['epoch'] === $request->session()->get('builderad.identity_epoch')
                && $owned['lineage'] === $request->session()->get('builderad.identity_lineage')
                && is_int($owned['cookie_deadline'] ?? null) && $owned['cookie_deadline'] > time()
                && $owned['cookie_deadline'] === $request->session()->get('builderad.identity_cookie_deadline')
                && $owned['csrf'] === $request->session()->token())
            || ($operation === 'anonymous' && ! $request->user())
        );
        foreach ($response->headers->getCookies() as $cookie) {
            if (! in_array($cookie->getName(), $captured['names'], true)
                || $cookie->getPath() !== $captured['path']
                || (string) $cookie->getDomain() !== $captured['domain']) {
                continue;
            }
            $remember = $cookie->getName() === $captured['names'][2];
            $selector = $cookie->getName() === $captured['names'][3];
            if (! $issue || ($remember && ($operation !== 'transition' || ($committed['kind'] ?? null) !== 'login'))
                || ($selector && ($operation !== 'anonymous' || ! $request->attributes->get('builderad.identity_selector_created')))) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            } elseif ($operation === 'transition' && ! $remember && ! $selector && $cookie->getExpiresTime() !== 0) {
                // Renewal publishes no cookies at all. An older completed
                // renewal therefore cannot overwrite a later login in any tab.
                // Preserve session cookies (expire-on-close) and all other flags.
                $response->headers->setCookie($cookie->withExpires($owned['cookie_deadline']));
            }
        }

        return $response;
    }
}
