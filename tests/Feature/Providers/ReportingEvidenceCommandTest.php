<?php

namespace Tests\Feature\Providers;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderReportingEvidence;
use App\Providers\Ads\ProviderScope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ReportingEvidenceCommandTest extends TestCase
{
    public static function scopes(): iterable
    {
        foreach (Provider::cases() as $provider) {
            foreach (['workspace', 'business'] as $owner) {
                yield $provider->value.'-'.$owner => [$provider, $owner];
            }
        }
    }

    #[DataProvider('scopes')]
    public function test_operator_command_preserves_exact_scope_through_configuration_admission_and_withdrawal(Provider $provider, string $owner): void
    {
        $source = (string) Str::uuid7();
        $scope = $owner === 'business'
            ? ProviderScope::forBusiness(17, $source, $provider, 'synthetic-account', 'report.read', 'advertising_analysis')
            : new ProviderScope(17, $source, $provider, 'synthetic-account', 'report.read', 'advertising_analysis');
        $service = app(ProviderReportingEvidence::class);
        $root = storage_path('framework/testing/reporting-command-'.Str::uuid());
        mkdir($root, 0700, true);
        $path = storage_path('app/private/bb2-reporting-evidence/'.hash('sha256', $scope->key('reporting-evidence')).'.enc');
        $record = ['scope' => $scope->jsonSerialize(), 'configuration' => $service->configuration($scope)];
        foreach (['provider_support', 'privacy_purpose'] as $axis) {
            file_put_contents($root.'/'.$axis, 'synthetic:'.$axis);
            $record[$axis] = ['provenance' => 'synthetic:reviewed:'.$axis, 'issuer' => 'synthetic-issuer',
                'authority_basis' => 'synthetic-test-only', 'approval_reference' => 'synthetic-approval',
                'artifact_sha256' => hash_file('sha256', $root.'/'.$axis),
                'verified_at' => now()->subMinute()->toAtomString(), 'valid_until' => now()->addHour()->toAtomString()];
        }
        $manifest = $root.'/manifest.json';
        $write = fn ($value) => file_put_contents($manifest, json_encode($value, JSON_THROW_ON_ERROR));
        $call = fn (array $options) => Artisan::call('bb2:admit-reporting-evidence', ['manifest' => $manifest, ...$options]);
        $options = ['--operator' => 'synthetic-operator', '--support-artifact' => $root.'/provider_support',
            '--privacy-artifact' => $root.'/privacy_purpose', '--attest-reviewed-approvals' => true];
        try {
            $write($record);
            $this->assertSame(0, $call(['--show-configuration' => true]));
            $this->assertSame($record['configuration'], trim(Artisan::output()));
            $this->assertFileDoesNotExist($path);
            $this->assertSame(1, $call([...$options, '--attest-reviewed-approvals' => false]));
            $this->assertSame(1, $call([...$options, '--operator' => '']));
            $this->assertSame(1, $call([...$options, '--privacy-artifact' => $root.'/provider_support']));
            foreach (['provider_support', 'privacy_purpose'] as $axis) {
                $invalid = $record;
                $invalid[$axis]['valid_until'] = now()->subSecond()->toAtomString();
                $write($invalid);
                $this->assertSame(1, $call($options));
            }
            $invalid = $record;
            $invalid['configuration'] = str_repeat('0', 64);
            $write($invalid);
            $this->assertSame(1, $call($options));
            foreach ([['workspace_id' => 17, 'business_id' => 17], [$owner === 'business' ? 'business_id' : 'workspace_id' => null]] as $fields) {
                $invalid = $record;
                $invalid['scope'] = [...$invalid['scope'], ...$fields];
                $write($invalid);
                foreach ([['--show-configuration' => true], $options, ['--withdraw' => true, '--operator' => 'synthetic-operator']] as $action) {
                    $this->assertSame(1, $call($action));
                }
            }
            $write($record);
            $this->assertSame(0, $call($options));
            $native = $service->apply($scope, []);
            $this->assertSame('VERIFIED', $native['provider_support']['state']);
            $this->assertSame('VERIFIED', $native['privacy_purpose']['state']);
            $this->assertArrayNotHasKey('credentials', $native);
            $foreign = $owner === 'business'
                ? new ProviderScope(17, $source, $provider, $scope->accountId, $scope->operation, $scope->purpose)
                : ProviderScope::forBusiness(17, $source, $provider, $scope->accountId, $scope->operation, $scope->purpose);
            $this->assertNotSame($scope->key('reporting-evidence'), $foreign->key('reporting-evidence'));
            $this->assertSame('UNKNOWN', $service->apply($foreign, [])['privacy_purpose']['state']);
            $otherSource = new ProviderScope($scope->workspaceId, (string) Str::uuid7(), $provider, $scope->accountId, $scope->operation, $scope->purpose, $scope->businessId);
            $this->assertSame('UNKNOWN', $service->apply($otherSource, [])['privacy_purpose']['state']);
            $this->assertSame($native, $service->applicable($scope, $native));
            $this->assertSame(1, $call(['--withdraw' => true, '--operator' => '']));
            $this->assertSame($native, $service->applicable($scope, $native));
            $this->assertSame(0, $call(['--withdraw' => true, '--operator' => 'synthetic-operator']));
            $this->assertSame('UNKNOWN', $service->applicable($scope, $native)['provider_support']['state']);
            $this->assertSame('UNKNOWN', $service->apply($scope, [])['privacy_purpose']['state']);
        } finally {
            foreach (glob($root.'/*') as $file) {
                unlink($file);
            }
            rmdir($root);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
