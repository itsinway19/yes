<?php

namespace App\Providers\Ads\Snapchat;

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
final readonly class SnapchatAuthorizationCoordinator
{
    public function __construct(private Factory $http, private SnapchatAccessAdapter $configuration,
        private SnapchatOAuthState $states, private SnapchatPendingAccess $pending,
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
        if ($continuation !== null && ! in_array($continuation->kind, ['snapchat.embedded_accounts'], true)) {
            throw new InvalidArgumentException('Unsupported Snapchat discovery continuation.');
        }
        $material = $this->pending->material($request, $installation, $accessVersion, $this->configurationBinding(), $handle);

        return $this->adapter($request, $installation, $accessVersion)->discover($installation,
            $material->credentials, $continuation?->envelope()['payload'] ?? '{}');
    }

    /** Selected Snapchat advertiser has its own explicitly granted BB1 report identity. */
    public function select(Request $request, ProviderScope $installation, int $installationVersion,
        #[\SensitiveParameter] string $handle, ProviderScope $selected, string $command, int $selectedAccessVersion): array
    {
        $this->installation($request, $installation);
        if ($selected->provider !== Provider::Snapchat || ! $selected->sameCustodyAs($installation)
            || $selected->sourceId === $installation->sourceId || $selected->operation !== 'report.read'
            || $selected->purpose !== $installation->purpose) {
            throw new InvalidArgumentException('Selected Snapchat account requires its own exact Workspace/source/report scope.');
        }
        DB::selectOne('SELECT builderad_source_read_scope(?, ?, ?::uuid, ?, ?, ?)', [$selected->workspaceId,
            SessionAuthority::sessionKey($request), $selected->sourceId, $selected->accountId, $selected->operation, $selected->purpose]);
        if (DB::table('source_registry')->where('id', $selected->sourceId)->value('namespace') !== 'ad_account') {
            throw new AuthorizationException('Selection must bind an explicitly declared advertising account source.');
        }
        $previous = DB::table('provider_access_receipts')->where('workspace_id', $selected->workspaceId)
            ->where('source_id', $selected->sourceId)->where('provider', 'snapchat')->where('resource', $selected->accountId)
            ->where('operation', $selected->operation)->where('purpose', $selected->purpose)->where('command_id', $command)->value('result');
        if ($previous !== null) {
            $receipt = json_decode($previous, true, flags: JSON_THROW_ON_ERROR);
            if (($receipt['version'] ?? null) !== $selectedAccessVersion + 1 || ($receipt['local_state'] ?? null) !== 'CREDENTIAL_STORED') {
                throw new AuthorizationException('Snapchat selection receipt does not match this command.');
            }
            // Repair a lost response without requiring already-discarded staging or repeating native exchange.
            $this->requests->requireCurrent($request, $selected, 'provider.connect', $receipt['version']);

            return $receipt;
        }
        $pending = $this->pending->material($request, $installation, $installationVersion, $this->configurationBinding(), $handle);
        $adapter = $this->adapter($request, $selected, $selectedAccessVersion);
        $verified = $adapter->verifySelection($selected, $pending->credentials,
            json_encode(['account_id' => $selected->accountId], JSON_THROW_ON_ERROR));
        $receipt = $this->custody->store($request, $selected, $command, $selectedAccessVersion,
            $verified->credentials, $verified->expiresAt, $verified->nativeState, $verified->verification,
            reconnect: $selectedAccessVersion > 0);
        $this->pending->discard($request, $installation, $installationVersion, $this->configurationBinding(), $handle);

        return $receipt;
    }

    public function renew(Request $request, ProviderScope $scope, string $command, int $version): array
    {
        if ($scope->provider !== Provider::Snapchat || $scope->operation !== 'report.read' || $scope->purpose !== 'advertising_analysis') {
            throw new InvalidArgumentException('Snapchat renewal requires its exact report source.');
        }
        $previous = DB::table('provider_access_receipts')->where('workspace_id', $scope->workspaceId)
            ->where('source_id', $scope->sourceId)->where('provider', 'snapchat')->where('resource', $scope->accountId)
            ->where('operation', $scope->operation)->where('purpose', $scope->purpose)->where('command_id', $command)->value('result');
        if ($previous !== null) {
            $receipt = json_decode($previous, true, flags: JSON_THROW_ON_ERROR);
            if (($receipt['version'] ?? null) !== $version + 1 || ($receipt['local_state'] ?? null) !== 'CREDENTIAL_STORED') {
                throw new AuthorizationException('Snapchat renewal command does not match.');
            }
            $this->requests->requireCurrent($request, $scope, 'provider.connect', $receipt['version']);

            return $receipt;
        }
        $credentials = $this->custody->snapchatConnectionCredentials($request, $scope, $version);
        $failure = DB::table('snapchat_renewal_failures')->where('workspace_id', $scope->workspaceId)
            ->where('source_id', $scope->sourceId)->where('resource', $scope->accountId)
            ->where('operation', $scope->operation)->where('purpose', $scope->purpose)->where('command_id', $command)->first();
        if ($failure !== null) {
            if ($failure->access_version !== $version) {
                throw new AuthorizationException('Snapchat renewal failure belongs to another access epoch.');
            }

            return ['failure' => $failure->failure];
        }
        // Adapter renewal rechecks the selected account with replacement tokens before returning CONNECTED.
        try {
            $renewed = $this->adapter($request, $scope, $version)->renew($scope, $credentials, '{}');
        } catch (SnapchatRequestFailed $error) {
            DB::selectOne('SELECT builderad_snapchat_renewal_failure(?, ?, ?::uuid, ?, ?, ?, ?, ?::uuid, ?)', [
                $scope->workspaceId, SessionAuthority::sessionKey($request), $scope->sourceId, $scope->accountId,
                $scope->operation, $scope->purpose, $version, $command, $error->reason,
            ]);

            // Return inside the owned transaction so immutable evidence commits before the 502 response.
            return ['failure' => $error->reason];
        }

        return $this->custody->store($request, $scope, $command, $version, $renewed->credentials,
            $renewed->expiresAt, $renewed->nativeState, $renewed->verification, reconnect: true);
    }

    private function adapter(Request $request, ProviderScope $scope, int $accessVersion): SnapchatAccessAdapter
    {
        return $this->configuration->forAuthority($this->http, $this->requests->bind($request, $scope, 'provider.connect', $accessVersion));
    }

    private function installation(Request $request, ProviderScope $scope): void
    {
        if ($scope->provider !== Provider::Snapchat || $scope->accountId !== $this->configuration->clientId()
            || $scope->operation !== 'discovery.read' || $scope->purpose !== 'advertising_analysis') {
            throw new InvalidArgumentException('Snapchat discovery must bind its exact configured OAuth client.');
        }
        DB::selectOne('SELECT builderad_source_read_scope(?, ?, ?::uuid, ?, ?, ?)', [$scope->workspaceId,
            SessionAuthority::sessionKey($request), $scope->sourceId, $scope->accountId, $scope->operation, $scope->purpose]);
        if (DB::table('source_registry')->where('id', $scope->sourceId)->value('namespace') !== 'developer_app') {
            throw new AuthorizationException('Snapchat OAuth requires an explicitly declared client source.');
        }
    }
}
