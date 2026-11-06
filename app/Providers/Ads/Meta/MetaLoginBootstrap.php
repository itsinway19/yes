<?php

namespace App\Providers\Ads\Meta;

use App\Models\Workspace;
use App\Providers\Ads\NativeSourceOwner;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use App\Sources\SourceRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Explicit Connect-button request over a real server-configured Login configuration, before account discovery. */
final readonly class MetaLoginBootstrap
{
    public function __construct(private GrantAuthority $grants, private SourceRegistry $sources,
        private ProviderRequestGuard $requests) {}

    /** Source/command IDs are server-issued retry identities; configuration ID comes from server settings. */
    public function requestConnection(Request $request, Workspace|NativeSourceOwner $workspace, string $configurationId,
        string $sourceId, string $commandId): ProviderScope
    {
        if (! preg_match('/^[1-9][0-9]{0,39}$/D', $configurationId) || ! Str::isUuid($commandId)) {
            throw new InvalidArgumentException('A real configured Meta Login configuration is required.');
        }
        if ($workspace instanceof NativeSourceOwner) {
            if ($workspace->businessId !== null) {
                $scope = $workspace->scope($sourceId, Provider::Meta, $configurationId, 'discovery.read', 'advertising_analysis');
                $workspace->bootstrap($request, $scope, $commandId, 'facebook_login_configuration', 'Meta advertiser connection');
                $this->requests->requireCurrent($request, $scope, 'provider.connect', 0);

                return $scope;
            }
            $workspace = $workspace->workspace();
        }
        // BB1 accepts a declared native namespace/resource reference. This is explicitly a configuration,
        // never a placeholder AdAccount. The scope's resource slot carries that exact configuration ID.
        $scope = new ProviderScope($workspace->id, $sourceId, Provider::Meta, $configurationId,
            'discovery.read', 'advertising_analysis');
        DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$workspace->id, SessionAuthority::sessionKey($request)]);
        $existing = DB::table('source_registry')->where('id', $sourceId)->first();
        if ($existing !== null && ((int) $existing->workspace_id !== $workspace->id || $existing->family !== 'meta'
            || $existing->namespace !== 'facebook_login_configuration' || $existing->declared_external_id !== $configurationId
            || $existing->status === 'REVOKED')) {
            throw new AuthorizationException('Current exact Meta Login configuration source required.');
        }
        foreach (['source.register', 'source.read', 'provider.connect'] as $action) {
            // A003 is still the sole writer; each exact grant requires this fresh responsible-owner request.
            $this->grants->requestSourceGrant($request, $workspace->id, ['source_id' => $scope->sourceId,
                'resource' => $configurationId, 'operation' => $scope->operation, 'purpose' => $scope->purpose,
                'action' => $action, 'decision' => 'authorize', 'expires_at' => null, 'reason' => 'explicit_meta_connect']);
        }
        if ($existing === null) {
            $this->sources->command($request, $workspace, ['source_id' => $scope->sourceId, 'command_id' => $commandId,
                'effect' => 'register', 'family' => 'meta', 'namespace' => 'facebook_login_configuration', 'external_id' => $configurationId,
                'label' => 'Meta advertiser connection', 'resource' => $configurationId, 'operation' => $scope->operation,
                'purpose' => $scope->purpose, 'expected_version' => 0, 'reason' => 'explicit_meta_connect']);
        }
        $this->requests->requireCurrent($request, $scope, 'provider.connect', 0);

        return $scope;
    }
}
