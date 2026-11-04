<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $this->actingAs($actor);
        $file = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Label preservation'])->assertCreated()->json('data');
        $db = DB::connection('pgsql_migrations');
        $business = $db->table('businesses')->where('public_id', $file['business_public_id'])->value('id');
        $url = '/api/businesses/'.$file['business_public_id'];
        $this->putJson($url.'/markets', ['command_id' => (string) Str::uuid(), 'codes' => ['SA', 'KW', 'AE'], 'expected_context' => 0,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $file['grant_refs']['business.markets.declare']]]]])->assertOk();
        $snapshot = fn () => [(array) $db->table('business_context_heads')->where('business_id', $business)->first(['business_id', 'display_name', 'context_version']),
            $db->table('business_declared_markets')->where('business_id', $business)->orderBy('country_code')->get()->toArray(),
            $db->table('business_market_transitions')->where('business_id', $business)->orderBy('event_id')->get()->toArray()];
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
            $surface = $this->getJson($url.'/surface?access='.$file['grant_refs']['business.context.read'])->assertOk()
                ->assertJsonPath('data.business.versions.context', 1)->assertJsonPath('data.business.declared_markets', ['AE', 'KW', 'SA']);
            $country = collect($surface->json('data.catalog.countries'))->firstWhere('code', 'AE');
            $this->assertSame('Synthetic display correction', $country['name_en']);
            $this->assertSame('تصحيح عرض اصطناعي', $country['name_ar']);
            $this->assertSame(0, $db->table('workspaces')->count());
        } finally {
            $db->table('country_references')->where('code', 'AE')->update(['name_en' => $old->name_en, 'name_ar' => $old->name_ar]);
        }
        $this->assertSame($this->localization($catalog), $this->localization($this->catalog()));
        $this->assertEquals($before, $snapshot());
    }
}
