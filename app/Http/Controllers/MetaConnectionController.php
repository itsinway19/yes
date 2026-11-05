<?php

namespace App\Http\Controllers;

use App\Providers\Ads\Meta\MetaAccessStatus;
use App\Providers\Ads\Meta\MetaAuthorizationCoordinator;
use App\Providers\Ads\Meta\MetaConnectionNotice;
use App\Providers\Ads\Meta\MetaFlowState;
use App\Providers\Ads\Meta\MetaLoginBootstrap;
use App\Providers\Ads\Meta\MetaLoginSettings;
use App\Providers\Ads\Meta\MetaOAuthState;
use App\Providers\Ads\Meta\MetaPendingAccess;
use App\Providers\Ads\Meta\MetaRequestFailed;
use App\Providers\Ads\NativeProgress;
use App\Providers\Ads\NativeSourceContext;
use App\Providers\Ads\NativeSourceOwner;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderRequestGuard;
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
use LogicException;
use Symfony\Component\HttpFoundation\ParameterBag;

/** Meta web API only. All effects retain the signed Workspace and exact A003/BB1 boundaries. */
final class MetaConnectionController extends Controller
{
    public function index(Request $request, string $workspace, NativeSourceContext $context, Factory $http): JsonResponse
    {
        return $this->respond(fn () => $context->run($request->user(), $workspace, function (NativeSourceOwner $w) use ($request, $http): array {
            DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$w->workspaceId, SessionAuthority::sessionKey($request)]);
            $sources = $w->sources('s')->join('source_capabilities as c', 'c.source_id', '=', 's.id')
                ->where('s.family', 'meta')->where('s.namespace', 'ad_account')
                ->where('c.operation', 'report.read')->where('c.purpose', 'advertising_analysis')
                ->get(['s.id', 's.label', 's.status', 'c.resource']);
            $connections = $sources->map(fn ($s) => ['source_id' => $s->id, 'label' => $s->label,
                'declared_account_id' => $s->resource, 'registration' => $s->status,
                'access' => (new MetaAccessStatus)->snapshot($request, $w->scope($s->id, Provider::Meta,
                    $s->resource, 'report.read', 'advertising_analysis'))])->all();
            try {
                [$settings, $notice, $coordinator, , $flows] = $this->runtime($http);
            } catch (LogicException) {
                return ['connections' => $connections, 'connect_intent' => null, 'availability' => 'CONFIGURATION_REQUIRED', 'notice' => null];
            }
            $owner = $w->canManage($request);
            $fresh = app(SessionAuthority::class)->hasFreshAuthentication($request, $request->user());
            $intent = null;
            if ($owner && $fresh) {
                DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$w->workspaceId, SessionAuthority::sessionKey($request)]);
                $existing = $w->sources()->where('family', 'meta')
                    ->where('namespace', 'facebook_login_configuration')->where('declared_external_id', $settings->configurationId)->value('id');
                $scope = $w->scope($existing ?? (string) Str::uuid7(), Provider::Meta, $settings->configurationId,
                    'discovery.read', 'advertising_analysis');
                $intent = $flows->issue($request, $scope, 0, $coordinator->configurationBinding(), 'connect',
                    ['command' => (string) Str::uuid(), ...$w->reference()]);
            }

