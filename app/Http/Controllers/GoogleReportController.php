<?php

namespace App\Http\Controllers;

use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\Workspace;
use App\Providers\Ads\Google\GoogleAccessStatus;
use App\Providers\Ads\Google\GoogleCampaignCsv;
use App\Providers\Ads\Google\GoogleReadJob;
use App\Providers\Ads\Google\GoogleReadSettings;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Security\SessionAuthority;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/** Explicit bounded Google report requests; no implicit grants, secrets or inline native HTTP. */
final class GoogleReportController extends Controller
{
    public function status(Request $request, string $workspace, string $source, WorkspaceContext $context): JsonResponse
    {
        $input = $this->input($request);

        return $this->respond(fn () => $context->run($request->user(), $workspace, function (Workspace $w) use ($request, $source, $input): array {
            [$scope, $window, $stream] = $this->reportContext($request, $w, $source, $input);
            $record = DB::table('provider_observation_streams')->where('workspace_id', $w->id)->where('source_id', $source)
                ->where('provider', 'google')->where('resource', $scope->accountId)->where('operation', 'report.read')
                ->where('purpose', 'advertising_analysis')->where('stream', $stream)->first(['namespace', 'generation']);
            $run = $record === null ? null : DB::table('provider_observation_runs')->where('namespace', $record->namespace)
                ->where('generation', $record->generation)->first(['coverage', 'failure', 'recorded_at']);

            return ['source_id' => $source, 'timezone' => $window->timezone, 'generation' => (int) ($record->generation ?? 0),
                'deadline' => CarbonImmutable::now()->addMinutes(5)->format(DATE_ATOM),
                'download' => $run?->coverage !== 'COMPLETE' ? null : ['generation' => (int) $record->generation,
                    'url' => route('workspaces.providers.google.report.download', ['workspace' => $w->public_id,
                        'source' => $source, 'generation' => $record->generation, 'stream' => $stream], absolute: false)],
                'last_run' => $run === null ? null : (array) $run];
        }, $request));
    }

    public function download(Request $request, string $workspace, string $source, string $generation, WorkspaceContext $context): Response
    {
        if (array_diff(array_keys($request->query()), ['stream', 'metrics'])) {
            throw ValidationException::withMessages(['report' => 'Unknown download fields.']);
        }
        $input = $request->validate(['stream' => ['required', 'string', 'regex:/^google\.campaign\.period\.v1:[0-9a-f]{64}$/D'],
            'metrics' => ['sometimes', 'required', 'array', 'min:1', 'max:5'],
            'metrics.*' => ['required', 'string', 'distinct:strict', Rule::in(GoogleCampaignCsv::METRICS)]]);
        try {
            $metrics = GoogleCampaignCsv::selectedMetrics($input['metrics'] ?? null);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['metrics' => 'Invalid Google metric selection.']);
        }
        $csv = $this->guarded(fn () => $context->run($request->user(), $workspace, function (Workspace $w) use ($request, $source, $generation, $input, $metrics): string {
            $scope = $this->sourceScope($request, $w, $source);
            $authorize = static fn () => DB::selectOne('SELECT builderad_source_read_scope(?, ?, ?::uuid, ?, ?, ?)',
                [$w->id, SessionAuthority::sessionKey($request), $source, $scope->accountId, $scope->operation, $scope->purpose]);
            $authorize();
            $namespace = DB::table('provider_observation_streams')->where('workspace_id', $w->id)->where('source_id', $source)
                ->where('provider', 'google')->where('resource', $scope->accountId)->where('operation', $scope->operation)
                ->where('purpose', $scope->purpose)->where('stream', $input['stream'])->value('namespace');
            $run = $namespace === null ? null : DB::table('provider_observation_runs')->where('namespace', $namespace)
                ->where('generation', $generation)->first(['coverage']);
            abort_unless($run?->coverage === 'COMPLETE', 404);
            $rows = DB::table('provider_observation_entries')->where('namespace', $namespace)->where('generation', $generation)
                ->orderBy('identity')->limit(GoogleCampaignCsv::MAX_ROWS + 1)->pluck('observation')->map(function ($json) use ($scope): array {
                    $o = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                    foreach ($scope->jsonSerialize() as $key => $value) {
                        if (($o['scope'][$key] ?? null) !== $value) {
                            throw new LogicException('Stored Google observation scope mismatch.');
                        }
                    }

                    return $o;
                })->all();
            try {
                $csv = GoogleCampaignCsv::render($rows, $metrics);
            } catch (\LengthException) {
                abort(413, 'Stored report exceeds the local download bound; no partial file was produced.');
            }
            // Recheck after database waits and rendering, before releasing the complete snapshot.
            $authorize();

            return $csv;
        }, $request));

