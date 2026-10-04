<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CountryReferenceProvenanceTest extends TestCase
{
    private function catalog(): array
    {
        return DB::connection('pgsql_migrations')->table('country_references')->orderByRaw('code COLLATE "C"')
            ->get(['code', 'name_en', 'name_ar'])->map(fn ($row) => (array) $row)->all();
    }

    private function identity(array $rows): string
    {
        return hash('sha256', implode("\n", array_column($rows, 'code'))."\n");
    }

    private function localization(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    public function test_installed_reference_matches_independently_reproduced_artifacts(): void
    {
        $root = database_path('reference/countries/');
        $provenance = json_decode(file_get_contents($root.'provenance.json'), true, flags: JSON_THROW_ON_ERROR);
        $source = json_decode(file_get_contents($root.'sources/iso_3166-1.json'), true, flags: JSON_THROW_ON_ERROR);
        $codes = array_column($source['3166-1'], 'alpha_2');
        sort($codes, SORT_STRING);
        $rows = $this->catalog();
        $this->assertSame($codes, array_column($rows, 'code'));
        $this->assertCount($provenance['IDENTITY_COUNT'], $rows);
        $this->assertSame($provenance['IDENTITY_DIGEST'], $this->identity($rows));
        $this->assertSame($provenance['LOCALIZATION_DIGEST_OR_EQUIVALENT'], $this->localization($rows));
        foreach (['en', 'ar'] as $locale) {
            $file = 'cldr-'.$locale.'-territories.json';
            $this->assertSame($provenance['source_hashes'][$file], hash_file('sha256', $root.'sources/'.$file));
            $sourceLabels = json_decode(file_get_contents($root.'sources/'.$file), true, flags: JSON_THROW_ON_ERROR)['main'][$locale]['localeDisplayNames']['territories'];
            foreach ($rows as $row) {
                $this->assertSame($sourceLabels[$row['code']], $row['name_'.$locale]);
            }
        }
    }

    public function test_label_only_change_preserves_identity_and_intent(): void
    {
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Label preservation', 'slug' => 'label-preservation'])->assertRedirect();
        $db = DB::connection('pgsql_migrations');
        $workspace = $db->table('workspaces')->where('slug', 'label-preservation')->first();
        $url = '/workspaces/'.$workspace->public_id.'/business';
        $this->post($url.'/target-markets', ['country_codes' => ['SA', 'KW', 'AE'], 'version' => 0])->assertRedirect();
        $snapshot = fn () => [(array) $db->table('workspaces')->find($workspace->id),
            $db->table('business_target_markets')->where('workspace_id', $workspace->id)->orderBy('country_code')->get()->toArray(),
            $db->table('business_target_market_events')->where('workspace_id', $workspace->id)->orderBy('id')->get()->toArray()];
        $before = $snapshot();
        $catalog = $this->catalog();
        $old = $db->table('country_references')->where('code', 'AE')->first();
        try {
            $db->table('country_references')->where('code', 'AE')->update(['name_en' => 'Synthetic display correction', 'name_ar' => 'تصحيح عرض اصطناعي']);
            $changed = $this->catalog();
            $this->assertSame($this->identity($catalog), $this->identity($changed));
            $this->assertSame(array_column($catalog, 'code'), array_column($changed, 'code'));
            $this->assertNotSame($this->localization($catalog), $this->localization($changed));
            $this->assertEquals($before, $snapshot());
            $this->get($url)->assertInertia(fn (Assert $page) => $page->where('business.targetMarkets.0.code', 'AE')
                ->where('business.targetMarkets.0.name_en', 'Synthetic display correction')->where('business.targetMarkets.0.name_ar', 'تصحيح عرض اصطناعي')
                ->where('business.version', 1)->where('business.targetMarketsDeclared', true));
        } finally {
            $db->table('country_references')->where('code', 'AE')->update(['name_en' => $old->name_en, 'name_ar' => $old->name_ar]);
        }
        $this->assertSame($this->localization($catalog), $this->localization($this->catalog()));
        $this->assertEquals($before, $snapshot());
    }
}
