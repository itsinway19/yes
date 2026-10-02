<?php

namespace App\Providers\Ads\X;

use App\Providers\Ads\ObservationJournal;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderRequestGuard;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Caller owns the signed Workspace transaction; only a complete verified stats read is accepted. */
final readonly class XObservationReader
{
    public function __construct(private Factory $http, private XOAuthSigner $signer, private ObservationJournal $journal,
        private ProviderAccessStore $custody, private ProviderRequestGuard $requests) {}

    public function read(Request $request, XCampaignReport $report, ProviderReadBudget $budget, string $stream,
        string $command, int $expectedGeneration, int $accessVersion): array
    {
        $guard = $this->requests->bind($request, $report->scope, 'provider.read', $accessVersion);
        $guard($report->scope, 'provider.read');
        (new XAccessStatus)->requireReport($request, $report->scope);
        $credentials = $this->custody->credentials($request, $report->scope);
        $cooldown = new XReadCooldown(app()->runningUnitTests() ? Cache::store() : Cache::store('redis'), $report->scope, $credentials);
        $cooldown->before('sync_stats');
        $result = (new XCampaignReportReader($this->http, $this->signer, $guard, $cooldown))->read($report, $credentials, $budget);
        if ($result->batch->coverage !== 'COMPLETE') {
            throw new RuntimeException($result->batch->failure ?? 'x_report_incomplete');
        }
        // Recheck after HTTP; the SQL journal also enforces current scope, epoch and atomic acceptance.
        $guard($report->scope, 'provider.read');

        return $this->journal->accept($request, $result->batch, $stream, $command, $expectedGeneration,
            $accessVersion, $result->readProvenance, $result->progress);
    }
}
