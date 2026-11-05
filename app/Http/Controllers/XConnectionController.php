<?php

namespace App\Http\Controllers;

use App\Providers\Ads\NativeSourceContext;
use App\Providers\Ads\NativeSourceOwner;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\X\XAccessStatus;
use App\Providers\Ads\X\XAuthorizationCoordinator;
use App\Providers\Ads\X\XFlowState;
use App\Providers\Ads\X\XOAuthClient;
use App\Providers\Ads\X\XOAuthState;
use App\Providers\Ads\X\XPendingAccess;
use App\Providers\Ads\X\XRequestFailed;
use App\Providers\Ads\X\XSourceBootstrap;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
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
use RuntimeException;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Explicit fresh-owner connection and selection; discovery alone never creates sources or grants. */
final class XConnectionController extends Controller
{
    public function index(Request $request, string $workspace, NativeSourceContext $context, Factory $http): JsonResponse
    {
        return $this->respond(fn () => $context->run($request->user(), $workspace, function (NativeSourceOwner $w) use ($request, $http): array {
            DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$w->workspaceId, SessionAuthority::sessionKey($request)]);
            $connections = $w->sources('s')->join('source_capabilities as c', 'c.source_id', '=', 's.id')
                ->where('s.family', 'x')->where('s.namespace', 'ad_account')
                ->where('c.operation', 'report.read')->where('c.purpose', 'advertising_analysis')
                ->get(['s.id', 's.label', 's.status', 'c.resource'])->map(fn ($s) => [
                    'source_id' => $s->id, 'label' => $s->label, 'declared_account_id' => $s->resource, 'registration' => $s->status,
                    'access' => (new XAccessStatus)->snapshot($request,
                        $w->scope($s->id, Provider::X, $s->resource, 'report.read', 'advertising_analysis'))])->all();
            try {
                [$coordinator, , $flows] = $this->configured($http);
            } catch (HttpException $error) {
                if ($error->getStatusCode() !== 503) {
                    throw $error;
                }

                return ['connections' => $connections, 'availability' => 'CONFIGURATION_REQUIRED', 'connect_intent' => null, 'notice' => null];
            }
            $owner = $w->canManage($request);
            $fresh = app(SessionAuthority::class)->hasFreshAuthentication($request, $request->user());
            $intent = null;
            if ($owner && $fresh) {
                DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$w->workspaceId, SessionAuthority::sessionKey($request)]);
                $existing = $w->sources()->where('family', 'x')
                    ->where('namespace', 'developer_app')->where('declared_external_id', config('services.x_ads.consumer_key'))->value('id');
                $scope = $w->scope($existing ?? (string) Str::uuid7(), Provider::X,
                    (string) config('services.x_ads.consumer_key'), 'discovery.read', 'advertising_analysis');
                $intent = $flows->issueConnectIntent($request, $scope, $coordinator->configurationBinding(),
                    [...$w->reference(), 'command' => (string) Str::uuid(), 'register' => $existing === null]);
            }

