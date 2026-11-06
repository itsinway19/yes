<?php

namespace App\Providers\Ads\X;

use App\Providers\Ads\NativeProgress;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderAccountDiscovery;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use App\Security\SessionAuthority;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Internal acquire/callback boundary. Callback returns only opaque encrypted staging correlation. */
final readonly class XAuthorizationCoordinator
{
    public function __construct(private Factory $http, private XOAuthClient $configuration,
        private XOAuthState $states, private ProviderRequestGuard $requests, private XPendingAccess $pending,
        private ProviderAccessStore $custody) {}

    public function configurationBinding(): string
    {
        return $this->configuration->configurationBinding();
    }

    public function authorizationUrl(Request $request, ProviderScope $installation, int $version = 0): string
    {
        $client = $this->client($request, $installation, $version);
        $temporary = $client->requestToken($this->budget($installation));
        $this->states->issue($request, $installation, $version, $this->configurationBinding(), $temporary);

        return $client->authorizationUrl($temporary);
    }

    public function callback(Request $request, ProviderScope $installation, int $version,
        #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $verifier): string
    {
        $client = $this->client($request, $installation, $version);
        $temporary = $this->states->consume($request, $installation, $version, $this->configurationBinding(), $token);

        $credentials = $client->exchange($temporary, $verifier, $this->budget($installation));

        return $this->pending->stage($request, $installation, $version, $this->configurationBinding(), $credentials);
    }

    private function client(Request $request, ProviderScope $scope, int $version): XOAuthClient
    {
        if ($scope->provider !== Provider::X || $scope->accountId !== $this->configuration->consumerKey
            || $scope->operation !== 'discovery.read' || $scope->purpose !== 'advertising_analysis') {
            throw new InvalidArgumentException('Exact X application installation required.');
        }

        return $this->configuration->forAuthority($this->http, $this->requests->bind($request, $scope, 'provider.connect', $version));
    }

    /** Cursor continuation is internal only and must remain in encrypted server-side flow custody. */
    public function discover(Request $request, ProviderScope $installation, int $version,
        #[\SensitiveParameter] string $handle, ?NativeProgress $continuation = null): ProviderAccountDiscovery
    {
        $client = $this->client($request, $installation, $version);
        $credentials = $this->pending->credentials($request, $installation, $version, $this->configurationBinding(), $handle);
        $budget = new ProviderReadBudget($installation, 'x-account-discovery', 1, 0, 1, 200, new DateTimeImmutable('+30 seconds'), 1, 5);

        return $client->accountDiscovery()->page($budget, $credentials, $continuation);
    }

    private function budget(ProviderScope $scope): ProviderReadBudget
    {
        return new ProviderReadBudget($scope, 'x-oauth', 1, 0, 1, 1, new DateTimeImmutable('+30 seconds'), 1, 5);
    }

    /** Browser-safe account evidence: neither native cursors nor acquisition credentials leave the server. */
    public function discoverPage(Request $request, ProviderScope $installation, int $version,
        #[\SensitiveParameter] string $pending, #[\SensitiveParameter] ?string $continuation = null): array
    {
        $this->client($request, $installation, $version);
        $store = new XDiscoveryContinuation(app()->runningUnitTests() ? Cache::store() : Cache::store('redis'), $this->requests);
        $native = $continuation === null ? null : $store->restore($request, $installation, $version,
            $this->configurationBinding(), $pending, $continuation);
        $page = $this->discover($request, $installation, $version, $pending, $native);
        $next = $page->continuation === null ? null : $store->stage($request, $installation, $version,
            $this->configurationBinding(), $pending, $page->continuation);

        return ['accounts' => $page->accounts, 'basis' => $page->basis, 'coverage' => $page->coverage, 'continuation' => $next];
    }

    /** Selection needs an independently registered/granted report source; discovery never creates it. */
    public function select(Request $request, ProviderScope $installation, int $installationVersion,
        #[\SensitiveParameter] string $handle, ProviderScope $selected, string $command, int $selectedVersion): array
    {
        $this->client($request, $installation, $installationVersion);
        if ($selected->provider !== Provider::X || ! $selected->sameCustodyAs($installation)
            || $selected->sourceId === $installation->sourceId || $selected->operation !== 'report.read'
            || $selected->purpose !== $installation->purpose) {
            throw new InvalidArgumentException('Selected X account requires its own exact Workspace/source/report scope.');
        }
        DB::selectOne('SELECT builderad_source_read_scope(?, ?, ?::uuid, ?, ?, ?)', [$selected->workspaceId,
            SessionAuthority::sessionKey($request), $selected->sourceId, $selected->accountId, $selected->operation, $selected->purpose]);
        if (DB::table('source_registry')->where('id', $selected->sourceId)->value('namespace') !== 'ad_account') {
            throw new AuthorizationException('X selection must use an explicitly registered advertising account.');
        }
        $previous = DB::table('provider_access_receipts')->where('workspace_id', $selected->workspaceId)->where('source_id', $selected->sourceId)
            ->where('provider', 'x')->where('resource', $selected->accountId)->where('operation', $selected->operation)
            ->where('purpose', $selected->purpose)->where('command_id', $command)->value('result');
        if ($previous !== null) {
            $receipt = json_decode($previous, true, flags: JSON_THROW_ON_ERROR);
            if (($receipt['version'] ?? null) !== $selectedVersion + 1 || ($receipt['local_state'] ?? null) !== 'CREDENTIAL_STORED') {
                throw new AuthorizationException('X selection receipt does not match this command.');
            }
            $this->requests->requireCurrent($request, $selected, 'provider.connect', $receipt['version']);

            return $receipt;
        }
        $credentials = $this->pending->credentials($request, $installation, $installationVersion, $this->configurationBinding(), $handle);
        $verifier = $this->configuration->forAuthority($this->http, $this->requests->bind($request, $selected, 'provider.connect', $selectedVersion))->accountSelection();
        $budget = new ProviderReadBudget($selected, 'x-account-selection', 2, 0, 2, 2, new DateTimeImmutable('+30 seconds'), 1, 5);
        $material = $verifier->verify($budget, $credentials);
        $receipt = $this->custody->store($request, $selected, $command, $selectedVersion, $material->credentials,
            $material->expiresAt, $material->nativeState, $material->verification, reconnect: $selectedVersion > 0);
        $this->pending->discard($request, $installation, $installationVersion, $this->configurationBinding(), $handle);

        return $receipt;
    }
}
