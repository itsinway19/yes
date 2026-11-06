<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture as Fixture;
use Tests\Support\DisposableDatabaseReset;
use Tests\TestCase;

final class BusinessLifecycleSurfaceTest extends TestCase
{
    use ApplicationContractAssertions;

    private function draft(string $name): array
    {
        return $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => $name])->assertCreated()->json('data');
    }

    private function url(array $draft, ?int $read = null): string
    {
        return '/api/businesses/'.$draft['business_public_id'].'/surface?access='.($read ?? $draft['grant_refs']['business.context.read']);
    }

    private function selection(int $grant): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $grant]]];
    }

    public function test_current_draft_archive_is_discovered_as_its_exact_existing_path_and_revoked_candidates_disappear(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $draft = $this->draft('Qualified draft');
        $response = $this->getJson($this->url($draft))->assertOk();
        $this->assertApplicationContract('BusinessSurfaceResult', $response->json());
        $response->assertJsonPath('data.lifecycle.revision', 0)
            ->assertJsonPath('data.lifecycle.archive_options', [['grant_id' => (string) $draft['grant_refs']['business.draft.archive']]]);

        $business = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $draft['business_public_id'])->value('id');
        $extra = Fixture::root($business, $actor->id, 'business.draft.archive', false);
        $this->assertContains((string) $extra->id, array_column($this->getJson($this->url($draft))->assertOk()->json('data.lifecycle.archive_options'), 'grant_id'));
        Fixture::revokeQualification($extra->qualification_id, $actor->id);
        $this->getJson($this->url($draft))->assertOk()
            ->assertJsonPath('data.lifecycle.archive_options', [['grant_id' => (string) $draft['grant_refs']['business.draft.archive']]]);
    }

    public function test_reading_or_editing_context_cannot_manufacture_archive_or_cross_business_access(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $this->actingAs($owner);
        $draft = $this->draft('Private draft');
        $sibling = $this->draft('Other draft');
        $db = DB::connection('pgsql_migrations');
        $business = $db->table('businesses')->where('public_id', $draft['business_public_id'])->value('id');
        $read = Fixture::root($business, $editor->id, 'business.context.read', false);
        $edit = Fixture::root($business, $editor->id, 'business.context.manage', false);
        $this->actingAs($editor);
        $this->getJson($this->url($draft, $read->id))->assertOk()
            ->assertJsonPath('data.lifecycle.archive_options', [])
            ->assertJsonPath('data.context_write_options', [['grant_id' => (string) $edit->id]]);
        $this->getJson($this->url($sibling, $read->id))->assertForbidden();
        $this->postJson('/api/businesses/'.$draft['business_public_id'].'/lifecycle', [
            'command_id' => (string) Str::uuid(), 'effect' => 'archive', 'target_ref' => null,
            'payload' => (object) [], 'expected_revision' => 0, 'authority_selection' => $this->selection($edit->id),
        ])->assertForbidden();
        $this->actingAs($owner);
        $this->getJson($this->url($draft))->assertOk()->assertJsonPath('data.business.state', 'declared_draft');
        $this->getJson($this->url($sibling))->assertOk()->assertJsonPath('data.business.state', 'declared_draft');
    }

    public function test_discovered_archive_uses_the_current_command_and_exact_replay_without_restoring_read_access(): void
    {
        $this->actingAs(User::factory()->create());
        $draft = $this->draft('Archive this draft');
        $sibling = $this->draft('Keep this draft');
        $lifecycle = $this->getJson($this->url($draft))->assertOk()->json('data.lifecycle');
        $body = ['command_id' => (string) Str::uuid(), 'effect' => 'archive', 'target_ref' => null,
            'payload' => (object) [], 'expected_revision' => $lifecycle['revision'],
            'authority_selection' => $this->selection((int) $lifecycle['archive_options'][0]['grant_id'])];
        $endpoint = '/api/businesses/'.$draft['business_public_id'].'/lifecycle';
        $receipt = $this->postJson($endpoint, $body)->assertOk()->assertJsonPath('data.state', 'archived')->json();
        $this->postJson($endpoint, $body)->assertOk()->assertExactJson($receipt);
        $this->getJson($this->url($draft))->assertForbidden();
        $this->getJson('/businesses/'.$draft['business_public_id'].'?access='.$draft['grant_refs']['business.context.read'])
            ->assertForbidden();
        $this->getJson($this->url($sibling))->assertOk()->assertJsonPath('data.business.state', 'declared_draft');
        $this->getJson('/api/businesses')->assertOk()->assertJsonCount(1, 'data.items');
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('business_command_receipts')->where('command_id', $body['command_id'])->count());
    }

    public function test_populated640_upgrade_preserves_businesses_grants_and_receipts_and_is_repeatable(): void
    {
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')),
                fn ($path) => strcmp(basename($path), '0001_01_01_000650_expose_qualified_draft_archive.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $db = DB::connection('pgsql_migrations');
            $db->statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
            $db->transaction(function () use ($db): void {
                $db->statement('SET LOCAL ROLE builderad_scope_executor');
                $db->unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', file_get_contents(base_path('tests/Fixtures/business-surface-640.sql'))));
            });
            $db->statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
            $db->unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
            $this->actingAs(User::factory()->create());
            $draft = $this->draft('Historical populated draft');
            $this->assertArrayNotHasKey('lifecycle', $this->getJson($this->url($draft))->assertOk()->json('data'));
            $before = $this->rows();
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            $this->assertSame($before, $this->rows());
            $response = $this->getJson($this->url($draft))->assertOk()->assertJsonPath('data.lifecycle.revision', 0);
            $this->assertApplicationContract('BusinessSurfaceResult', $response->json());
            $this->assertSame((string) $draft['grant_refs']['business.draft.archive'], $response->json('data.lifecycle.archive_options.0.grant_id'));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
        } finally {
            $reset->install();
        }
    }

    private function rows(): array
    {
        $db = DB::connection('pgsql_migrations');
        $rows = [];
        foreach (['businesses', 'security_action_grants', 'authority_qualification_receipts', 'business_command_receipts'] as $table) {
            $rows[$table] = $db->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r");
        }

        return $rows;
    }
}