        return response($csv)->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="google-campaigns-'.$source.'-generation-'.$generation.'.csv"')
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Content-Type-Options', 'nosniff');
    }

    public function store(Request $request, string $workspace, string $source, WorkspaceContext $context): JsonResponse
    {
        $input = $this->input($request, true);

        return $this->respond(fn () => $context->run($request->user(), $workspace, function (Workspace $w) use ($request, $source, $input): array {
            [$scope, $window, $stream] = $this->reportContext($request, $w, $source, $input, (int) $input['expected_version']);
            try {
                GoogleReadSettings::configured();
            } catch (LogicException) {
                abort(503, 'Google reporting is not configured.');
            }
            $job = GoogleReadJob::capture($request, $scope, $window, $stream, $input['command_id'],
                (int) $input['expected_generation'], (int) $input['expected_version'], new DateTimeImmutable($input['deadline']));
            // The durable database queue is pushed after Workspace commit. Never run HTTP inline.
            Bus::dispatch($job->onConnection('database'));

            return ['queued' => true, 'command_id' => $input['command_id'], 'source_id' => $source];
        }, $request), 202);
    }

    private function reportContext(Request $request, Workspace $workspace, string $source, array $input, ?int $version = null): array
    {
        $scope = $this->sourceScope($request, $workspace, $source);
        $status = (new GoogleAccessStatus)->snapshot($request, $scope);
        (new GoogleAccessStatus)->requireReport($request, $scope);
        app(ProviderRequestGuard::class)->requireCurrent($request, $scope, 'provider.read', $version ?? (int) $status['version']);
        // Fetch prerequisites include privacy/purpose; unknown approval cannot queue a native read.
        $credentials = app(ProviderAccessStore::class)->credentials($request, $scope);
        $window = new ReportWindow($input['start_date'], $input['end_date'], 'INCLUSIVE_DATES', 'TOTAL',
            $credentials->value('account_timezone'), 'Google account-local inclusive campaign period');
        $stream = 'google.campaign.period.v1:'.hash('sha256', json_encode($window->jsonSerialize(), JSON_THROW_ON_ERROR));

        return [$scope, $window, $stream];
    }

    private function sourceScope(Request $request, Workspace $workspace, string $source): ProviderScope
    {
        DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$workspace->id, SessionAuthority::sessionKey($request)]);
        $identity = DB::table('source_registry')->where('workspace_id', $workspace->id)->where('id', $source)
            ->where('family', 'google')->where('namespace', 'ad_account')->first(['declared_external_id']);
        abort_if($identity === null, 404);

        return new ProviderScope($workspace->id, $source, Provider::Google, $identity->declared_external_id, 'report.read', 'advertising_analysis');
    }

    private function input(Request $request, bool $command = false): array
    {
        $rules = ['start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date']];
        if ($command) {
            $rules += ['command_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1'],
                'expected_generation' => ['required', 'integer', 'min:0'], 'deadline' => ['required', 'date_format:Y-m-d\TH:i:sP']];
        }
        if (array_diff(array_keys($request->except('_token')), array_keys($rules))) {
            throw ValidationException::withMessages(['report' => 'Unknown report fields.']);
        }

        $input = $request->validate($rules);
        if ($command) {
            $deadline = new DateTimeImmutable($input['deadline']);
            $now = CarbonImmutable::now()->toDateTimeImmutable();
            if ($deadline <= $now || $deadline > $now->modify('+5 minutes')) {
                throw ValidationException::withMessages(['deadline' => 'Report execution deadline expired or exceeds the local bound.']);
            }
        }

        return $input;
    }

    private function respond(callable $action, int $status = 200): JsonResponse
    {
        return response()->json($this->guarded($action), $status)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function guarded(callable $action): mixed
    {
        try {
            $data = $action();
        } catch (QueryException $error) {
            if ($error->getCode() === '42501') {
                abort(403);
            }
            if ($error->getCode() === '23505') {
                abort(409, 'Google report command conflict.');
            }
            throw $error;
        }

        return $data;
    }
}