            return ['connections' => $connections, 'connect_intent' => $intent, 'notice' => $notice->projection(),
                'availability' => ! $owner ? 'OWNER_REQUIRED' : (! $fresh ? 'FRESH_AUTHENTICATION_REQUIRED' : 'CONNECT_AVAILABLE')];
        }, $request));
    }

    public function connect(Request $request, string $workspace, NativeSourceContext $context, Factory $http): JsonResponse
    {
        $input = $this->input($request, ['intent' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D']]);

        return $this->respond(function () use ($request, $workspace, $context, $http, $input): array {
            [$settings, , $coordinator, , $flows] = $this->configured($http);

            return $context->run($request->user(), $workspace, function (NativeSourceOwner $w) use ($request, $input, $settings, $coordinator, $flows): array {
                $intent = $flows->load($request, $coordinator->configurationBinding(), 'connect', $input['intent']);
                $this->sameOwner($w, $intent);
                $scope = $intent['scope'];
                app(MetaLoginBootstrap::class)->requestConnection($request, $w, $settings->configurationId,
                    $scope->sourceId, $intent['data']['command']);

                return ['authorization_url' => $coordinator->authorizationUrl($request, $scope)];
            }, $request);
        });
    }

    public function callback(#[\SensitiveParameter] Request $request, NativeSourceContext $context, Factory $http): JsonResponse|RedirectResponse
    {
        $callbackInput = $request->attributes->get('bb2.callback_input');
        $request->attributes->remove('bb2.callback_input');
        if (! $callbackInput instanceof ParameterBag) {
            abort(400, 'Invalid provider callback ingress.');
        }

        // No validation flash or exception request input may retain the provider's code/error text.
        $code = $callbackInput->get('code');
        $state = $callbackInput->get('state');
        $cancelled = $callbackInput->has('error');
        foreach (['code', 'state', 'error', 'error_reason', 'error_description'] as $name) {
            $request->query->remove($name);
        }
        $request->server->set('QUERY_STRING', '');
        $request->server->set('REQUEST_URI', $request->getPathInfo());
        if (! is_string($state) || ! preg_match('/^[A-Za-z0-9_-]{43}$/D', $state)
            || (! $cancelled && (! is_string($code) || $code === '' || strlen($code) > 4096 || preg_match('/[\x00-\x20\x7f]/', $code)))) {
            abort(400, 'Invalid Meta callback.');
        }

        $response = $this->respond(function () use ($request, $context, $http, $code, $state, $cancelled): array {
            [, , $coordinator, $states, $flows] = $this->configured($http);
            $route = $states->locate($request, $coordinator->configurationBinding(), $state);

            return $context->runForReference($request, $route, function (NativeSourceOwner $w) use ($request, $coordinator, $states, $flows, $route, $code, $state, $cancelled): array {
                $this->sameOwner($w, $route);
                if ($cancelled) {
                    $states->consume($request, $route['scope'], $route['access_version'], $coordinator->configurationBinding(), $state);

                    return [...$w->reference(), 'status' => 'AUTHORIZATION_NOT_COMPLETED'];
                }
                $pending = $coordinator->callback($request, $route['scope'], $route['access_version'], $state, $code);
                $handle = $flows->issue($request, $route['scope'], $route['access_version'], $coordinator->configurationBinding(), 'discovery',
                    [...$w->reference(), 'pending' => $pending, 'cursor' => null]);

                return [...$w->reference(), 'status' => 'ACCOUNT_SELECTION_REQUIRED', 'discovery_handle' => $handle];
            });
        });
        if ($request->expectsJson() || $response->getStatusCode() !== 200) {
            return $response;
        }
        $data = $response->getData(true);
        $fragment = isset($data['discovery_handle']) ? 'meta-flow='.$data['discovery_handle']
            : 'meta-result=AUTHORIZATION_NOT_COMPLETED';

        // The fragment is opaque session-bound correlation, never the provider code, token or native cursor.
        return redirect()->to(NativeSourceOwner::landingUrl($data).'#'.$fragment)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function discovery(Request $request, string $workspace, string $flow, NativeSourceContext $context, Factory $http): JsonResponse
    {
        return $this->respond(function () use ($request, $workspace, $flow, $context, $http): array {
            [, , $coordinator, , $flows] = $this->configured($http);

            return $context->run($request->user(), $workspace, function (NativeSourceOwner $w) use ($request, $flow, $coordinator, $flows): array {
                $record = $flows->load($request, $coordinator->configurationBinding(), 'discovery', $flow);
                $this->sameOwner($w, $record);
                $cursor = $record['data']['cursor'];
                $native = $cursor === null ? null : new NativeProgress($cursor['kind'], $cursor['basis'], $cursor['payload']);
                $page = $coordinator->discover($request, $record['scope'], $record['access_version'], $record['data']['pending'], $native);
                $choices = [];
                DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$w->workspaceId, SessionAuthority::sessionKey($request)]);
                foreach ($page->accounts as $account) {
                    $existing = $w->sources('s')->join('source_capabilities as c', 's.id', '=', 'c.source_id')
                        ->where('s.family', 'meta')->where('s.namespace', 'ad_account')
                        ->where('s.declared_external_id', $account['id'])->where('c.resource', $account['id'])
                        ->where('c.operation', 'report.read')->where('c.purpose', 'advertising_analysis')->orderBy('s.id')->get(['s.id', 's.label']);
                    $targets = $existing->isEmpty() ? [['id' => (string) Str::uuid7(), 'label' => null, 'register' => true]]
                        : $existing->map(fn ($s) => ['id' => $s->id, 'label' => $s->label, 'register' => false])->all();
                    foreach ($targets as $target) {
                        $version = $target['register'] ? 0 : (new MetaAccessStatus)->snapshot($request,
                            $w->scope($target['id'], Provider::Meta, $account['id'], 'report.read', 'advertising_analysis'))['version'];
                        $handle = $flows->issue($request, $record['scope'], $record['access_version'], $coordinator->configurationBinding(), 'selection',
                            [...$w->reference(), 'pending' => $record['data']['pending'], 'account_id' => $account['id'],
                                'source_id' => $target['id'], 'register' => $target['register'], 'access_version' => $version, 'command' => (string) Str::uuid()]);
                        $choices[] = ['account_id' => $account['id'], 'label' => $account['label'], 'existing_source_label' => $target['label'],
                            'selection_handle' => $handle];
                    }
                }
                $next = $page->continuation === null ? null : $flows->issue($request, $record['scope'], $record['access_version'],
                    $coordinator->configurationBinding(), 'discovery', [...$w->reference(), 'pending' => $record['data']['pending'],
                        'cursor' => $page->continuation->envelope()]);

                return ['coverage' => $page->coverage, 'accounts' => $choices, 'continuation' => $next];
            }, $request);
        });
    }

    public function select(Request $request, string $workspace, NativeSourceContext $context, Factory $http): JsonResponse
    {
        $input = $this->input($request, ['selection' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D']]);

        return $this->respond(function () use ($request, $workspace, $context, $http, $input): array {
            [, , $coordinator, , $flows] = $this->configured($http);

            return $context->run($request->user(), $workspace, function (NativeSourceOwner $w) use ($request, $coordinator, $flows, $input): array {
                $record = $flows->load($request, $coordinator->configurationBinding(), 'selection', $input['selection']);
                $this->sameOwner($w, $record);
                $d = $record['data'];
                $selected = $w->scope($d['source_id'], Provider::Meta, $d['account_id'], 'report.read', 'advertising_analysis');
                if ($w->businessId !== null) {
                    $w->bootstrap($request, $selected, $d['command'], 'ad_account', 'Meta account '.$selected->accountId);
                } else {
                    $actions = $d['register'] ? ['source.register', 'source.read', 'provider.connect', 'provider.read']
                        : ['source.read', 'provider.connect', 'provider.read'];
                    foreach ($actions as $action) {
                        app(GrantAuthority::class)->requestSourceGrant($request, $w->workspaceId, ['source_id' => $selected->sourceId,
                            'resource' => $selected->accountId, 'operation' => $selected->operation, 'purpose' => $selected->purpose,
                            'action' => $action, 'decision' => 'authorize', 'expires_at' => null, 'reason' => 'explicit_meta_account_selection']);
                    }
                    if ($d['register']) {
                        app(SourceRegistry::class)->command($request, $w->workspace(), ['source_id' => $selected->sourceId, 'command_id' => $d['command'],
                            'effect' => 'register', 'family' => 'meta', 'namespace' => 'ad_account', 'external_id' => $selected->accountId,
                            'label' => 'Meta account '.$selected->accountId, 'resource' => $selected->accountId, 'operation' => $selected->operation,
                            'purpose' => $selected->purpose, 'expected_version' => 0, 'reason' => 'explicit_meta_account_selection']);
                    }
                }
                $receipt = $coordinator->select($request, $record['scope'], $record['access_version'], $d['pending'], $selected, $d['command'], $d['access_version']);

                return ['source_id' => $selected->sourceId, 'receipt' => $receipt, 'access' => (new MetaAccessStatus)->snapshot($request, $selected)];
            }, $request);
        });
    }

    public function disconnect(Request $request, string $workspace, string $source, NativeSourceContext $context): JsonResponse
    {
        $input = $this->input($request, ['command_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1']]);

        return $this->respond(fn () => $context->run($request->user(), $workspace, function (NativeSourceOwner $w) use ($request, $source, $input): array {
            // Local custody revocation remains available when Meta configuration or transport is unavailable.
            DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$w->workspaceId, SessionAuthority::sessionKey($request)]);
            $declared = $w->sources('s')->join('source_capabilities as c', 'c.source_id', '=', 's.id')
                ->where('s.id', $source)->where('s.family', 'meta')->where('s.namespace', 'ad_account')
                ->where('c.operation', 'report.read')->where('c.purpose', 'advertising_analysis')->first(['c.resource']);
            abort_if($declared === null, 404);
            $scope = $w->scope($source, Provider::Meta, $declared->resource, 'report.read', 'advertising_analysis');
            if ($w->businessId === null) {
                app(GrantAuthority::class)->requestSourceGrant($request, $w->workspaceId, ['source_id' => $scope->sourceId,
                    'resource' => $scope->accountId, 'operation' => $scope->operation, 'purpose' => $scope->purpose,
                    'action' => 'provider.revoke', 'decision' => 'authorize', 'expires_at' => null, 'reason' => 'explicit_meta_local_disconnect']);
            }
            $receipt = app(ProviderAccessStore::class)->revoke($request, $scope, $input['command_id'], (int) $input['expected_version']);

            return ['source_id' => $source, 'receipt' => $receipt, 'access' => (new MetaAccessStatus)->snapshot($request, $scope)];
        }, $request));
    }

    private function runtime(Factory $http): array
    {
        $settings = MetaLoginSettings::configured();
        $notice = MetaConnectionNotice::configured();
        if ($settings->redirectUri !== rtrim((string) config('app.url'), '/').route('providers.meta.callback', absolute: false)) {
            throw new LogicException('Meta callback must match this application deployment.');
        }
        $cache = Cache::store();
        $guard = app(ProviderRequestGuard::class);
        $states = new MetaOAuthState($cache, $guard);
        $coordinator = new MetaAuthorizationCoordinator($http, $settings->adapter($http), $states,
            new MetaPendingAccess($cache, $guard), $guard, app(ProviderAccessStore::class), $notice->binding());

        return [$settings, $notice, $coordinator, $states, new MetaFlowState($cache)];
    }

    private function configured(Factory $http): array
    {
        try {
            return $this->runtime($http);
        } catch (LogicException) {
            abort(503, 'Meta connection is not configured.');
        }
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
        } catch (MetaRequestFailed $error) {
            return response()->json(['status' => 'PROVIDER_RETRY_REQUIRED', 'reason' => $error->reason], 502)
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        } catch (QueryException $error) {
            if ($error->getCode() === '42501') {
                abort(403);
            }
            if ($error->getCode() === '23505') {
                abort(409, 'Meta connection command conflict.');
            }
            throw $error;
        }

        return response()->json($data)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
