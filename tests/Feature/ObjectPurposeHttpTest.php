<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ObjectPurposeTestCase;

class ObjectPurposeHttpTest extends ObjectPurposeTestCase
{
    private function url(): string
    {
        return '/workspaces/'.$this->workspace->public_id.'/object-purposes';
    }

    private function input(): array
    {
        $c = self::command();
        $c['declaration']['included_objects'] = [['provider' => 'meta', 'account_namespace' => 'meta_ads_account', 'account_id' => '100', 'object_kind' => 'campaign', 'object_id' => '200', 'incarnation' => null],
            ['provider' => 'meta', 'account_namespace' => 'meta_ads_account', 'account_id' => '100', 'object_kind' => 'ad_set', 'object_id' => '300', 'incarnation' => null]];

        return $c;
    }

    public function test_unified_shell_and_context_resources_preserve_canonical_read_writer_and_passive_authority(): void
    {
        $this->get($this->url())->assertOk()->assertSee('application-bootstrap', false)->assertDontSee('data-page=', false);
        $resource = $this->getJson($this->url().'/context')->assertOk()->json('data');
        $this->assertNull($resource['selectedPurpose']);
        $this->assertSame('UNKNOWN', $resource['projection']['state']);
        $first = $this->postJson($this->url(), $this->input())->assertCreated()->json();
        $selected = $this->url().'/'.$first['purpose_id'];
        $this->get($selected)->assertOk()->assertSee('application-bootstrap', false)->assertDontSee('data-page=', false);
        $this->getJson($selected.'/context')->assertOk()->assertJsonPath('data.selectedPurpose', $first['purpose_id'])
            ->assertJsonPath('data.projection.items.0.applicable.revision_id', $first['revision_id']);
        $before = DB::connection('pgsql_migrations')->table('security_sessions')->where('user_id', auth()->id())->sole();
        $this->travel(30)->seconds();
        $this->getJson($selected.'/context', ['X-Builderad-Passive' => '1'])->assertOk();
        $this->getJson($selected.'/context', ['X-Builderad-Witness' => '1'])->assertOk()->assertJsonMissingPath('data');
        $after = DB::connection('pgsql_migrations')->table('security_sessions')->where('id', $before->id)->sole();
        $this->assertSame($before->idle_expires_at, $after->idle_expires_at);
        $this->assertSame($before->absolute_expires_at, $after->absolute_expires_at);
        $this->getJson($selected.'/context?known_at=2030-01-01T00:00:00Z')->assertUnprocessable();
        $this->getJson($this->url().'/00000000-0000-4000-8000-000000000000/context')->assertNotFound();
    }

    public function test_owner_http_admission_read_and_stale_conflict_preserve_other_context(): void
    {
        $this->getJson($this->url())->assertOk()->assertJsonPath('state', 'UNKNOWN');
        $input = $this->input();
        $first = $this->postJson($this->url(), $input)->assertCreated()->json();
        $this->postJson($this->url(), $input)->assertCreated()->assertJsonPath('revision_id', $first['revision_id']);
        $this->getJson($this->url().'/'.$first['purpose_id'])->assertOk()->assertJsonPath('items.0.applicable.version', 1)->assertJsonCount(2, 'items.0.applicable.snapshot.included_objects');
        $this->get($this->url())->assertOk();
        $next = $input;
        $next['command_id'] = (string) Str::uuid();
        $next['purpose_id'] = $first['purpose_id'];
        $next['operation'] = 'replace';
        $next['supersedes_revision_id'] = $first['revision_id'];
        $next['confirmations']['transition'] = true;
        $this->postJson($this->url().'/'.$first['purpose_id'].'/revisions', $next)->assertConflict();
        $actual = DB::connection('pgsql_migrations')->table('workspaces')->find($this->workspace->id);
        $this->assertSame(0, $actual->business_context_version);
        $this->assertNull($actual->current_intent);
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('source_registry')->count());
    }

    public function test_meta_rules_exist_only_at_http_edge_and_strict_shapes_fail(): void
    {
        $c = self::command();
        $this->postJson($this->url(), $c)->assertUnprocessable();
        $c = $this->input();
        $c['declaration']['included_objects'][0]['object_kind'] = 'ad';
        $this->postJson($this->url(), $c)->assertUnprocessable();
        $c = $this->input();
        $c['declaration']['included_objects'][0]['object_id'] = 'not-native-meta-id';
        $this->postJson($this->url(), $c)->assertUnprocessable();
        $c = $this->input();
        $c['declaration']['excluded_objects'] = (object) [];
        $this->postJson($this->url(), $c)->assertUnprocessable();
        $c = $this->input();
        $c['confirmations']['meaning'] = 'true';
        $this->postJson($this->url(), $c)->assertUnprocessable();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('object_purposes')->count());
    }

    public function test_unavailable_exact_identity_and_future_knowledge_are_not_empty_reads(): void
    {
        $this->getJson($this->url().'/'.Str::uuid7())->assertNotFound();
        $this->getJson($this->url().'?known_at=2030-01-01T00:00:00Z')->assertUnprocessable();
        $this->getJson($this->url().'?effective_at=tomorrow')->assertUnprocessable();
    }
}
