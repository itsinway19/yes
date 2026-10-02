<?php

namespace App\Http\Controllers;

use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\Workspace;
use App\Providers\Ads\NativeProgress;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\Snapchat\SnapchatAccessAdapter;
use App\Providers\Ads\Snapchat\SnapchatAccessStatus;
use App\Providers\Ads\Snapchat\SnapchatAuthorizationCoordinator;
use App\Providers\Ads\Snapchat\SnapchatClient;
use App\Providers\Ads\Snapchat\SnapchatFlowState;
use App\Providers\Ads\Snapchat\SnapchatOAuthClient;
use App\Providers\Ads\Snapchat\SnapchatOAuthState;
use App\Providers\Ads\Snapchat\SnapchatPendingAccess;
use App\Providers\Ads\Snapchat\SnapchatRequestFailed;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use App\Sources\SourceRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Explicit fresh-owner connection and selection; discovery alone never creates sources or grants. */
final class SnapchatConnectionController extends Controller
{
    public function index(Request $request, string $workspace, WorkspaceContext $context, Factory $http): JsonResponse
    {
        return $this->respond(fn () => $context->run($request->user(), $workspace, function (Workspace $w) use ($request, $http): array {
            DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$w->id, SessionAuthority::sessionKey($request)]);
            $connections = DB::table('source_registry as s')->join('source_capabilities as c', 'c.source_id', '=', 's.id')
                ->where('s.workspace_id', $w->id)->where('s.family', 'snapchat')->where('s.namespace', 'ad_account')
                ->where('c.operation', 'report.read')->where('c.purpose', 'advertising_analysis')
                ->get(['s.id', 's.label', 's.status', 'c.resource'])->map(fn ($s) => [
                    'source_id' => $s->id, 'label' => $s->label, 'declared_account_id' => $s->resource, 'registration' => $s->status,
                    'access' => (new SnapchatAccessStatus)->snapshot($request,
                        new ProviderScope($w->id, $s->id, Provider::Snapchat, $s->resource, 'report.read', 'advertising_analysis'))])->all();
            try {
                [$coordinator, , $flows] = $this->configured($http);
            } catch (HttpException $error) {
                if ($error->getStatusCode() !== 503) {
                    throw $error;
                }

                return ['connections' => $connections, 'availability' => 'CONFIGURATION_REQUIRED', 'connect_intent' => null, 'notice' => null];
            }
            $owner = DB::table('workspace_ownerships')->where('workspace_id', $w->id)
                ->where('responsible_owner_user_id', $request->user()->id)->exists();
            $fresh = app(SessionAuthority::class)->hasFreshAuthentication($request, $request->user());
            $intent = null;
            if ($owner && $fresh) {
                DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$w->id, SessionAuthority::sessionKey($request)]);
                $existing = DB::table('source_registry')->where('workspace_id', $w->id)->where('family', 'snapchat')
                    ->where('namespace', 'developer_app')->where('declared_external_id', config('services.snapchat_ads.client_id'))->value('id');
                $scope = new ProviderScope($w->id, $existing ?? (string) Str::uuid7(), Provider::Snapchat,
                    (string) config('services.snapchat_ads.client_id'), 'discovery.read', 'advertising_analysis');
                $intent = $flows->issueConnectIntent($request, $scope, $coordinator->configurationBinding(),
                    ['workspace' => $w->public_id, 'command' => (string) Str::uuid(), 'register' => $existing === null]);
            }

