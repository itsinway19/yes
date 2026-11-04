<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\PurposeDeclarationFixture;
use Tests\TestCase;

final class BusinessPurposePrivacyTest extends TestCase
{
    use ApplicationContractAssertions;

    private function selection(int|string $grant, ?string $workspace = null): array
    {
        return ['realm_kind' => $workspace === null ? 'direct' : 'internal_team', 'realm_workspace_public_id' => $workspace,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => (int) $grant]]];
    }

    public function test_direct_team_reads_current_purpose_without_creator_private_history(): void
    {
        $this->privacyScenario(false);
    }

    public function test_internal_team_reads_current_purpose_without_creator_private_history(): void
    {
        $this->privacyScenario(true);
    }

    private function privacyScenario(bool $internal): void
    {
        $creator = User::factory()->create();
        $employee = User::factory()->create();
        $this->actingAs($creator);
        $workspace = null;
        if ($internal) {
            app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($creator, 1);
            $key = $this->getJson('/organizations/new')->assertOk()->json('data.creationKey');
            $this->postJson('/organizations', ['name' => 'Purpose privacy team', 'creation_key' => $key])->assertOk();
            $workspace = DB::connection('pgsql_migrations')->table('workspaces')->sole();
            DB::connection('pgsql_migrations')->table('workspace_memberships')->insert(['workspace_id' => $workspace->id,
                'user_id' => $employee->id, 'role' => 'member', 'status' => 'active', 'created_at' => now()]);
        }
        $file = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Purpose privacy subject'])->assertCreated()->json('data');
        $base = '/api/businesses/'.$file['business_public_id'];
        $creatorRead = $file['grant_refs']['business.context.read'];
        $purpose = $this->postJson($base.'/purpose-enrollment', ['command_id' => (string) Str::uuid(), 'confirmed' => true,
            'previous_grant' => '0', 'authority_selection' => $this->selection($creatorRead)])->assertOk()->json('data.grant_refs')['business.purpose.declare'];
        $write = fn (array $command) => $this->postJson($base.'/purposes', ['command' => $command, 'expected_context' => 0,
            'authority_selection' => $this->selection($purpose)])->assertCreated()->json('data');
        $command = PurposeDeclarationFixture::command();
        $command['declaration']['meaning'] = 'Private superseded purpose before delegation';
        $first = $write($command);
        $known = $this->getJson($base.'/purposes?access='.$creatorRead)->assertOk()->json('data.projection.known_at');
        $command = [...$command, 'command_id' => (string) Str::uuid(), 'purpose_id' => $first['purpose_id'], 'expected_version' => 1,
            'operation' => 'correct', 'supersedes_revision_id' => $first['revision_id'], 'affected_revision_ids' => [$first['revision_id']],
            'correction_reason' => 'Private creator correction explanation'];
        $command['confirmations']['transition'] = true;
        $command['declaration']['meaning'] = 'Current purpose visible to the declaration team';
        $second = $write($command);
        // The latest revision is scheduled, so using the head instead of the
        // actual effective timeline would release a future private statement.
        $command = [...$command, 'command_id' => (string) Str::uuid(), 'expected_version' => 2, 'operation' => 'replace',
            'supersedes_revision_id' => $second['revision_id'], 'effective_from' => '2099-01-01T00:00:00Z',
            'correction_reason' => 'Private scheduling explanation'];
        $command['declaration']['meaning'] = 'Private future purpose before delegation';
        $scheduled = $write($command);

        if ($internal) {
            $roots = $this->postJson($base.'/internal-team', ['command_id' => (string) Str::uuid(),
                'workspace_public_id' => $workspace->public_id, 'effect' => 'enroll', 'recipient_public_id' => $creator->public_id,
                'expected_revision' => 0, 'authority_selection' => $this->selection($creatorRead)])->assertOk()->json('data.grant_refs');
        } else {
            $roots = $this->postJson($base.'/direct-team', ['command_id' => (string) Str::uuid(),
                'authority_selection' => $this->selection($creatorRead)])->assertOk()->json('data.grant_refs');
        }
        $read = $this->postJson($base.'/grants', ['command_id' => (string) Str::uuid(),
            'authority_selection' => $this->selection($roots['business.context.read'], $workspace?->public_id),
            'effect' => 'issue', 'grantee_public_id' => $employee->public_id, 'action' => 'business.context.read',
            'parent_grant_id' => (int) $roots['business.context.read'], 'grant_id' => null, 'expected_grant' => 0,
            'expires_at' => null, 'delegable' => false, 'realm_workspace_public_id' => $workspace?->public_id])->assertOk()->json('data.grant_id');
        $this->actingAs($employee);
        $surface = $this->getJson($base.'/surface?access='.$read)->assertOk()->assertJsonPath('data.private_history_available', false);
        $this->assertApplicationContract('BusinessSurfaceResult', $surface->json());
        foreach (['', '&purpose='.$first['purpose_id'], '&after=1', '&after='.$first['revision_id']] as $query) {
            $response = $this->getJson($base.'/purposes?access='.$read.$query)->assertOk()
                ->assertJsonPath('data.projection.items.0.applicable.revision_id', $second['revision_id'])
                ->assertJsonPath('data.projection.items.0.applicable.snapshot.meaning', 'Current purpose visible to the declaration team')
                ->assertJsonPath('data.projection.items.0.history', [])
                ->assertJsonPath('data.projection.items.0.scheduled_revision_ids', []);
            $this->assertApplicationContract('BusinessPurposesResult', $response->json());
            $this->assertPrivateHistoryAbsent($response->getContent(), $first, $scheduled);
        }
        foreach (['known_at' => $known, 'effective_at' => $known, 'effective_at_future' => '2099-01-02T00:00:00Z'] as $field => $instant) {
            $field = $field === 'effective_at_future' ? 'effective_at' : $field;
            $this->getJson($base.'/purposes?access='.$read.'&'.$field.'='.rawurlencode($instant))->assertForbidden();
        }
        foreach (['context', 'markets', 'intent', 'preferences'] as $kind) {
            $this->getJson($base.'/history?access='.$read.'&kind='.$kind.'&after=1')->assertForbidden();
        }
        $this->getJson($base.'/legacy-history?access='.$read.'&kind=object_purpose&after='.$first['revision_id'])->assertForbidden();
        $this->getJson($base.'/purposes?access='.$creatorRead)->assertForbidden();

        // Exercise the app-executable SECURITY DEFINER boundary directly: PHP
        // must never receive the private journal and then merely hide it.
        $request = Request::create($base.'/purposes');
        $request->setLaravelSession(app('session')->driver());
        $raw = DB::transaction(function () use ($employee, $request, $file, $read): array {
            app(PrincipalContext::class)->activate($employee);

            return json_decode(DB::scalar('SELECT builderad_v2_business_purpose_snapshot(?,?::uuid,?,NULL)',
                [SessionAuthority::sessionKey($request), $file['business_public_id'], $read]), true, flags: JSON_THROW_ON_ERROR);
        });
        $this->assertFalse($raw['history_available']);
        $this->assertSame([], $raw['heads'][0]['history']);
        $this->assertSame($second['revision_id'], $raw['heads'][0]['current']['revision_id']);
        $this->assertPrivateHistoryAbsent(json_encode($raw, JSON_THROW_ON_ERROR), $first, $scheduled);

        $this->actingAs($creator);
        $history = $this->getJson($base.'/purposes?access='.$creatorRead)->assertOk()->assertJsonCount(3, 'data.projection.items.0.history');
        $this->assertApplicationContract('BusinessPurposesResult', $history->json());
        $this->assertSame('Private creator correction explanation', $history->json('data.projection.items.0.history.1.reason'));
        $this->assertTrue($history->json('data.projection.items.0.history.1.confirmations.transition'));
        $this->getJson($base.'/purposes?access='.$creatorRead.'&known_at='.rawurlencode($known))->assertOk()
            ->assertJsonPath('data.projection.items.0.applicable.revision_id', $first['revision_id'])->assertJsonCount(1, 'data.projection.items.0.history');

        // A withdrawal cuts the preceding effective segment. Current-only reads
        // must not fall back to a retained declaration or its private snapshot.
        $command = [...$command, 'command_id' => (string) Str::uuid(), 'expected_version' => 3, 'operation' => 'withdraw',
            'supersedes_revision_id' => $scheduled['revision_id'], 'effective_from' => null, 'declaration' => null,
            'affected_revision_ids' => [$scheduled['revision_id']], 'correction_reason' => null, 'withdrawal_reason' => 'Private withdrawal explanation'];
        $write($command);
        $this->actingAs($employee)->getJson($base.'/purposes?access='.$read)->assertOk()
            ->assertJsonPath('data.projection.state', 'UNKNOWN')->assertJsonPath('data.projection.items.0.applicable', null)
            ->assertJsonPath('data.projection.items.0.history', [])->assertDontSee('Private withdrawal explanation');
    }

    private function assertPrivateHistoryAbsent(string $json, array $first, array $scheduled): void
    {
        foreach (['reason', 'confirmations', 'prior_version', 'supersedes_revision_id', 'affected_revision_ids',
            'Private superseded purpose', 'Private creator correction explanation', 'Private future purpose', 'Private scheduling explanation',
            $first['revision_id'], $scheduled['revision_id']] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }
}
