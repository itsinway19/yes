<?php

namespace App\Providers\Ads;

/** Read-only provider boundary. Transport, paging, jobs, streaming and retry hints remain native. */
interface ProviderObservationAdapter
{
    public function provider(): Provider;

    /** Every network request/poll/retry consumes this run's budget; malformed results never produce progress. */
    public function read(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $credentials,
        ReportWindow $window, ProviderReadBudget $budget, ?NativeProgress $progress): ProviderReadResult;
}
