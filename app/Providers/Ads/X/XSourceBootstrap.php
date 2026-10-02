<?php

namespace App\Providers\Ads\X;

use App\Models\Workspace;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use App\Security\GrantAuthority;
use App\Sources\SourceRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Explicit fresh-owner intent only. Public callers must use server-issued, context-bound identities. */
final readonly class XSourceBootstrap
{
    public function __construct(private GrantAuthority $grants, private SourceRegistry $sources, private ProviderRequestGuard $requests) {}

    public function requestConnection(Request $request, Workspace $workspace, string $consumerKey, string $sourceId, string $command): ProviderScope
    {
        if ($consumerKey === '' || strlen($consumerKey) > 180 || preg_match('/[\x00-\x20\x7f]/', $consumerKey)) {
            throw new InvalidArgumentException('Exact configured X application key required.');
        }
        $scope = new ProviderScope($workspace->id, $sourceId, Provider::X, $consumerKey, 'discovery.read', 'advertising_analysis');
        $this->register($request, $workspace, $scope, $command, 'developer_app', ['source.register', 'source.read', 'provider.connect']);
        $this->requests->requireCurrent($request, $scope, 'provider.connect', 0);

        return $scope;
    }

    public function requestAccount(Request $request, Workspace $workspace, ProviderScope $selected, string $command): void
    {
        if ($selected->workspaceId !== $workspace->id || $selected->provider !== Provider::X
            || $selected->operation !== 'report.read' || $selected->purpose !== 'advertising_analysis'
            || ! preg_match('/^[a-zA-Z0-9]{1,180}$/D', $selected->accountId)) {
            throw new InvalidArgumentException('Exact selected X advertising account required.');
        }
        $this->register($request, $workspace, $selected, $command, 'ad_account', ['source.register', 'source.read', 'provider.connect', 'provider.read']);
        $this->requests->requireCurrent($request, $selected, 'provider.connect', 0);
    }

    private function register(Request $request, Workspace $workspace, ProviderScope $scope, string $command, string $namespace, array $actions): void
    {
        if (! Str::isUuid($command)) {
            throw new InvalidArgumentException('Server-issued X bootstrap command required.');
        }
        foreach ($actions as $action) {
            $this->grants->requestSourceGrant($request, $workspace->id, ['source_id' => $scope->sourceId,
                'resource' => $scope->accountId, 'operation' => $scope->operation, 'purpose' => $scope->purpose,
                'action' => $action, 'decision' => 'authorize', 'expires_at' => null, 'reason' => 'explicit_x_connection']);
        }
        $this->sources->command($request, $workspace, ['source_id' => $scope->sourceId, 'command_id' => $command,
            'effect' => 'register', 'family' => 'x', 'namespace' => $namespace, 'external_id' => $scope->accountId,
            'label' => $namespace === 'developer_app' ? 'X advertiser connection' : 'X Ads '.$scope->accountId,
            'resource' => $scope->accountId, 'operation' => $scope->operation, 'purpose' => $scope->purpose,
            'expected_version' => 0, 'reason' => 'explicit_x_connection']);
    }
}
