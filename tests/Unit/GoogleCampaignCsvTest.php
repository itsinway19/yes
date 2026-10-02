<?php

namespace Tests\Unit;

use App\Providers\Ads\Google\GoogleCampaignCsv;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GoogleCampaignCsvTest extends TestCase
{
    private function observation(): array
    {
        return ['scope' => ['provider' => 'google', 'account_id' => '1234567890'], 'campaign_id' => '456',
            'window' => ['start' => '2026-09-01', 'end' => '2026-09-02', 'grain' => 'TOTAL', 'boundary' => 'INCLUSIVE_DATES', 'timezone' => 'Asia/Amman'],
            'spend' => ['native_value' => '9007199254740993', 'currency' => 'JOD', 'native_unit' => 'google.currency_micros', 'decimal_scale' => 6, 'major_units' => '9007199254.740993'],
            'native_facts' => ['clicks' => ['value' => '0', 'basis' => 'PROVIDER_REPORTED'],
                'impressions' => ['value' => '10', 'basis' => 'PROVIDER_REPORTED'],
                'conversions' => ['value' => '0.5', 'basis' => 'PROVIDER_REPORTED'],
                'all_conversions' => ['value' => null, 'basis' => 'UNKNOWN'],
                'conversions_value' => ['value' => '9007199254740993.001', 'basis' => 'PROVIDER_REPORTED']],
            'read_at' => '2026-10-02T09:00:00+03:00',
            'provenance' => ['api_version' => 'v25', 'report' => 'Google Ads GAQL campaign Search', 'documentation_basis' => 'https://developers.google.com/google-ads/api/fields/v25/campaign']];
    }

    public function test_export_keeps_exact_native_metrics_zero_unknown_attribution_and_provenance(): void
    {
        $csv = GoogleCampaignCsv::render([$this->observation()]);
        $file = fopen('php://memory', 'w+');
        fwrite($file, $csv);
        rewind($file);
        $header = fgetcsv($file, escape: '');
        $this->assertIsArray($header, 'A report must contain the required header.');
        $row = array_combine($header, fgetcsv($file, escape: ''));
        fclose($file);
        $this->assertSame('9007199254740993', $row['metrics.cost_micros']);
        $this->assertSame('9007199254.740993', $row['cost_account_currency']);
        $this->assertSame('0', $row['metrics.clicks']);
        $this->assertSame('0.5', $row['metrics.conversions']);
        $this->assertSame('', $row['metrics.all_conversions']);
        $this->assertSame('UNKNOWN', $row['metrics.all_conversions.basis']);
        $this->assertSame('9007199254740993.001', $row['metrics.conversions_value']);
        $this->assertSame('PROVIDER_ATTRIBUTION_NOT_ACCEPTED_BUSINESS_OUTCOME', $row['conversion_interpretation']);
        $this->assertSame('Asia/Amman', $row['account_timezone']);
        $this->assertSame('v25', $row['api_version']);
    }

    public function test_empty_complete_report_has_required_columns_and_no_fabricated_campaign(): void
    {
        $csv = GoogleCampaignCsv::render([]);
        $this->assertStringContainsString('metrics.conversions_value', $csv);
        $this->assertSame(1, substr_count($csv, "\r\n"));
    }

    public function test_native_status_is_exported_and_old_missing_status_is_not_assumed_enabled(): void
    {
        $row = $this->observation();
        $row['native_facts']['campaign_status'] = ['value' => 'PAUSED', 'basis' => 'PROVIDER_REPORTED'];
        $file = fopen('php://memory', 'w+');
        fwrite($file, GoogleCampaignCsv::render([$row, $this->observation()]));
        rewind($file);
        $header = fgetcsv($file, escape: '');
        $this->assertContains('campaign.status', $header);
        $paused = array_combine($header, fgetcsv($file, escape: ''));
        $old = array_combine($header, fgetcsv($file, escape: ''));
        fclose($file);
        $this->assertSame('PAUSED', $paused['campaign.status']);
        $this->assertSame('', $old['campaign.status']);
        $this->assertSame('UNKNOWN', $old['campaign.status.basis']);
    }

    public function test_untrusted_formula_and_sibling_provider_are_not_exported(): void
    {
        foreach (['formula', 'provider', 'unit'] as $case) {
            $row = $this->observation();
            if ($case === 'formula') {
                $row['native_facts']['clicks']['value'] = '=HYPERLINK("https://invalid")';
            }
            if ($case === 'provider') {
                $row['scope']['provider'] = 'tiktok';
            }
            if ($case === 'unit') {
                $row['spend']['decimal_scale'] = null;
            }
            try {
                GoogleCampaignCsv::render([$row]);
                $this->fail('Invalid persisted measurements must never produce a download.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_oversized_snapshot_fails_instead_of_silently_truncating(): void
    {
        $this->expectException(\LengthException::class);
        GoogleCampaignCsv::render(array_fill(0, 10001, $this->observation()));
    }

    public function test_selected_metrics_keep_exact_values_basis_and_required_context_in_canonical_order(): void
    {
        $csv = GoogleCampaignCsv::render([$this->observation()], ['conversions_value', 'clicks']);
        $header = str_getcsv(strtok($csv, "\r\n"), escape: '');
        $this->assertContains('metrics.cost_micros', $header);
        $this->assertContains('campaign.status', $header);
        $this->assertContains('documentation_basis', $header);
        $this->assertContains('metrics.impressions', $header);
        $this->assertContains('metrics.all_conversions.basis', $header);
        $this->assertLessThan(array_search('metrics.conversions_value', $header), array_search('metrics.clicks', $header));
        $this->assertStringContainsString('9007199254740993.001', $csv);
        $this->assertSame($csv, GoogleCampaignCsv::render([$this->observation()], ['clicks', 'conversions_value']));
        $requiredOnly = GoogleCampaignCsv::render([$this->observation()], ['clicks']);
        $this->assertStringContainsString('metrics.all_conversions', $requiredOnly);
        $this->assertStringNotContainsString('metrics.conversions_value', $requiredOnly);
    }

    public function test_invalid_duplicate_or_empty_metric_selection_is_rejected(): void
    {
        foreach ([[], ['clicks', 'clicks'], ['access_token'], ['ctr'], [['clicks']]] as $metrics) {
            try {
                GoogleCampaignCsv::render([], $metrics);
                $this->fail('Invalid metric selection must not silently fall back to the complete report.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
