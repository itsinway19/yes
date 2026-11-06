<?php

namespace App\Console\Commands;

use App\Providers\Ads\ProviderReportingEvidence;
use App\Providers\Ads\ProviderScope;
use Illuminate\Console\Command;

final class AdmitProviderReportingEvidence extends Command
{
    protected $signature = 'bb2:admit-reporting-evidence {manifest : Private JSON evidence record}
        {--operator= : Attributable trusted installation operator}
        {--support-artifact= : Reviewed provider support approval artifact}
        {--privacy-artifact= : Reviewed privacy/purpose approval artifact}
        {--show-configuration : Print only the exact current configuration fingerprint for the manifest scope}
        {--withdraw : Withdraw admitted evidence for the manifest scope without changing grants or credentials}
        {--attest-reviewed-approvals : Operator attests each issuer and authority basis actually approves the exact scope and effect period}';

    protected $description = 'Admit reviewed reporting evidence to private server custody; never creates grants or legal approval';

    public function handle(ProviderReportingEvidence $evidence): int
    {
        try {
            $path = (string) $this->argument('manifest');
            if (! is_file($path) || filesize($path) > 16384) {
                throw new \InvalidArgumentException;
            }
            $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $scope = ProviderScope::fromArray($record['scope']);
            if ($this->option('show-configuration')) {
                $this->line($evidence->configuration($scope));

                return self::SUCCESS;
            }
            if ($this->option('withdraw')) {
                $evidence->withdraw($scope, (string) $this->option('operator'));
                $this->info('Reporting evidence withdrawn; grants and credentials unchanged.');

                return self::SUCCESS;
            }
            if (! $this->option('attest-reviewed-approvals')) {
                throw new \InvalidArgumentException;
            }
            foreach (['provider_support' => 'support-artifact', 'privacy_purpose' => 'privacy-artifact'] as $axis => $option) {
                $artifact = (string) $this->option($option);
                if (! is_file($artifact) || ! hash_equals($record[$axis]['artifact_sha256'] ?? '', hash_file('sha256', $artifact))) {
                    throw new \InvalidArgumentException;
                }
            }
            $evidence->admit($scope, $record, (string) $this->option('operator'));
        } catch (\Throwable) {
            $this->error('Admission denied: exact scope/configuration, reviewed matching artifacts, operator attestation and explicit current validity are required.');

            return self::FAILURE;
        }
        $this->info('Evidence admitted. This establishes no grant and does not reverify existing native custody.');

        return self::SUCCESS;
    }
}
