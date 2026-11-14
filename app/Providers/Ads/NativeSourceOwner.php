<?php

namespace App\Providers\Ads;

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
        public int $businessId,
        public string $publicId,
        private int $creatorBasis,
    ) {}

    public static function forBusiness(int $id, string $publicId, int $creatorBasis): self
    {
        if ($id < 1 || $creatorBasis < 1 || ! Str::isUuid($publicId)) {
            throw new LogicException('A typed Business Source owner is required.');
        }

        return new self($id, $publicId, $creatorBasis);
    }

    public function selection(): array
    {
        return ['realm_kind' => 'direct',
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $this->creatorBasis]]];
    }

    public function scope(string $source, Provider $provider, string $resource, string $operation, string $purpose): ProviderScope
    {
        return ProviderScope::forBusiness($this->businessId, $source, $provider, $resource, $operation, $purpose);
    }

    /** Source RLS remains in force; this only selects the exact current owner. */
    public function sources(?string $alias = null): Builder
    {
        if ($alias !== null && $alias !== 's') {
            throw new LogicException('A fixed Source query alias is required.');
        }
        $prefix = $alias === null ? '' : $alias.'.';

        return DB::table($alias === null ? 'source_registry' : 'source_registry as '.$alias)
            ->where($prefix.'custody_kind', 'business_native')
            ->where($prefix.'business_id', $this->businessId);
    }

    /** Explicit Business routing identity. */
    public function reference(): array
    {
        return ['custody_kind' => 'business_native', 'business' => $this->publicId, 'access' => $this->creatorBasis];
    }

    public function canManage(Request $request): bool
    {
        return true;
    }

    /** Uses the canonical SQL Source writer and the approved creator qualification. */
    public function bootstrap(Request $request, ProviderScope $scope, string $command, string $namespace, string $label): void
    {
        if ($scope->businessId !== $this->businessId) {
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
        if (($reference['custody_kind'] ?? null) !== 'business_native' || ! Str::isUuid($reference['business'] ?? '') || array_key_exists('workspace', $reference)) {
            throw new AuthorizationException('Exact Business routing state required.');
        }

        return url('/businesses/'.$reference['business'].'?'.http_build_query([
            'access' => $reference['access'] ?? null, 'section' => 'sources',
        ]));
    }

    public function assertBound(array $record): void
    {
        $scope = $record['scope'] ?? null;
        $data = $record['data'] ?? $record;
        if (! $scope instanceof ProviderScope || $scope->businessId !== $this->businessId
            || ($data['custody_kind'] ?? null) !== 'business_native' || ($data['business'] ?? null) !== $this->publicId || array_key_exists('workspace', $data)) {
            throw new AuthorizationException('Native connection state does not belong to this resource.');
        }
    }

    public static function referenceFor(Request $request, ProviderScope $scope): array
    {
        $owner = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (! $owner instanceof self || $owner->businessId !== $scope->businessId) {
            throw new AuthorizationException('Admitted Business connection routing is required.');
        }

        return $owner->reference();
    }

    public static function referenceFrom(array $record, ProviderScope $scope): array
    {
        if (($record['custody_kind'] ?? null) !== 'business_native' || ! Str::isUuid($record['business'] ?? '') || array_key_exists('workspace', $record)) {
            throw new AuthorizationException('Exact Business routing state required.');
        }

        return ['custody_kind' => 'business_native', 'business' => $record['business']];
    }
}
