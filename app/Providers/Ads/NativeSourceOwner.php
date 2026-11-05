<?php

namespace App\Providers\Ads;

use App\Models\Workspace;
use App\Security\GrantAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Typed resource identity. Construction and query selection grant no authority. */
final readonly class NativeSourceOwner
{
    public const REQUEST_ATTRIBUTE = 'builderad.native_source_owner';

    private function __construct(
        public ?int $workspaceId,
        public ?int $businessId,
        public string $publicId,
        private ?int $creatorBasis,
        private ?Workspace $workspace,
    ) {}

    public static function fromWorkspace(Workspace $workspace): self
    {
        if (! $workspace->exists || ! is_int($workspace->id) || $workspace->id < 1 || ! Str::isUuid($workspace->public_id)) {
            throw new LogicException('A persisted organizational Source owner is required.');
        }

        return new self($workspace->id, null, $workspace->public_id, null, $workspace);
    }

    public static function forBusiness(int $id, string $publicId, int $creatorBasis): self
    {
        if ($id < 1 || $creatorBasis < 1 || ! Str::isUuid($publicId)) {
            throw new LogicException('A typed Business Source owner is required.');
        }

        return new self(null, $id, $publicId, $creatorBasis, null);
    }

    public function workspace(): Workspace
    {
        return $this->workspace ?? throw new LogicException('Business custody has no Workspace.');
    }

    public function selection(): array
    {
        if ($this->creatorBasis === null) {
            throw new LogicException('Organizational Source authority is independently qualified.');
        }

        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $this->creatorBasis]]];
    }

    public function scope(string $source, Provider $provider, string $resource, string $operation, string $purpose): ProviderScope
    {
        return $this->businessId === null
            ? new ProviderScope($this->workspaceId, $source, $provider, $resource, $operation, $purpose)
            : ProviderScope::forBusiness($this->businessId, $source, $provider, $resource, $operation, $purpose);
    }

    /** Source RLS remains in force; this only selects the exact current owner. */
    public function sources(?string $alias = null): Builder
    {
        if ($alias !== null && $alias !== 's') {
            throw new LogicException('A fixed Source query alias is required.');
        }
        $prefix = $alias === null ? '' : $alias.'.';
        $query = DB::table($alias === null ? 'source_registry' : 'source_registry as '.$alias)
            ->where($prefix.'workspace_id', $this->workspaceId)
            ->where($prefix.'custody_kind', $this->businessId === null ? 'workspace_native' : 'business_native');
        if ($this->businessId !== null) {
            $query->where($prefix.'business_id', $this->businessId);
        }

        return $query;
    }

    /** Preserve the existing Workspace wire; Business routing is explicit. */
    public function reference(): array
    {
        return $this->businessId === null ? ['workspace' => $this->publicId]
            : ['custody_kind' => 'business_native', 'business' => $this->publicId, 'access' => $this->creatorBasis];
    }

    public function canManage(Request $request): bool
    {
        return $this->businessId !== null || DB::table('workspace_ownerships')->where('workspace_id', $this->workspaceId)
            ->where('responsible_owner_user_id', $request->user()->id)->exists();
    }

    /** Uses the canonical SQL Source writer and the approved creator qualification. */
    public function bootstrap(Request $request, ProviderScope $scope, string $command, string $namespace, string $label): void
    {
        if ($this->businessId === null || $scope->businessId !== $this->businessId || $scope->workspaceId !== null) {
            throw new LogicException('Exact Business-native Source bootstrap required.');
        }
        app(GrantAuthority::class)->bootstrapBusinessSource($request, $this->publicId, $scope->sourceId, $command, [
            'family' => $scope->provider->value, 'namespace' => $namespace, 'external_id' => $scope->accountId,
            'label' => $label, 'resource' => $scope->accountId, 'operation' => $scope->operation, 'purpose' => $scope->purpose,
            'expected_version' => 0, 'reason' => 'explicit_'.$scope->provider->value.'_connection',
        ], $this->selection());
    }

    public static function landingUrl(array $reference): string
    {
        return ($reference['custody_kind'] ?? null) === 'business_native'
            ? url('/businesses/'.$reference['business'].'?'.http_build_query([
                // A routing selector only: BusinessSurface independently requalifies it.
                'access' => $reference['access'] ?? null, 'section' => 'sources',
            ]))
            : route('workspaces.show', $reference['workspace']);
    }

    public function assertBound(array $record): void
    {
        $scope = $record['scope'] ?? null;
        $data = $record['data'] ?? $record;
        if (! $scope instanceof ProviderScope || $scope->workspaceId !== $this->workspaceId || $scope->businessId !== $this->businessId
            || ($this->businessId === null
                ? (($data['workspace'] ?? null) !== $this->publicId || array_key_exists('business', $data))
                : (($data['custody_kind'] ?? null) !== 'business_native' || ($data['business'] ?? null) !== $this->publicId || array_key_exists('workspace', $data)))) {
            throw new AuthorizationException('Native connection state does not belong to this resource.');
        }
    }

    public static function referenceFor(Request $request, ProviderScope $scope): array
    {
        if ($scope->businessId === null) {
            $publicId = DB::table('organization_workspaces')->where('id', $scope->workspaceId)->value('public_id');
            if (! is_string($publicId) || ! Str::isUuid($publicId)) {
                throw new AuthorizationException('Organizational connection routing is unavailable.');
            }

            return ['workspace' => $publicId];
        }
        $owner = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (! $owner instanceof self || $owner->businessId !== $scope->businessId || $owner->workspaceId !== null) {
            throw new AuthorizationException('Admitted Business connection routing is required.');
        }

        return $owner->reference();
    }

    public static function referenceFrom(array $record, ProviderScope $scope): array
    {
        if ($scope->businessId === null) {
            if (! Str::isUuid($record['workspace'] ?? '') || array_key_exists('business', $record) || array_key_exists('custody_kind', $record)) {
                throw new AuthorizationException('Exact organizational routing state required.');
            }

            return ['workspace' => $record['workspace']];
        }
        if (($record['custody_kind'] ?? null) !== 'business_native' || ! Str::isUuid($record['business'] ?? '') || array_key_exists('workspace', $record)) {
            throw new AuthorizationException('Exact Business routing state required.');
        }

        return ['custody_kind' => 'business_native', 'business' => $record['business']];
    }
}