            return ['connections' => $connections, 'availability' => ! $owner ? 'OWNER_REQUIRED' : (! $fresh ? 'FRESH_AUTHENTICATION_REQUIRED' : 'CONNECT_AVAILABLE'),
                'connect_intent' => $intent, 'notice' => ['privacy_url' => config('services.snapchat_ads.privacy_url'),
                    'revision' => config('services.snapchat_ads.notice_revision')]];
        }, $request));
    }

    public function connect(Request $request, string $workspace, WorkspaceContext $context, Factory $http): JsonResponse
    {
        if ($request->has('intent') && ($request->has('source_id') || $request->has('expected_version'))) {
            throw ValidationException::withMessages(['connection' => 'Choose one connection request format.']);
        }
        $input = $this->input($request, ['intent' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D'],
            'source_id' => ['required_without:intent', 'uuid'],
            'expected_version' => ['required_without:intent', 'integer', 'min:0']]);

        return $this->respond(fn () => $context->run($request->user(), $workspace, function (Workspace $w) use ($request, $http, $input): array {
            [$coordinator, , $flows] = $this->configured($http);
            $version = $input['expected_version'] ?? 0;
            if (isset($input['intent'])) {
                $intent = $flows->loadConnectIntent($request, $coordinator->configurationBinding(), $input['intent']);
                $this->sameWorkspace($w, $intent);
                $scope = $intent['scope'];
                if ($scope->accountId !== config('services.snapchat_ads.client_id') || $scope->operation !== 'discovery.read') {
                    abort(403);
                }
                $this->authorizeSource($request, $w, $scope, $intent['data']['register'], $intent['data']['command'], 'developer_app');
            } else {
                $scope = $this->declared($request, $w, $input['source_id'], 'developer_app', 'discovery.read');
            }

            return ['authorization_url' => $coordinator->authorizationUrl($request, $scope, $version)];
        }, $request));
    }

    public function callback(Request $request, WorkspaceContext $context, Factory $http): JsonResponse|RedirectResponse
    {
        $callbackInput = $request->attributes->get('bb2.callback_input');
        $request->attributes->remove('bb2.callback_input');
        if (! $callbackInput instanceof ParameterBag) {
            abort(400, 'Invalid provider callback ingress.');
        }

        $code = $callbackInput->get('code');
        $state = $callbackInput->get('state');
        $cancelled = $callbackInput->has('error');
        // Scrub all callback input before validation, exceptions, redirects or session flashing.
        $request->query->replace([]);
        $request->server->set('QUERY_STRING', '');
        $request->server->set('REQUEST_URI', $request->getPathInfo());
        if (! is_string($state) || ! preg_match('/^[A-Za-z0-9_-]{43}$/D', $state)
            || (! $cancelled && (! is_string($code) || $code === '' || strlen($code) > 4096 || preg_match('/[\x00-\x20\x7f]/', $code)))) {
            abort(400, 'Invalid Snapchat callback.');
        }
        $returnWorkspace = null;
        $response = $this->respond(function () use ($request, $context, $http, $code, $state, $cancelled, &$returnWorkspace): array {
            [$coordinator, $states, $flows] = $this->configured($http);
            $route = $states->locate($request, $coordinator->configurationBinding(), $state);
            $returnWorkspace = $route['workspace'];

            return $context->run($request->user(), $route['workspace'], function (Workspace $w) use ($request, $coordinator, $states, $flows, $route, $code, $state, $cancelled): array {
                if ($route['scope']->workspaceId !== $w->id) {
                    abort(403);
                }
                if ($cancelled) {
                    $states->consume($request, $route['scope'], $route['access_version'], $coordinator->configurationBinding(), $state);

                    return ['workspace' => $w->public_id, 'status' => 'AUTHORIZATION_NOT_COMPLETED'];
                }
                $pending = $coordinator->callback($request, $route['scope'], $route['access_version'], $state, $code);
                $handle = $flows->issue($request, $route['scope'], $route['access_version'], $coordinator->configurationBinding(), 'discovery',
                    ['workspace' => $w->public_id, 'pending' => $pending, 'cursor' => null]);

                return ['workspace' => $w->public_id, 'status' => 'ACCOUNT_SELECTION_REQUIRED', 'discovery_handle' => $handle];
            }, $request);
        });
        if (! $request->expectsJson() && $response->getStatusCode() === 502 && $returnWorkspace !== null) {
            $status = 'PROVIDER_ACTION_REQUIRED';

            return redirect()->to(route('workspaces.show', $returnWorkspace).'#snapchat-result='.$status)
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }
        if ($request->expectsJson() || $response->getStatusCode() !== 200) {
            return $response;
        }
        $data = $response->getData(true);
        $fragment = isset($data['discovery_handle']) ? 'snapchat-flow='.$data['discovery_handle'] : 'snapchat-result=AUTHORIZATION_NOT_COMPLETED';

        return redirect()->to(route('workspaces.show', $data['workspace']).'#'.$fragment)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function discovery(Request $request, string $workspace, string $flow, WorkspaceContext $context, Factory $http): JsonResponse
    {
        return $this->respond(fn () => $context->run($request->user(), $workspace, function (Workspace $w) use ($request, $flow, $http): array {
            [$coordinator, , $flows] = $this->configured($http);
            $record = $flows->load($request, $coordinator->configurationBinding(), 'discovery', $flow);
            $this->sameWorkspace($w, $record);
            $cursor = $record['data']['cursor'];
            $progress = $cursor === null ? null : new NativeProgress($cursor['kind'], $cursor['basis'], $cursor['payload']);
            $page = $coordinator->discover($request, $record['scope'], $record['access_version'], $record['data']['pending'], $progress);
            $choices = $this->choices($request, $w, $record, $coordinator, $flows, $page->accounts);
            $next = $page->continuation === null ? null : $flows->issue($request, $record['scope'], $record['access_version'],
                $coordinator->configurationBinding(), 'discovery', ['workspace' => $w->public_id, 'pending' => $record['data']['pending'],
                    'cursor' => $page->continuation->envelope()]);

            return ['coverage' => $page->coverage, 'accounts' => $choices, 'continuation' => $next,
                'selection_requires_existing_source' => false];
        }, $request));
    }

    public function select(Request $request, string $workspace, WorkspaceContext $context, Factory $http): JsonResponse
    {
        $input = $this->input($request, ['selection' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D']]);

        return $this->respond(fn () => $context->run($request->user(), $workspace, function (Workspace $w) use ($request, $input, $http): array {
            [$coordinator, , $flows] = $this->configured($http);
            $record = $flows->load($request, $coordinator->configurationBinding(), 'selection', $input['selection']);
            $this->sameWorkspace($w, $record);
            $d = $record['data'];
            $candidate = new ProviderScope($w->id, $d['source_id'], Provider::Snapchat, $d['account_id'], 'report.read', 'advertising_analysis');
            $this->authorizeSource($request, $w, $candidate, $d['register'], $d['command'], 'ad_account');
            $selected = $this->declared($request, $w, $d['source_id'], 'ad_account', 'report.read');
            if ($selected->accountId !== $d['account_id']) {
                abort(403);
            }
            $receipt = $coordinator->select($request, $record['scope'], $record['access_version'], $d['pending'], $selected,
                $d['command'], $d['access_version']);

            return ['source_id' => $selected->sourceId, 'receipt' => $receipt, 'status' => 'NATIVE_CONNECTION_OBSERVED',
                'access' => (new SnapchatAccessStatus)->snapshot($request, $selected)];
        }, $request));
    }

    public function renew(Request $request, string $workspace, string $source, WorkspaceContext $context, Factory $http): JsonResponse
    {
        $input = $this->input($request, ['command_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1']]);

        return $this->respond(fn () => $context->run($request->user(), $workspace, function (Workspace $w) use ($request, $source, $input, $http): array {
            [$coordinator] = $this->configured($http);
            $scope = $this->declared($request, $w, $source, 'ad_account', 'report.read');
            app(GrantAuthority::class)->requestSourceGrant($request, $w->id, ['source_id' => $scope->sourceId,
                'resource' => $scope->accountId, 'operation' => $scope->operation, 'purpose' => $scope->purpose,
                'action' => 'provider.connect', 'decision' => 'authorize', 'expires_at' => null, 'reason' => 'explicit_snapchat_renewal']);
            $receipt = $coordinator->renew($request, $scope, $input['command_id'], (int) $input['expected_version']);
            if (isset($receipt['failure'])) {
                return ['status' => 'PROVIDER_ACTION_REQUIRED', 'reason' => $receipt['failure'],
                    'source_id' => $source, 'access' => (new SnapchatAccessStatus)->snapshot($request, $scope)];
            }

            return ['source_id' => $source, 'receipt' => $receipt, 'access' => (new SnapchatAccessStatus)->snapshot($request, $scope)];
        }, $request));
    }

    public function disconnect(Request $request, string $workspace, string $source, WorkspaceContext $context): JsonResponse
    {
        $input = $this->input($request, ['command_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1']]);

        return $this->respond(fn () => $context->run($request->user(), $workspace, function (Workspace $w) use ($request, $source, $input): array {
            // Local removal must remain available without working OAuth configuration or provider transport.
            $scope = $this->declared($request, $w, $source, 'ad_account', 'report.read');
            app(GrantAuthority::class)->requestSourceGrant($request, $w->id, ['source_id' => $scope->sourceId,
                'resource' => $scope->accountId, 'operation' => $scope->operation, 'purpose' => $scope->purpose,
                'action' => 'provider.revoke', 'decision' => 'authorize', 'expires_at' => null, 'reason' => 'explicit_snapchat_local_disconnect']);
            $receipt = app(ProviderAccessStore::class)->revoke($request, $scope, $input['command_id'], (int) $input['expected_version']);

            return ['source_id' => $source, 'receipt' => $receipt, 'access' => (new SnapchatAccessStatus)->snapshot($request, $scope),
                'remote_authorization_revoked' => false];
        }, $request));
    }

    private function choices(Request $request, Workspace $w, array $record, SnapchatAuthorizationCoordinator $coordinator,
        SnapchatFlowState $flows, array $accounts): array
    {
        $choices = [];
        foreach ($accounts as $account) {
            // RLS-visible existing declared sources only; native discovery cannot manufacture access.
            $targets = DB::table('source_registry as s')->join('source_capabilities as c', 'c.source_id', '=', 's.id')
                ->where('s.workspace_id', $w->id)->where('s.family', 'snapchat')->where('s.namespace', 'ad_account')
                ->where('s.declared_external_id', $account['id'])->where('c.resource', $account['id'])
                ->where('c.operation', 'report.read')->where('c.purpose', 'advertising_analysis')->get(['s.id', 's.label']);
            foreach ($targets as $target) {
                $version = (int) (DB::table('provider_access')->where('workspace_id', $w->id)->where('source_id', $target->id)
                    ->where('provider', 'snapchat')->where('resource', $account['id'])->where('operation', 'report.read')
                    ->where('purpose', 'advertising_analysis')->value('version') ?? 0);
                $handle = $flows->issue($request, $record['scope'], $record['access_version'], $coordinator->configurationBinding(), 'selection',
                    ['workspace' => $w->public_id, 'pending' => $record['data']['pending'], 'source_id' => $target->id,
                        'account_id' => $account['id'], 'access_version' => $version, 'command' => (string) Str::uuid(), 'register' => false]);
                $choices[] = ['account_id' => $account['id'], 'label' => $account['label'],
                    'existing_source_label' => $target->label, 'selection_handle' => $handle];
            }
            if ($targets->isEmpty()) {
                $handle = $flows->issue($request, $record['scope'], $record['access_version'], $coordinator->configurationBinding(), 'selection',
                    ['workspace' => $w->public_id, 'pending' => $record['data']['pending'], 'source_id' => (string) Str::uuid7(),
                        'account_id' => $account['id'], 'access_version' => 0, 'command' => (string) Str::uuid(), 'register' => true]);
                $choices[] = ['account_id' => $account['id'], 'label' => $account['label'], 'existing_source_label' => null, 'selection_handle' => $handle];
            }
        }

        return $choices;
    }

    private function authorizeSource(Request $request, Workspace $workspace, ProviderScope $scope, bool $register,
        string $command, string $namespace): void
    {
        $actions = $register ? ['source.register', 'source.read', 'provider.connect'] : ['source.read', 'provider.connect'];
        if ($scope->operation === 'report.read') {
            $actions[] = 'provider.read';
        }
        foreach ($actions as $action) {
            app(GrantAuthority::class)->requestSourceGrant($request, $workspace->id, ['source_id' => $scope->sourceId,
                'resource' => $scope->accountId, 'operation' => $scope->operation, 'purpose' => $scope->purpose,
                'action' => $action, 'decision' => 'authorize', 'expires_at' => null, 'reason' => 'explicit_snapchat_connection']);
        }
        if ($register) {
            app(SourceRegistry::class)->command($request, $workspace, ['source_id' => $scope->sourceId, 'command_id' => $command,
                'effect' => 'register', 'family' => 'snapchat', 'namespace' => $namespace, 'external_id' => $scope->accountId,
                'label' => $namespace === 'developer_app' ? 'Snapchat advertiser connection' : 'Snapchat Ads '.$scope->accountId,
                'resource' => $scope->accountId, 'operation' => $scope->operation, 'purpose' => $scope->purpose,
                'expected_version' => 0, 'reason' => 'explicit_snapchat_connection']);
        }
    }

    private function configured(Factory $http): array
    {
        try {
            $redirect = (string) config('services.snapchat_ads.redirect_uri');
            $privacy = (string) config('services.snapchat_ads.privacy_url');
            $revision = (string) config('services.snapchat_ads.notice_revision');
            if (filter_var($privacy, FILTER_VALIDATE_URL) === false || parse_url($privacy, PHP_URL_SCHEME) !== 'https'
                || parse_url($privacy, PHP_URL_USER) !== null || parse_url($privacy, PHP_URL_PASS) !== null
                || ! preg_match('/^[a-zA-Z0-9_.:-]{1,80}$/D', $revision)) {
                throw new LogicException('Configured operator privacy notice required.');
            }
            if ($redirect !== rtrim((string) config('app.url'), '/').route('providers.snapchat.callback', absolute: false)) {
                throw new LogicException('Exact configured callback required.');
            }
            $unbound = static fn () => throw new LogicException('Unbound Snapchat configuration cannot send HTTP.');
            $adapter = new SnapchatAccessAdapter(new SnapchatOAuthClient($http, (string) config('services.snapchat_ads.client_id'),
                (string) config('services.snapchat_ads.client_secret'), $redirect, $unbound), new SnapchatClient($http, $unbound, 'provider.connect'));
            $cache = Cache::store('redis');
            if (app()->runningUnitTests()) {
                $cache = Cache::store();
            }
            $requests = app(ProviderRequestGuard::class);
            $states = new SnapchatOAuthState($cache, $requests);
            $pending = new SnapchatPendingAccess($cache, $requests);

            // Link syntax and revision are correlation only; they never approve the privacy/purpose axis.
            $noticeBinding = hash('sha256', json_encode([$privacy, $revision], JSON_THROW_ON_ERROR));

            return [new SnapchatAuthorizationCoordinator($http, $adapter, $states, $pending, $requests, app(ProviderAccessStore::class), $noticeBinding),
                $states, new SnapchatFlowState($cache, $requests)];
        } catch (InvalidArgumentException|LogicException) {
            abort(503, 'Snapchat connection is not configured.');
        }
    }

    private function declared(Request $request, Workspace $workspace, string $source, string $namespace, string $operation): ProviderScope
    {
        DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$workspace->id, SessionAuthority::sessionKey($request)]);
        $identity = DB::table('source_registry')->where('workspace_id', $workspace->id)->where('id', $source)
            ->where('family', 'snapchat')->where('namespace', $namespace)->first();
        if (! $identity) {
            abort(404);
        }

        return new ProviderScope($workspace->id, $source, Provider::Snapchat, $identity->declared_external_id, $operation, 'advertising_analysis');
    }

    private function sameWorkspace(Workspace $workspace, array $record): void
    {
        if ($record['scope']->workspaceId !== $workspace->id || $record['data']['workspace'] !== $workspace->public_id) {
            abort(403);
        }
    }

    private function input(Request $request, array $rules): array
    {
        if (array_diff(array_keys($request->except('_token')), array_keys($rules))) {
            throw ValidationException::withMessages(['connection' => 'Unknown connection fields.']);
        }

        return $request->validate($rules);
    }

    private function respond(callable $action): JsonResponse
    {
        try {
            $data = $action();
        } catch (SnapchatRequestFailed $error) {
            return response()->json(['status' => 'PROVIDER_ACTION_REQUIRED', 'reason' => $error->reason], 502)
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        } catch (QueryException $error) {
            if ($error->getCode() === '42501') {
                abort(403);
            }
            if ($error->getCode() === '23505') {
                abort(409, 'Snapchat connection command conflict.');
            }
            throw $error;
        }

        return response()->json($data, ($data['status'] ?? null) === 'PROVIDER_ACTION_REQUIRED' ? 502 : 200)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
