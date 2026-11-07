<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApplicationContractAssertions;
use Tests\TestCase;

final class OrganizationTeamTest extends TestCase
{
    use ApplicationContractAssertions;

    private function organization(User $owner): string
    {
        $this->actingAs($owner);
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($owner, 1);
        $key = $this->getJson('/organizations/new')->assertOk()->json('data.creationKey');
        $path = $this->postJson('/organizations', ['name' => 'Agency without commercial profile', 'creation_key' => $key])->assertOk()->json('redirect');

        return basename($path);
    }

    private function membership(string $organization, User $recipient, string $effect, int $revision): array
    {
        $body = ['command_id' => (string) Str::uuid(), 'effect' => $effect, 'recipient_public_id' => $recipient->public_id, 'expected_revision' => $revision];
        $result = $this->postJson('/api/organizations/'.$organization.'/team', $body)->assertOk()->json('data');
        if ($effect !== 'leave') {
            $this->postJson('/api/organizations/'.$organization.'/team', $body)->assertOk()->assertJsonPath('data.versions.team', $result['versions']['team']);
        }

        return $result;
    }

    private function selection(int|string $grant, ?string $organization = null): array
    {
        return ['realm_kind' => $organization ? 'internal_team' : 'direct', 'realm_workspace_public_id' => $organization,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => (int) $grant]]];
    }

    private function file(User $creator, string $name): array
    {
        return $this->actingAs($creator)->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => $name])->assertCreated()->json('data');
    }

    private function delegate(string $organization, array $file, array $roots, User $recipient, string $action): int
    {
        return $this->postJson('/api/businesses/'.$file['business_public_id'].'/grants', [
            'command_id' => (string) Str::uuid(), 'effect' => 'issue', 'grantee_public_id' => $recipient->public_id, 'action' => $action,
            'parent_grant_id' => (int) $roots[$action], 'grant_id' => null, 'expected_grant' => 0, 'expires_at' => null, 'delegable' => false,
            'realm_workspace_public_id' => $organization, 'authority_selection' => $this->selection($roots[$action], $organization),
        ])->assertOk()->json('data.grant_id');
    }

    public function test_registered_agency_adds_staff_assigns_three_unregistered_client_files_and_survives_staff_departure(): void
    {
        $owner = User::factory()->create();
        $staff = [User::factory()->create(), User::factory()->create()];
        $organization = $this->organization($owner);
        $teamPath = '/api/organizations/'.$organization.'/team';
        $team = $this->getJson($teamPath)->assertOk()->json('data');
        $this->assertApplicationContract('OrganizationTeam', $team);
        $this->assertTrue($team['can_manage']);
        $this->membership($organization, $staff[0], 'add', 0);
        $this->membership($organization, $staff[1], 'add', 1);
        $files = [];
        for ($i = 0; $i < 3; $i++) {
            $file = $this->file($owner, 'Unregistered client '.$i);
            $base = '/api/businesses/'.$file['business_public_id'];
            $roots = $this->postJson($base.'/internal-team', ['command_id' => (string) Str::uuid(), 'workspace_public_id' => $organization,
                'effect' => 'enroll', 'recipient_public_id' => $owner->public_id, 'expected_revision' => 0,
                'authority_selection' => $this->selection($file['grant_refs']['business.context.read'])])->assertOk()->json('data.grant_refs');
            $read = $this->delegate($organization, $file, $roots, $staff[$i % 2], 'business.context.read');
            $write = $this->delegate($organization, $file, $roots, $staff[$i % 2], 'business.context.manage');
            $this->actingAs($staff[$i % 2])->getJson($base.'/surface?access='.$read)->assertOk()->assertJsonPath('data.business.business_public_id', $file['business_public_id']);
            $this->putJson($base.'/context', ['command_id' => (string) Str::uuid(), 'name' => 'Employee updated client '.$i,
                'business_type_id' => null, 'expected_context' => 0, 'authority_selection' => $this->selection($write, $organization)])->assertOk();
            $this->actingAs($staff[($i + 1) % 2])->getJson($base.'/surface?access='.$read)->assertForbidden();
            $files[] = compact('file', 'read', 'write', 'roots');
        }
        $own = $this->file($staff[0], 'Employee independent activity');
        $this->actingAs($staff[0]);
        $this->getJson($teamPath)->assertOk()->assertJsonPath('data.can_manage', false)->assertJsonCount(1, 'data.members');
        $this->membership($organization, $staff[0], 'leave', 2);
        $this->getJson($teamPath)->assertForbidden();
        $this->getJson('/workspaces/'.$organization)->assertForbidden();
        $this->getJson('/api/businesses/'.$own['business_public_id'].'/surface?access='.$own['grant_refs']['business.context.read'])->assertOk();
        foreach ([0, 2] as $i) {
            $this->getJson('/api/businesses/'.$files[$i]['file']['business_public_id'].'/surface?access='.$files[$i]['read'])->assertForbidden();
        }
        $this->actingAs($owner);
        foreach ($files as $i => $entry) {
            $this->getJson('/api/businesses/'.$entry['file']['business_public_id'].'/surface?access='.$entry['file']['grant_refs']['business.context.read'])
                ->assertOk()->assertJsonPath('data.business.name', 'Employee updated client '.$i);
        }
        $this->membership($organization, $staff[0], 'add', 3);
        $this->actingAs($staff[0])->getJson('/api/businesses/'.$files[0]['file']['business_public_id'].'/surface?access='.$files[0]['read'])->assertForbidden();
        $this->actingAs($owner);
        $newRead = $this->delegate($organization, $files[0]['file'], $files[0]['roots'], $staff[0], 'business.context.read');
        $this->actingAs($staff[0])->getJson('/api/businesses/'.$files[0]['file']['business_public_id'].'/surface?access='.$newRead)->assertOk();
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(3, $db->table('users')->count(), 'Clients need no accounts.');
        $this->assertSame(1, $db->table('workspaces')->count());
        $this->assertSame(0, $db->table('source_registry')->count());
        $this->assertSame(0, $db->table('business_context_events')->count(), 'All staff edits used Business, never the Workspace profile.');
        $this->assertSame(0, $db->table('business_data_use_bindings')->count());
    }

    public function test_same_second_departure_and_rejoin_cannot_restore_the_old_business_grant(): void
    {
        $owner = User::factory()->create();
        $employee = User::factory()->create();
        $organization = $this->organization($owner);
        $this->membership($organization, $employee, 'add', 0);
        $file = $this->file($owner, 'Same second membership boundary');
        $base = '/api/businesses/'.$file['business_public_id'];
        $roots = $this->postJson($base.'/internal-team', ['command_id' => (string) Str::uuid(), 'workspace_public_id' => $organization,
            'effect' => 'enroll', 'recipient_public_id' => $owner->public_id, 'expected_revision' => 0,
            'authority_selection' => $this->selection($file['grant_refs']['business.context.read'])])->assertOk()->json('data.grant_refs');
        $db = DB::connection('pgsql_migrations');
        // Align the three real commands near a second boundary. The invariant
        // concerns command order even when their old timestamp columns rounded alike.
        $db->scalar("SELECT pg_sleep(greatest(0,extract(epoch FROM (date_trunc('second',clock_timestamp())+interval '1.01 seconds'-clock_timestamp()))))");
        $read = $this->delegate($organization, $file, $roots, $employee, 'business.context.read');
        $team = '/api/organizations/'.$organization.'/team';
        $this->postJson($team, ['command_id' => (string) Str::uuid(), 'effect' => 'remove',
            'recipient_public_id' => $employee->public_id, 'expected_revision' => 1])->assertOk();
        $this->postJson($team, ['command_id' => (string) Str::uuid(), 'effect' => 'add',
            'recipient_public_id' => $employee->public_id, 'expected_revision' => 2])->assertOk();
        $this->assertTrue((bool) $db->scalar("SELECT date_trunc('second',g.created_at)=date_trunc('second',m.created_at)
            FROM security_action_grants g JOIN workspaces w ON w.public_id=?::uuid
            JOIN workspace_memberships m ON m.workspace_id=w.id AND m.user_id=g.subject_user_id WHERE g.id=?", [$organization, $read]),
            'The fixture must exercise the same-second boundary.');
        $this->actingAs($employee)->getJson($base.'/surface?access='.$read)->assertForbidden();
    }

    public function test_membership_is_not_business_authority_and_cannot_be_managed_by_nonowners_or_stale_commands(): void
    {
        $owner = User::factory()->create();
        $employee = User::factory()->create();
        $stranger = User::factory()->create();
        $organization = $this->organization($owner);
        $path = '/api/organizations/'.$organization.'/team';
        $file = $this->file($owner, 'Unassigned client');
        $this->membership($organization, $employee, 'add', 0);
        $command = ['command_id' => (string) Str::uuid(), 'effect' => 'add', 'recipient_public_id' => $stranger->public_id, 'expected_revision' => 0];
        $this->postJson($path, $command)->assertConflict();
        $this->postJson($path, [...$command, 'command_id' => (string) Str::uuid(), 'expected_revision' => 1, 'effect' => 'leave', 'recipient_public_id' => $owner->public_id])->assertForbidden();
        $this->actingAs($employee)->postJson($path, [...$command, 'expected_revision' => 1])->assertForbidden();
        $this->getJson('/api/businesses/'.$file['business_public_id'].'/surface?access='.$file['grant_refs']['business.context.read'])->assertForbidden();
        $this->actingAs($stranger)->getJson($path)->assertForbidden();
        $this->postJson($path, [...$command, 'realm_kind' => 'agency'])->assertUnprocessable();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $employee->id)->where('scope_kind', 'business')->count());
    }

    public function test_member_can_read_only_self_and_leave_without_workspace_view_and_cannot_revive_its_revoked_grant(): void
    {
        $owner = User::factory()->create();
        $employee = User::factory()->create();
        $organization = $this->organization($owner);
        $this->membership($organization, $employee, 'add', 0);
        $db = DB::connection('pgsql_migrations');
        $workspace = $db->table('workspaces')->where('public_id', $organization)->value('id');
        $db->table('security_action_grants')->where('subject_user_id', $employee->id)->where('workspace_id', $workspace)
            ->where('scope_kind', 'workspace')->where('action', 'workspace.view')->update(['status' => 'revoked', 'revoked_at' => now(), 'version' => 2]);
        $path = '/api/organizations/'.$organization.'/team';
        $this->actingAs($employee)->getJson('/workspaces/'.$organization)->assertForbidden();
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'data.members')->assertJsonPath('data.members.0.public_id', $employee->public_id)
            ->assertJsonPath('data.can_manage', false)->assertJsonPath('data.can_leave', true);
        $this->membership($organization, $employee, 'leave', 1);
        $this->getJson($path)->assertForbidden();
        $this->actingAs($owner)->postJson($path, ['command_id' => (string) Str::uuid(), 'effect' => 'add',
            'recipient_public_id' => $employee->public_id, 'expected_revision' => 2])->assertForbidden();
        $this->assertSame('revoked', $db->table('workspace_memberships')->where('workspace_id', $workspace)->where('user_id', $employee->id)->value('status'));
        $this->assertSame('revoked', $db->table('security_action_grants')->where('subject_user_id', $employee->id)->where('workspace_id', $workspace)->where('action', 'workspace.view')->value('status'));
    }
}