            return ['connections' => $connections, 'availability' => ! $owner ? 'OWNER_REQUIRED' : (! $fresh ? 'FRESH_AUTHENTICATION_REQUIRED' : 'CONNECT_AVAILABLE'),
                'connect_intent' => $intent, 'notice' => ['privacy_url' => config('services.x_ads.privacy_url'),
                    'revision' => config('services.x_ads.notice_revision')]];
        }, $request));
    }

    public function connect(Request $request, string $workspace, NativeSourceContext $context, Factory $http): JsonResponse
    {
        if ($request->has('intent') && ($request->has('source_id') || $request->has('expected_version'))) {
            throw ValidationException::withMessages(['connection' => 'Choose one connection request format.']);
        }
        $input = $this->input($request, ['intent' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D'],
            'source_id' => ['required_without:intent', 'uuid'],
            'expected_version' => ['required_without:intent', 'integer', 'min:0']]);

        return $this->respond(fn () => $context->run($request->user(), $workspace, function (NativeSourceOwner $w) use ($request, $http, $input): array {
            [$coordinator, , $flows] = $this->configured($http);
            $version = $input['expected_version'] ?? 0;
            if (isset($input['intent'])) {
                $intent = $flows->loadConnectIntent($request, $coordinator->configurationBinding(), $input['intent']);
                $this->sameOwner($w, $intent);
                $scope = $intent['scope'];
                if ($scope->accountId !== config('services.x_ads.consumer_key') || $scope->operation !== 'discovery.read') {
                    abort(403);
                }
                $this->authorizeSource($request, $w, $scope, $intent['data']['register'], $intent['data']['command'], 'developer_app');
            } else {
                $scope = $this->declared($request, $w, $input['source_id'], 'developer_app', 'discovery.read');
            }

            return ['authorization_url' => $coordinator->authorizationUrl($request, $scope, $version)];
        }, $request));
    }

    public function callback(Request $request, NativeSourceContext $context, Factory $http): JsonResponse|RedirectResponse
    {
        $callbackInput = $request->attributes->get('bb2.callback_input');
        $request->attributes->remove('bb2.callback_input');
        if (! $callbackInput instanceof ParameterBag) {
            abort(400, 'Invalid provider callback ingress.');
        }

        $cancelled = $callbackInput->has('denied');
        $state = $cancelled ? $callbackInput->get('denied') : $callbackInput->get('oauth_token');
        $code = $callbackInput->get('oauth_verifier');
        $conflicting = $cancelled && ($callbackInput->has('oauth_token') || $callbackInput->has('oauth_verifier'));
        // Scrub all callback input before validation, exceptions, redirects or session flashing.
        $request->query->replace([]);
        $request->server->set('QUERY_STRING', '');
        $request->server->set('REQUEST_URI', $request->getPathInfo());
        if ($conflicting || ! is_string($state) || $state === '' || strlen($state) > 8192 || preg_match('/[\x00-\x20\x7f]/', $state)
            || (! $cancelled && (! is_string($code) || $code === '' || strlen($code) > 8192 || preg_match('/[\x00-\x20\x7f]/', $code)))) {
            abort(400, 'Invalid X callback.');
        }
        $returnOwner = null;
        $response = $this->respond(function () use ($request, $context, $http, $code, $state, $cancelled, &$returnOwner): array {
            [$coordinator, $states, $flows] = $this->configured($http);
            $route = $states->locate($request, $coordinator->configurationBinding(), $state);
            $returnOwner = $route;

            return $context->runForReference($request, $route, function (NativeSourceOwner $w) use ($request, $coordinator, $states, $flows, $route, $code, $state, $cancelled): array {
                $w->assertBound($route);
                if ($cancelled) {
                    $states->consume($request, $route['scope'], $route['access_version'], $coordinator->configurationBinding(), $state);

                    return [...$w->reference(), 'status' => 'AUTHORIZATION_NOT_COMPLETED'];
                }
                $pending = $coordinator->callback($request, $route['scope'], $route['access_version'], $state, $code);
                $handle = $flows->issue($request, $route['scope'], $route['access_version'], $coordinator->configurationBinding(), 'discovery',
                    [...$w->reference(), 'pending' => $pending, 'continuation' => null]);

                return [...$w->reference(), 'status' => 'ACCOUNT_SELECTION_REQUIRED', 'discovery_handle' => $handle];
            });
        });
        if (! $request->expectsJson() && $response->getStatusCode() === 502 && $returnOwner !== null) {
            $status = 'PROVIDER_ACTION_REQUIRED';

            return redirect()->to(NativeSourceOwner::landingUrl($returnOwner).'#x-result='.$status)
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }
        if ($request->expectsJson() || $response->getStatusCode() !== 200) {
            return $response;
        }
        $data = $response->getData(true);
        $fragment = isset($data['discovery_handle']) ? 'x-flow='.$data['discovery_handle'] : 'x-result=AUTHORIZATION_NOT_COMPLETED';

        return redirect()->to(NativeSourceOwner::landingUrl($data).'#'.$fragment)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function discovery(Request $request, string $workspace, string $flow, NativeSourceContext $context, Factory $http): JsonResponse
    {
        return $this->respond(fn () => $context->run($request->user(), $workspace, function (NativeSourceOwner $w) use ($request, $flow, $http): array {
            [$coordinator, , $flows] = $this->configured($http);
            $record = $flows->load($request, $coordinator->configurationBinding(), 'discovery', $flow);
            $this->sameOwner($w, $record);
            $page = $coordinator->discoverPage($request, $record['scope'], $record['access_version'],
                $record['data']['pending'], $record['data']['continuation']);
            $choices = $this->choices($request, $w, $record, $coordinator, $flows, $page['accounts']);
            $next = $page['continuation'] === null ? null : $flows->issue($request, $record['scope'], $record['access_version'],
                $coordinator->configurationBinding(), 'discovery', [...$w->reference(), 'pending' => $record['data']['pending'],
                    'continuation' => $page['continuation']]);

            return ['coverage' => $page['coverage'], 'accounts' => $choices, 'continuation' => $next,
                'selection_requires_existing_source' => false];
        }, $request));
    }

    public function select(Request $request, string $workspace, NativeSourceContext $context, Factory $http): JsonResponse
    {
        $input = $this->input($request, ['selection' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D']]);

        return $this->respond(fn () => $context->run($request->user(), $workspace, function (NativeSourceOwner $w) use ($request, $input, $http): array {
            [$coordinator, , $flows] = $this->configured($http);
            $record = $flows->load($request, $coordinator->configurationBinding(), 'selection', $input['selection']);
            $this->sameOwner($w, $record);
            $d = $record['data'];
            $candidate = $w->scope($d['source_id'], Provider::X, $d['account_id'], 'report.read', 'advertising_analysis');
            $this->authorizeSource($request, $w, $candidate, $d['register'], $d['command'], 'ad_account');
            $selected = $this->declared($request, $w, $d['source_id'], 'ad_account', 'report.read');
            if ($selected->accountId !== $d['account_id']) {
                abort(403);
            }
            $receipt = $coordinator->select($request, $record['scope'], $record['access_version'], $d['pending'], $selected,
                $d['command'], $d['access_version']);

            return ['source_id' => $selected->sourceId, 'receipt' => $receipt, 'status' => 'NATIVE_CONNECTION_OBSERVED',
                'access' => (new XAccessStatus)->snapshot($request, $selected)];
        }, $request));
    }

    public function disconnect(Request $request, string $workspace, string $source, NativeSourceContext $context): JsonResponse
    {
        $input = $this->input($request, ['command_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1']]);

        return $this->respond(fn () => $context->run($request->user(), $workspace, function (NativeSourceOwner $w) use ($request, $source, $input): array {
            // Local removal must remain available without working OAuth configuration or provider transport.
            $scope = $this->declared($request, $w, $source, 'ad_account', 'report.read');
            if ($w->businessId === null) {
                app(GrantAuthority::class)->requestSourceGrant($request, $w->workspaceId, ['source_id' => $scope->sourceId,
                    'resource' => $scope->accountId, 'operation' => $scope->operation, 'purpose' => $scope->purpose,
                    'action' => 'provider.revoke', 'decision' => 'authorize', 'expires_at' => null, 'reason' => 'explicit_x_local_disconnect']);
            }
            $receipt = app(ProviderAccessStore::class)->revoke($request, $scope, $input['command_id'], (int) $input['expected_version']);

            return ['source_id' => $source, 'receipt' => $receipt, 'access' => (new XAccessStatus)->snapshot($request, $scope),
                'remote_authorization_revoked' => false];
        }, $request));
    }

    private function choices(Request $request, NativeSourceOwner $w, array $record, XAuthorizationCoordinator $coordinator,
        XFlowState $flows, array $accounts): array
    {
        $choices = [];
        foreach ($accounts as $account) {
            // RLS-visible existing declared sources only; native discovery cannot manufacture access.
            $targets = $w->sources('s')->join('source_capabilities as c', 'c.source_id', '=', 's.id')
                ->where('s.family', 'x')->where('s.namespace', 'ad_account')
                ->where('s.declared_external_id', $account['id'])->where('c.resource', $account['id'])
                ->where('c.operation', 'report.read')->where('c.purpose', 'advertising_analysis')->get(['s.id', 's.label']);
            foreach ($targets as $target) {
                $version = (int) (DB::table('provider_access')->where('workspace_id', $w->workspaceId)->where('source_id', $target->id)
                    ->where('provider', 'x')->where('resource', $account['id'])->where('operation', 'report.read')
                    ->where('purpose', 'advertising_analysis')->value('version') ?? 0);
                $handle = $flows->issue($request, $record['scope'], $record['access_version'], $coordinator->configurationBinding(), 'selection',
                    [...$w->reference(), 'pending' => $record['data']['pending'], 'source_id' => $target->id,
                        'account_id' => $account['id'], 'access_version' => $version, 'command' => (string) Str::uuid(), 'register' => false]);
                $choices[] = ['account_id' => $account['id'], 'label' => $account['label'],
                    'existing_source_label' => $target->label, 'selection_handle' => $handle];
            }
            if ($targets->isEmpty()) {
                $handle = $flows->issue($request, $record['scope'], $record['access_version'], $coordinator->configurationBinding(), 'selection',
                    [...$w->reference(), 'pending' => $record['data']['pending'], 'source_id' => (string) Str::uuid7(),
                        'account_id' => $account['id'], 'access_version' => 0, 'command' => (string) Str::uuid(), 'register' => true]);
                $choices[] = ['account_id' => $account['id'], 'label' => $account['label'], 'existing_source_label' => null, 'selection_handle' => $handle];
            }
        }

        return $choices;
    }

    private function authorizeSource(Request $request, NativeSourceOwner $workspace, ProviderScope $scope, bool $register,
        string $command, string $namespace): void
    {
        if ($workspace->businessId !== null) {
            $workspace->bootstrap($request, $scope, $command, $namespace, 'X '.$scope->accountId);

            return;
        }
        DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$workspace->workspaceId, SessionAuthority::sessionKey($request)]);
        $exists = $workspace->sources()->where('id', $scope->sourceId)
            ->where('family', 'x')->where('namespace', $namespace)->where('declared_external_id', $scope->accountId)->exists();
        if ($register && ! $exists) {
            $bootstrap = app(XSourceBootstrap::class);
            if ($namespace === 'developer_app') {
                $bootstrap->requestConnection($request, $workspace->workspace(), $scope->accountId, $scope->sourceId, $command);
            } else {
                $bootstrap->requestAccount($request, $workspace->workspace(), $scope, $command);
            }

            return;
        }
        $actions = ['source.read', 'provider.connect'];
        if ($scope->operation === 'report.read') {
            $actions[] = 'provider.read';
        }
        foreach ($actions as $action) {
            app(GrantAuthority::class)->requestSourceGrant($request, $workspace->workspaceId, ['source_id' => $scope->sourceId,
                'resource' => $scope->accountId, 'operation' => $scope->operation, 'purpose' => $scope->purpose,
                'action' => $action, 'decision' => 'authorize', 'expires_at' => null, 'reason' => 'explicit_x_connection']);
        }
    }

    private function configured(Factory $http): array
    {
        try {
            $redirect = (string) config('services.x_ads.redirect_uri');
            $privacy = (string) config('services.x_ads.privacy_url');
            $revision = (string) config('services.x_ads.notice_revision');
            if (filter_var($privacy, FILTER_VALIDATE_URL) === false || parse_url($privacy, PHP_URL_SCHEME) !== 'https'
                || parse_url($privacy, PHP_URL_USER) !== null || parse_url($privacy, PHP_URL_PASS) !== null
                || ! preg_match('/^[a-zA-Z0-9_.:-]{1,80}$/D', $revision)) {
                throw new LogicException('Configured operator privacy notice required.');
            }
            if ($redirect !== rtrim((string) config('app.url'), '/').route('providers.x.callback', absolute: false)) {
                throw new LogicException('Exact configured callback required.');
            }
            $unbound = static fn () => throw new LogicException('Unbound X configuration cannot send HTTP.');
            $configuration = new XOAuthClient($http, (string) config('services.x_ads.consumer_key'),
                (string) config('services.x_ads.consumer_secret'), $redirect, $unbound,
                hash('sha256', json_encode([$privacy, $revision], JSON_THROW_ON_ERROR)));
            $cache = Cache::store('redis');
            if (app()->runningUnitTests()) {
                $cache = Cache::store();
            }
            $requests = app(ProviderRequestGuard::class);
            $states = new XOAuthState($cache, $requests);
            $pending = new XPendingAccess($cache, $requests);

            // Notice correlation is never evidence of legal or provider approval.
            return [new XAuthorizationCoordinator($http, $configuration, $states, $requests, $pending, app(ProviderAccessStore::class)),
                $states, new XFlowState($cache, $requests)];
        } catch (InvalidArgumentException|LogicException) {
            abort(503, 'X connection is not configured.');
        }
    }

    private function declared(Request $request, NativeSourceOwner $workspace, string $source, string $namespace, string $operation): ProviderScope
    {
        DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$workspace->workspaceId, SessionAuthority::sessionKey($request)]);
        $identity = $workspace->sources()->where('id', $source)
            ->where('family', 'x')->where('namespace', $namespace)->first();
        if (! $identity) {
            abort(404);
        }

        return $workspace->scope($source, Provider::X, $identity->declared_external_id, $operation, 'advertising_analysis');
    }

    private function sameOwner(NativeSourceOwner $workspace, array $record): void
    {
        $workspace->assertBound($record);
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
        } catch (QueryException $error) {
            if ($error->getCode() === '42501') {
                abort(403);
            }
            if ($error->getCode() === '23505') {
                abort(409, 'X connection command conflict.');
            }
            throw $error;
        } catch (RuntimeException $error) {
            if (! $error instanceof XRequestFailed && ! preg_match('/^x_[a-z_]{1,100}$/D', $error->getMessage())) {
                throw $error;
            }

            return response()->json(['status' => 'PROVIDER_ACTION_REQUIRED', 'reason' => $error instanceof XRequestFailed ? $error->reason : $error->getMessage()], 502)
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');

        }

        return response()->json($data, ($data['status'] ?? null) === 'PROVIDER_ACTION_REQUIRED' ? 502 : 200)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
