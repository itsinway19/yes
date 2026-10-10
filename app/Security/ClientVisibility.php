<?php

namespace App\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Presentation binding only: the page's existing guards still authorize every probe. */
final class ClientVisibility
{
    public function describe(Request $request): array
    {
        $path = $request->getRequestUri();
        if (! $request->user()) {
            return ['protected' => false, 'path' => $path, 'context' => null];
        }

        $generation = (int) $request->session()->get('a001_generation', 0);
        $grants = DB::table('security_action_grants')
            ->where('subject_user_id', $request->user()->getAuthIdentifier())
            ->where('subject_generation', $generation)->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('id')->get(['id', 'version', 'action', 'scope_kind', 'workspace_id', 'expires_at']);

        return ['protected' => true, 'path' => $path, 'context' => hash_hmac('sha256', json_encode([
            $path, $request->user()->getAuthIdentifier(), $request->session()->getId(),
            $generation, $request->is('internal/*'), $grants,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'))];
    }
}
