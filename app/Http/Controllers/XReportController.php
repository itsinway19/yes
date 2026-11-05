<?php

namespace App\Http\Controllers;

use App\Providers\Ads\NativeSourceContext;
use App\Providers\Ads\NativeSourceOwner;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ReportWindow;
use App\Providers\Ads\X\XAccessStatus;
use App\Providers\Ads\X\XCampaignChoices;
use App\Providers\Ads\X\XCampaignReport;
use App\Providers\Ads\X\XReadJob;
use App\Providers\Ads\X\XReadSettings;
use App\Providers\Ads\X\XRequestFailed;
use App\Security\SessionAuthority;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/** Explicit bounded X report requests; no implicit grants, secrets or inline native HTTP. */
final class XReportController extends Controller
{
    public function campaigns(Request $request, string $workspace, string $source, NativeSourceContext $context, Factory $http): JsonResponse
    {
        if (array_diff(array_keys($request->query()), ['continuation'])) {
            throw ValidationException::withMessages(['campaigns' => 'Unknown campaign discovery fields.']);
        }
        $input = $request->validate(['continuation' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D']]);

        return $this->respond(fn () => $context->run($request->user(), $workspace, function (NativeSourceOwner $w) use ($request, $source, $http, $input): array {
            DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$w->workspaceId, SessionAuthority::sessionKey($request)]);
            $id = $w->sources()->where('id', $source)->where('family', 'x')
                ->where('namespace', 'ad_account')->value('declared_external_id');
            if ($id === null) {
                abort(404);
            }
            $scope = $w->scope($source, Provider::X, $id, 'report.read', 'advertising_analysis');
            $status = (new XAccessStatus)->snapshot($request, $scope);

            try {
                return (new XCampaignChoices($http, app(ProviderAccessStore::class), app(ProviderRequestGuard::class)))
                    ->page($request, $scope, (int) $status['version'], $input['continuation'] ?? null);
            } catch (XRequestFailed $error) {
                abort(502, $error->reason);
            } catch (LogicException) {
                abort(503, 'X campaign discovery is not configured.');
            }
        }, $request));
    }

    public function status(Request $request, string $workspace, string $source, NativeSourceContext $context): JsonResponse
    {
        $input = $this->input($request);

        return $this->respond(fn () => $context->run($request->user(), $workspace, function (NativeSourceOwner $w) use ($request, $source, $input): array {
            [$scope, $report, $stream] = $this->reportContext($request, $w, $source, $input);
            $record = DB::table('provider_observation_streams')->where('workspace_id', $w->workspaceId)->where('source_id', $source)
                ->where('provider', 'x')->where('resource', $scope->accountId)->where('operation', 'report.read')
                ->where('purpose', 'advertising_analysis')->where('stream', $stream)->first(['namespace', 'generation']);
            $run = $record === null ? null : DB::table('provider_observation_runs')->where('namespace', $record->namespace)
                ->where('generation', $record->generation)->first(['coverage', 'failure', 'recorded_at']);

            return ['source_id' => $source, 'timezone' => $report->window->timezone, 'generation' => (int) ($record->generation ?? 0),
                'deadline' => CarbonImmutable::now()->addMinutes(5)->format(DATE_ATOM),
                'last_run' => $run === null ? null : (array) $run];
        }, $request));
    }

    public function store(Request $request, string $workspace, string $source, NativeSourceContext $context): JsonResponse
    {
        $input = $this->input($request, true);

        return $this->respond(fn () => $context->run($request->user(), $workspace, function (NativeSourceOwner $w) use ($request, $source, $input): array {
            [$scope, $report, $stream] = $this->reportContext($request, $w, $source, $input, (int) $input['expected_version']);
            try {
                XReadSettings::configured();
            } catch (LogicException) {
                abort(503, 'X reporting is not configured.');
            }
            $job = XReadJob::capture($request, $report, $stream, $input['command_id'],
                (int) $input['expected_generation'], (int) $input['expected_version'], new DateTimeImmutable($input['deadline']));
            // The durable database queue is pushed after Workspace commit. Never run HTTP inline.
            Bus::dispatch($job->onConnection('database'));

            return ['queued' => true, 'command_id' => $input['command_id'], 'source_id' => $source];
        }, $request), 202);
    }

    private function reportContext(Request $request, NativeSourceOwner $workspace, string $source, array $input, ?int $version = null): array
    {
        DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$workspace->workspaceId, SessionAuthority::sessionKey($request)]);
        $identity = $workspace->sources()->where('id', $source)
            ->where('family', 'x')->where('namespace', 'ad_account')->first(['declared_external_id']);
        if ($identity === null) {
            abort(404);
        }
        $scope = $workspace->scope($source, Provider::X, $identity->declared_external_id, 'report.read', 'advertising_analysis');
        $status = (new XAccessStatus)->snapshot($request, $scope);
        (new XAccessStatus)->requireReport($request, $scope);
        app(ProviderRequestGuard::class)->requireCurrent($request, $scope, 'provider.read', $version ?? (int) $status['version']);
        // Fetch prerequisites include privacy/purpose; unknown approval cannot queue a native read.
        $credentials = app(ProviderAccessStore::class)->credentials($request, $scope);
        $window = new ReportWindow($input['start_date'], $input['end_date'], 'INCLUSIVE_DATES', 'TOTAL',
            $credentials->value('account_timezone'), 'X account-local inclusive campaign period');
        try {
            $report = new XCampaignReport($scope, $window, $input['campaign_ids'], $input['placement'], ($input['mode'] ?? 'SYNC') === 'ASYNC',
                null, $credentials->custodyPayload()['timezone_switch_at'] ?? 'UNKNOWN');
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['report' => $error->getMessage() === 'X timezone switch applicability is unverified.'
                ? 'X timezone-switch metadata is unverified for this period. Reverify the account or choose an established period.'
                : 'X reports require 1–20 unique campaigns and whole hours: SYNC supports seven elapsed days, ASYNC supports 90.']);
        }
        $stream = 'x.campaign.period.v1:'.$report->binding();

        return [$scope, $report, $stream];
    }

    private function input(Request $request, bool $command = false): array
    {
        $rules = ['start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'campaign_ids' => ['required', 'array', 'list', 'min:1', 'max:20'],
            'campaign_ids.*' => ['required', 'string', 'distinct:strict', 'regex:/^[a-zA-Z0-9]{1,180}$/D'],
            'placement' => ['required', 'in:ALL_ON_TWITTER,SPOTLIGHT,TREND'], 'mode' => ['sometimes', 'in:SYNC,ASYNC']];
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
                abort(409, 'X report command conflict.');
            }
            throw $error;
        }

        return response()->json($data, $status)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
