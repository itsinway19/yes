<?php

namespace App\Providers\Ads;

use App\Security\SessionAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Restores the captured locator; each worker still qualifies its actual Source action. */
final class NativeSourceQueueContext
{
    public static function capture(Request $request, ProviderScope $scope): string
    {
        $reference = NativeSourceOwner::referenceFor($request, $scope);

        return $reference[$scope->businessId === null ? 'workspace' : 'business'];
    }

    public static function restore(Request $request, ProviderScope $scope, string $publicId, int $generation): void
    {
        if ((int) DB::table('principal_lifecycles')->where('user_id', $request->user()->id)->value('generation') !== $generation) {
            throw new AuthorizationException('Queued native actor generation changed.');
        }
        if ($scope->businessId !== null) {
            $owner = json_decode(DB::scalar('SELECT builderad_v2_business_source_owner(?,?::uuid)',
                [SessionAuthority::sessionKey($request), $publicId]), true, flags: JSON_THROW_ON_ERROR);
            if ($owner['business_id'] !== $scope->businessId || $owner['business_public_id'] !== $publicId) {
                throw new AuthorizationException('Queued Business custody changed.');
            }
        } else {
            DB::selectOne('SELECT builderad_select_workspace_context(?)', [$scope->workspaceId]);
            if (DB::table('organization_workspaces')->where('id', $scope->workspaceId)->value('public_id') !== $publicId) {
                throw new AuthorizationException('Queued organizational custody changed.');
            }
        }
    }
}
