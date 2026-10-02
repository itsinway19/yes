<?php

namespace App\Http\Controllers;

use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\Workspace;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Providers\Ads\TikTok\TikTokAccessStatus;
use App\Providers\Ads\TikTok\TikTokReadJob;
use App\Providers\Ads\TikTok\TikTokReadSettings;
use App\Security\SessionAuthority;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Explicit bounded TikTok report requests; no implicit grants, secrets or inline native HTTP. */
final class TikTokReportController extends Controller
{
    public function status(Request $request, string $workspace, string $source, WorkspaceContext $context): JsonResponse
    {
        $input = $this->input($request);

        return $this->respond(fn () => $context->run($request->user(), $workspace, function (Workspace $w) use ($request, $source, $input): array {
            [$scope, $window, $stream] = $this->reportContext($request, $w, $source, $input);
            $record = DB::table('provider_observation_streams')->where('workspace_id', $w->id)->where('source_id', $source)
                ->where('provider', 'tiktok')->where('resource', $scope->accountId)->where('operation', 'report.read')
                ->where('purpose', 'advertising_analysis')->where('stream', $stream)->first(['namespace', 'generation']);
            $run = $record === null ? null : DB::table('provider_observation_runs')->where('namespace', $record->namespace)
                ->where('generation', $record->generation)->first(['coverage', 'failure', 'recorded_at']);

            return ['source_id' => $source, 'timezone' => $window->timezone, 'generation' => (int) ($record->generation ?? 0),
                'deadline' => CarbonImmutable::now()->addMinutes(5)->format(DATE_ATOM),
                'last_run' => $run === null ? null : (array) $run];
        }, $request));
    }

    public function store(Request $request, string $workspace, string $source, WorkspaceContext $context): JsonResponse
    {
        $input = $this->input($request, true);

        return $this->respond(fn () => $context->run($request->user(), $workspace, function (Workspace $w) use ($request, $source, $input): array {
            [$scope, $window, $stream] = $this->reportContext($request, $w, $source, $input, (int) $input['expected_version']);
            try {
                TikTokReadSettings::configured();
            } catch (LogicException) {
                abort(503, 'TikTok reporting is not configured.');
            }
            $job = TikTokReadJob::capture($request, $scope, $window, $stream, $input['command_id'],
                (int) $input['expected_generation'], (int) $input['expected_version'], new DateTimeImmutable($input['deadline']));
            // The durable database queue is pushed after Workspace commit. Never run HTTP inline.
            Bus::dispatch($job->onConnection('database'));

            return ['queued' => true, 'command_id' => $input['command_id'], 'source_id' => $source];
        }, $request), 202);
    }

    private function reportContext(Request $request, Workspace $workspace, string $source, array $input, ?int $version = null): array
    {
        DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$workspace->id, SessionAuthority::sessionKey($request)]);
        $identity = DB::table('source_registry')->where('workspace_id', $workspace->id)->where('id', $source)
            ->where('family', 'tiktok')->where('namespace', 'ad_account')->first(['declared_external_id']);
        if ($identity === null) {
            abort(404);
        }
        $scope = new ProviderScope($workspace->id, $source, Provider::TikTok, $identity->declared_external_id, 'report.read', 'advertising_analysis');
        $status = (new TikTokAccessStatus)->snapshot($request, $scope);
        (new TikTokAccessStatus)->requireReport($request, $scope);
        app(ProviderRequestGuard::class)->requireCurrent($request, $scope, 'provider.read', $version ?? (int) $status['version']);
        // Fetch prerequisites include privacy/purpose; unknown approval cannot queue a native read.
        $credentials = app(ProviderAccessStore::class)->credentials($request, $scope);
        $window = new ReportWindow($input['start_date'], $input['end_date'], 'INCLUSIVE_DATES', 'TOTAL',
            $credentials->value('account_timezone'), 'TikTok account-local inclusive campaign period');
        $stream = 'tiktok.campaign.period.v1:'.hash('sha256', json_encode($window->jsonSerialize(), JSON_THROW_ON_ERROR));

        return [$scope, $window, $stream];
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
        try {
            $data = $action();
        } catch (QueryException $error) {
            if ($error->getCode() === '42501') {
                abort(403);
            }
            if ($error->getCode() === '23505') {
                abort(409, 'TikTok report command conflict.');
            }
            throw $error;
        }

        return response()->json($data, $status)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
