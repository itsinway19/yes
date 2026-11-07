<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BusinessNativeMaterialFixture;
use Tests\TestCase;

final class DataLicenseSelectionTest extends TestCase
{
    use BusinessNativeMaterialFixture;

    public static function limits(): array
    {
        return [[32, 8], [33, 8], [32, 9], [33, 9]];
    }

    #[DataProvider('limits')]
    public function test_qualified_http_selection_separates_acquisition_and_bounded_membership(int $records, int $datasets): void
    {
        $f = $this->nativeMaterial(licensed: false, records: $records, datasets: $datasets);
        $base = '/api/businesses/'.$f['business']['business_public_id'];
        $response = $this->getJson($base.'/data-use-journey?access='.$f['business']['grant_refs']['business.context.read'])->assertOk();
        $response->assertJsonCount(1, 'data.sources')->assertJsonCount(0, 'data.licenses')
            ->assertJsonPath('data.sources_more', false)->assertJsonPath('data.licenses_more', false)
            ->assertJsonCount(8, 'data.sources.0.datasets')
            ->assertJsonPath('data.sources.0.datasets_completeness', $datasets > 8 ? 'PARTIAL' : 'COMPLETE');
        foreach ($response->json('data.sources.0.datasets') as $dataset) {
            $this->assertSame('COMPLETE', $dataset['coverage']);
            $this->assertSame($records > 32 ? 'PARTIAL' : 'COMPLETE', $dataset['records_completeness']);
            $this->assertSame(32, $dataset['record_count']);
            $this->assertCount(32, $dataset['material_refs']);
            $this->assertCount(32, $dataset['record_metadata']);
            foreach ($dataset['record_metadata'] as $index => $metadata) {
                $this->assertSame($dataset['material_refs'][$index], $metadata['ref']);
                $this->assertStringStartsWith('campaign-', $metadata['campaign_id']);
                $this->assertSame('2026-10-01', $metadata['start']);
                $this->assertSame('2026-10-31', $metadata['end']);
                $this->assertSame('INCLUSIVE_DATES', $metadata['boundary']);
                $this->assertSame('TOTAL', $metadata['grain']);
                $this->assertSame('UTC', $metadata['timezone']);
                $this->assertArrayNotHasKey('spend', $metadata);
                $this->assertArrayNotHasKey('provenance', $metadata);
            }
            $this->assertCount(32, $dataset['selection']['native_keys']);
        }
        // Nothing is admitted by an inaccessible Business identifier or a foreign
        // read grant, including metadata or the extra-element existence probes.
        $other = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Unrelated context'])->assertCreated()->json('data');
        $this->getJson($base.'/data-use-journey?access='.$other['grant_refs']['business.context.read'])->assertForbidden();
        $this->assertSame($records * $datasets, DB::connection('pgsql_migrations')->table('provider_observation_entries')->count());
    }
}
