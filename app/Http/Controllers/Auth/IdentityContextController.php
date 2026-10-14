<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Security\IdentityDocument;
use App\Security\IdentityReceipt;
use App\Security\IdentityTransition;
use App\Security\IdentityTransitionConflict;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IdentityContextController extends Controller
{
    public function show(Request $request, IdentityDocument $documents): JsonResponse
    {
        $destination = $documents->boundary($request) === 'internal' ? '/internal/login' : '/login';
        if (! $request->user()) {
            return response()->json(['reauthenticate' => $destination], 401)->header('Cache-Control', 'no-store');
        }
        try {
            $metadata = $documents->fresh($request);
        } catch (IdentityTransitionConflict|AuthorizationException) {
            return response()->json(['reauthenticate' => $destination], 401)->header('Cache-Control', 'no-store');
        }

        return response()->json(['identity' => $metadata])->header('Cache-Control', 'no-store');
    }

    public function renew(Request $request, IdentityDocument $documents): JsonResponse
    {
        if (! $request->user() || ! $request->attributes->has('builderad.identity_document')) {
            throw new IdentityTransitionConflict;
        }
        $request->attributes->set('builderad.identity_cookie_operation', 'renewal');

        return response()->json(['identity' => $documents->metadata($request)])
            ->header('Cache-Control', 'no-store');
    }

    public function resolve(Request $request, IdentityReceipt $receipts, IdentityDocument $documents, IdentityTransition $transitions): JsonResponse
    {
        $binding = $receipts->read((string) $request->header('X-Builderad-Transition'), 'transition')['binding'];
        if ($binding['boundary'] !== $documents->boundary($request)) {
            throw new IdentityTransitionConflict;
        }

        // Evidence of completion only. No credentials, grants, authentication
        // replay or private page payload are returned from nonce resolution.
        return response()->json(['transition' => $transitions->resolve(
            $binding['lineage'], $binding['boundary'], $binding['nonce'],
        )])->header('Cache-Control', 'no-store');
    }

    public function rebind(Request $request, IdentityDocument $documents, IdentityTransition $transitions): JsonResponse
    {
        [$captured, $locked] = $documents->transition($request, (string) $request->header('X-Builderad-Transition'));
        $result = $transitions->rebind($request, $captured['lineage'], $captured['boundary'], $captured['epoch'], $captured['nonce'], $locked);
        if ($request->attributes->get('builderad.identity_replayed')) {
            throw new IdentityTransitionConflict;
        }
        $request->attributes->set('builderad.identity_cookie_operation', 'transition');

        return response()->json(['transition' => $result, 'identity' => $documents->fresh($request)])
            ->header('Cache-Control', 'no-store');
    }
}
