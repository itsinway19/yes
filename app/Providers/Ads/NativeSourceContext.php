<?php

namespace App\Providers\Ads;

use App\Foundation\Tenancy\PrincipalContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Models\Workspace;
use App\Security\SessionAuthority;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Reuses the actual Business or Workspace admission; never creates an owner. */
final readonly class NativeSourceContext
{
    public function __construct(private PrincipalContext $principals, private WorkspaceContext $workspaces) {}

    public function run(User $actor, string $publicId, Closure $callback, Request $request): mixed
    {
        return $this->execute($actor, $publicId, $request->route()?->hasParameter('business') === true, $callback, $request);
    }

    public function runForReference(Request $request, array $reference, Closure $callback): mixed
    {
        $scope = $reference['scope'] ?? null;
        if (! $scope instanceof ProviderScope || ! $request->user()) {
            throw new AuthorizationException('Typed native callback routing required.');
        }
        $route = NativeSourceOwner::referenceFrom($reference, $scope);
        $business = $scope->businessId !== null;

        return $this->execute($request->user(), $route[$business ? 'business' : 'workspace'], $business,
            function (NativeSourceOwner $owner) use ($reference, $callback): mixed {
                $owner->assertBound($reference);

                return $callback($owner);
            }, $request);
    }

    private function execute(User $actor, string $publicId, bool $business, Closure $callback, Request $request): mixed
    {
        $invoke = function (NativeSourceOwner $owner) use ($request, $callback): mixed {
            if ($request->attributes->has(NativeSourceOwner::REQUEST_ATTRIBUTE)) {
                throw new LogicException('Native connection admission cannot be nested.');
            }
            $request->attributes->set(NativeSourceOwner::REQUEST_ATTRIBUTE, $owner);
            try {
                return $callback($owner);
            } finally {
                $request->attributes->remove(NativeSourceOwner::REQUEST_ATTRIBUTE);
            }
        };
        if (! $business) {
            return $this->workspaces->run($actor, $publicId, fn (Workspace $workspace) => $invoke(NativeSourceOwner::fromWorkspace($workspace)), $request);
        }
        if (DB::connection()->transactionLevel() !== 0 || ! $actor->exists || ! Str::isUuid($publicId)
            || $request->user()?->getAuthIdentifier() !== $actor->id) {
            throw new AuthorizationException('A clean exact Business Source context is required.');
        }

        return DB::transaction(function () use ($actor, $publicId, $request, $invoke): mixed {
            $this->principals->activate($actor);
            $owner = json_decode(DB::scalar('SELECT builderad_v2_business_source_owner(?,?::uuid)',
                [SessionAuthority::sessionKey($request), $publicId]), true, flags: JSON_THROW_ON_ERROR);

            return $invoke(NativeSourceOwner::forBusiness($owner['business_id'], $owner['business_public_id'], $owner['read_grant_ref']));
        }, 1);
    }
}
