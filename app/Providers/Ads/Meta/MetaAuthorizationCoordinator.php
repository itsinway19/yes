<?php

namespace App\Providers\Ads\Meta;

use App\Providers\Ads\NativeProgress;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderAccountDiscovery;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use App\Security\SessionAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Server orchestration only. Routes must provide signed Workspace transactions and explicit source grants. */
final readonly class MetaAuthorizationCoordinator
{
    public function __construct(private Factory $http, private MetaAccessAdapter $configuration,
        private MetaOAuthState $states, private MetaPendingAccess $pending,
        private ProviderRequestGuard $requests, private ProviderAccessStore $custody, private ?string $noticeBinding = null) {}

    public function configurationBinding(): string
    {
        return $this->noticeBinding === null ? $this->configuration->configurationBinding()
            : hash('sha256', $this->configuration->configurationBinding().':'.$this->noticeBinding);
    }

    public function authorizationUrl(Request $request, ProviderScope $installation, int $accessVersion = 0): string
    {
        $this->installation($request, $installation);
        $state = $this->states->issue($request, $installation, $accessVersion, $this->configurationBinding());

        return $this->configuration->authorizationUrl($state);
    }

    /** Returns only an opaque pending handle. Native credentials remain encrypted and server-side. */
    public function callback(Request $request, ProviderScope $installation, int $accessVersion,
        #[\SensitiveParameter] string $state, #[\SensitiveParameter] string $code): string
    {
        $this->installation($request, $installation);
        $this->states->consume($request, $installation, $accessVersion, $this->configurationBinding(), $state);
        $adapter = $this->adapter($request, $installation, $accessVersion);
        $material = $adapter->acquire($installation, new ProviderCredentials(['code' => $code]), '{}');

        return $this->pending->stage($request, $material, $accessVersion, $this->configurationBinding());
    }

    /** Continuation is internal encrypted state, never a browser-supplied URL/cursor or durable acceptance marker. */
    public function discover(Request $request, ProviderScope $installation, int $accessVersion,
        #[\SensitiveParameter] string $handle, ?NativeProgress $continuation = null): ProviderAccountDiscovery
    {
        $this->installation($request, $installation);
        if ($continuation !== null && ! in_array($continuation->kind, ['meta.discovery_cursor', 'meta.granted_asset_enumeration'], true)) {
            throw new InvalidArgumentException('Unsupported Meta discovery continuation.');
        }
        $material = $this->pending->material($request, $installation, $accessVersion, $this->configurationBinding(), $handle);

        return $this->adapter($request, $installation, $accessVersion)->discover($installation,
            $material->credentials, $continuation?->envelope()['payload'] ?? '{}');
    }

    /** The selected AdAccount is a separate explicitly granted BB1 identity, not a renamed Login configuration. */
    public function select(Request $request, ProviderScope $installation, int $installationVersion,
        #[\SensitiveParameter] string $handle, ProviderScope $selected, string $command, int $selectedAccessVersion): array
    {
        $this->installation($request, $installation);
        if ($selected->provider !== Provider::Meta || $selected->workspaceId !== $installation->workspaceId
            || $selected->sourceId === $installation->sourceId || $selected->operation !== 'report.read'
            || $selected->purpose !== $installation->purpose) {
            throw new InvalidArgumentException('Selected Meta account requires its own exact Workspace/source/report scope.');
        }
        DB::selectOne('SELECT builderad_source_read_scope(?, ?, ?::uuid, ?, ?, ?)', [$selected->workspaceId,
            SessionAuthority::sessionKey($request), $selected->sourceId, $selected->accountId, $selected->operation, $selected->purpose]);
        if (DB::table('source_registry')->where('id', $selected->sourceId)->value('namespace') !== 'ad_account') {
            throw new AuthorizationException('Selection must bind an explicitly declared AdAccount source.');
        }
        $previous = DB::table('provider_access_receipts')->where('workspace_id', $selected->workspaceId)
            ->where('source_id', $selected->sourceId)->where('provider', 'meta')->where('resource', $selected->accountId)
            ->where('operation', $selected->operation)->where('purpose', $selected->purpose)->where('command_id', $command)->value('result');
        if ($previous !== null) {
            $receipt = json_decode($previous, true, flags: JSON_THROW_ON_ERROR);
            if (($receipt['version'] ?? null) !== $selectedAccessVersion + 1 || ($receipt['local_state'] ?? null) !== 'CREDENTIAL_STORED') {
                throw new AuthorizationException('Meta selection receipt does not match this command.');
            }
            // Repair a lost response without requiring already-discarded staging or repeating native exchange.
            $this->requests->requireCurrent($request, $selected, 'provider.connect', $receipt['version']);

            return $receipt;
        }
        $pending = $this->pending->material($request, $installation, $installationVersion, $this->configurationBinding(), $handle);
        $adapter = $this->adapter($request, $selected, $selectedAccessVersion);
        $verified = $adapter->verifySelection($selected, $pending->credentials, $selected->accountId);
        $receipt = $this->custody->store($request, $selected, $command, $selectedAccessVersion,
            $verified->credentials, $verified->expiresAt, $verified->nativeState, $verified->verification,
            reconnect: $selectedAccessVersion > 0);
        $this->pending->discard($request, $installation, $installationVersion, $this->configurationBinding(), $handle);

        return $receipt;
    }

    private function adapter(Request $request, ProviderScope $scope, int $accessVersion): MetaAccessAdapter
    {
        return $this->configuration->forAuthority($this->http, $this->requests->bind($request, $scope, 'provider.connect', $accessVersion));
    }

    private function installation(Request $request, ProviderScope $scope): void
    {
        if ($scope->provider !== Provider::Meta || $scope->accountId !== $this->configuration->configurationId()
            || $scope->operation !== 'discovery.read' || $scope->purpose !== 'advertising_analysis') {
            throw new InvalidArgumentException('Meta discovery must bind its exact configured Login installation.');
        }
        DB::selectOne('SELECT builderad_source_read_scope(?, ?, ?::uuid, ?, ?, ?)', [$scope->workspaceId,
            SessionAuthority::sessionKey($request), $scope->sourceId, $scope->accountId, $scope->operation, $scope->purpose]);
        if (DB::table('source_registry')->where('id', $scope->sourceId)->value('namespace') !== 'facebook_login_configuration') {
            throw new AuthorizationException('Meta Login requires an explicitly declared configuration source.');
        }
    }
}
