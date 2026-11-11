<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\DisposableDatabaseReset;
use Tests\TestCase;

final class BusinessWorkspaceAuthorityClosureTest extends TestCase
{
    private const ACTIONS = ['business.context.read', 'business.context.manage', 'business.intent.declare', 'business.markets.declare', 'business.preferences.manage'];

    private function selection(int|string $grant, ?string $workspace = null): array
    {
        return ['realm_kind' => $workspace === null ? 'direct' : 'internal_team', 'realm_workspace_public_id' => $workspace,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => (int) $grant]]];
    }

    private function business(User $creator, string $name): array
    {
        return $this->actingAs($creator)->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => $name])
            ->assertCreated()->json('data');
    }

    private function base(array $business): string
    {
        return '/api/businesses/'.$business['business_public_id'];
    }

    private function custodyBody(array $business, int|string $read, string $effect, ?User $recipient, ?int $epoch = null, ?string $workspace = null): array
    {
        if ($epoch === null) {
            $epoch = $this->getJson($this->base($business).'/internal-team?access='.$read)->assertOk()->json('data.versions.authority_epoch');
            $this->assertIsInt($epoch);
        }

        return ['command_id' => (string) Str::uuid(), 'workspace_public_id' => $workspace, 'effect' => $effect,
            'recipient_public_id' => $recipient?->public_id, 'expected_revision' => $epoch, 'authority_selection' => $this->selection($read)];
    }

    private function grantBody(int|string $parent, User $recipient, string $action, ?string $workspace = null): array
    {
        return ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($parent, $workspace), 'effect' => 'issue',
            'grantee_public_id' => $recipient->public_id, 'action' => $action, 'parent_grant_id' => (int) $parent, 'grant_id' => null,
            'expected_grant' => 0, 'expires_at' => null, 'delegable' => false, 'realm_workspace_public_id' => $workspace];
    }

    /** Actual runtime connection and signed principal; no owner-role SQL bypass. */
    private function runtimeSql(User $actor, string $query, array $parameters): mixed
    {
        $this->actingAs($actor)->getJson('/api/businesses')->assertOk();
        $request = Request::create('/api/businesses');
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn () => $actor);

        return DB::transaction(function () use ($actor, $request, $query, $parameters) {
            app(PrincipalContext::class)->activate($actor);
            $this->assertSame('builderad_app', DB::scalar('SELECT current_user'));

            return DB::scalar($query, [SessionAuthority::sessionKey($request), ...$parameters]);
        });
    }

    private function deniedSql(User $actor, string $query, array $parameters): void
    {
        try {
            $this->runtimeSql($actor, $query, $parameters);
            $this->fail('The directly executable SQL writer must reject new Workspace-derived Business authority.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->errorInfo[0] ?? $error->getCode());
        }
    }

    public function test_explicit_direct_business_custodian_survives_creator_surrender_and_administers_only_limited_staff(): void
    {
        $creator = User::factory()->create();
        $custodian = User::factory()->create();
        $employee = User::factory()->create();
        $successor = User::factory()->create();
        $business = $this->business($creator, 'Independent custody');
        $base = $this->base($business);
        $originalRead = $business['grant_refs']['business.context.read'];
        $appoint = $this->custodyBody($business, $originalRead, 'appoint', $custodian);
        $receipt = $this->postJson($base.'/internal-team', $appoint)->assertOk()->json('data');
        $roots = $receipt['grant_refs'];
        $this->assertEqualsCanonicalizing(self::ACTIONS, array_keys($roots));
        $db = DB::connection('pgsql_migrations');
        $rows = $db->table('security_action_grants')->whereIn('id', array_values($roots))->get();
        $this->assertCount(5, $rows);
        foreach ($rows as $grant) {
            $this->assertNull($grant->parent_grant_id);
            $this->assertNull($grant->delegation_workspace_id);
            $this->assertSame('direct', $grant->delegation_realm_kind);
            $qualification = $db->table('authority_qualification_receipts')->where('qualification_id', $grant->qualification_id)->sole();
            $this->assertSame('internal_client_file_admin_v1', $qualification->policy_id);
            $this->assertSame(2, (int) $qualification->policy_version);
            $this->assertSame('business-custody:'.$business['business_public_id'], $qualification->evidence_ref);
        }
        $this->assertSame(0, $db->table('workspaces')->count());
        $this->assertSame(0, $db->table('workspace_memberships')->count());
        $this->assertSame(0, $db->table('business_workspace_links')->count());

        // Surrender the exact creator read. Continuity must not silently fall
        // back to it or depend on a delegated creator ancestry.
        $surrender = $this->grantBody($originalRead, $creator, 'business.context.read');
        $surrender = [...$surrender, 'effect' => 'revoke', 'parent_grant_id' => null, 'grant_id' => (int) $originalRead, 'expected_grant' => 1];
        $this->postJson($base.'/grants', $surrender)->assertOk();
        $this->getJson($base.'/surface?access='.$originalRead)->assertForbidden();
        $read = $roots['business.context.read'];
        $surface = $this->actingAs($custodian)->getJson($base.'/surface?access='.$read)->assertOk()
            ->assertJsonPath('data.private_history_available', false)->assertJsonPath('data.agency_task.can_mandate', false)
            ->assertJsonPath('data.agency_task.can_assign', false)->json('data');
        $this->assertSame('direct', $surface['authority_selection']['realm_kind']);
        $this->putJson($base.'/context', ['command_id' => (string) Str::uuid(), 'name' => 'Custodian continued the file', 'business_type_id' => null,
            'expected_context' => 0, 'authority_selection' => $this->selection($roots['business.context.manage'])])->assertOk();

        $this->postJson($base.'/team/recipient', ['access' => (int) $read, 'email' => $employee->email])->assertOk()
            ->assertJsonPath('data.recipient.public_id', $employee->public_id);
        $staffBody = ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($read), 'recipient_public_id' => $employee->public_id,
            'expected_grants' => [], 'permissions' => ['business.context.read', 'business.context.manage'], 'expires_at' => null, 'confirmed' => true];
        $staff = $this->postJson($base.'/team', $staffBody)->assertOk()->json('data.grant_refs');
        $this->postJson($base.'/team', $staffBody)->assertOk()->assertJsonPath('data.grant_refs', $staff);
        $this->actingAs($employee)->getJson($base.'/surface?access='.$staff['business.context.read'])->assertOk()
            ->assertJsonPath('data.private_history_available', false);
        $this->getJson($base.'/history?access='.$staff['business.context.read'].'&kind=context')->assertForbidden();
        $this->getJson($base.'/team?access='.$staff['business.context.read'])->assertForbidden();
        $ordinaryAppointment = $this->custodyBody($business, $staff['business.context.read'], 'appoint', $successor, 0);
        $this->postJson($base.'/internal-team', $ordinaryAppointment)->assertForbidden();
        $this->actingAs($custodian);
        $next = $this->custodyBody($business, $read, 'appoint', $successor);
        $this->postJson($base.'/internal-team', $next)->assertOk();

        foreach (['history?access='.$read.'&kind=context', 'legacy-history?access='.$read.'&kind=context', 'inquiry-evidence/eligibility?access='.$read] as $path) {
            $this->getJson($base.'/'.$path)->assertForbidden();
        }
        $this->getJson('/businesses/'.$business['business_public_id'].'/providers/google')->assertForbidden();
        $this->postJson($base.'/operating-mandate', ['command_id' => (string) Str::uuid(), 'confirmed' => true, 'capacity' => 'responsible_business',
            'authority_reference' => 'A custodian is not the external Business representative', 'valid_until' => now()->addHour()->toIso8601String(),
            'previous_grant' => '0', 'authority_selection' => $this->selection($read)])->assertForbidden();
        foreach (['business.data.use', 'business.link.manage', 'business.purpose.declare'] as $action) {
            $this->postJson($base.'/grants', $this->grantBody($read, $employee, $action))->assertForbidden();
        }
        $this->postJson($base.'/grants', $this->grantBody($read, $employee, 'business.inquiry_evidence.read'))->assertUnprocessable();
        $onward = [...$this->grantBody($read, $employee, 'business.context.read'), 'delegable' => true];
        $this->postJson($base.'/grants', $onward)->assertForbidden();
        $member = $this->getJson($base.'/team?access='.$read)->assertOk()->json('data.members.0');
        $remove = [...$staffBody, 'command_id' => (string) Str::uuid(), 'permissions' => [],
            'expected_grants' => array_map(fn ($grant) => ['grant_id' => $grant['grant_id'], 'version' => $grant['version']], $member['grants'])];
        $this->postJson($base.'/team', $remove)->assertOk();
        $this->actingAs($employee)->getJson($base.'/surface?access='.$staff['business.context.read'])->assertForbidden();
        $this->assertSame(0, $db->table('security_action_grants')->whereIn('scope_kind', ['source', 'client_data', 'data_use'])->count());
    }

    public function test_withdrawn_custodian_cannot_replay_as_issuer_but_creator_receipt_retrieval_never_revives_recipient_rights(): void
    {
        $creator = User::factory()->create();
        $custodian = User::factory()->create();
        $employee = User::factory()->create();
        $successor = User::factory()->create();
        $business = $this->business($creator, 'Custody replay boundaries');
        $base = $this->base($business);
        $originalRead = $business['grant_refs']['business.context.read'];
        $this->postJson($base.'/direct-team', ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($originalRead)])
            ->assertOk();
        $independent = $this->postJson($base.'/team', ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($originalRead),
            'recipient_public_id' => $employee->public_id, 'expected_grants' => [], 'permissions' => ['business.context.read'], 'expires_at' => null, 'confirmed' => true])
            ->assertOk()->json('data.grant_refs')['business.context.read'];
        $appoint = $this->custodyBody($business, $originalRead, 'appoint', $custodian);
        $receipt = $this->postJson($base.'/internal-team', $appoint)->assertOk()->json('data');
        $read = $receipt['grant_refs']['business.context.read'];
        $this->actingAs($custodian);
        $staff = $this->postJson($base.'/grants', $this->grantBody($read, $employee, 'business.context.read'))->assertOk()->json('data.grant_id');
        $next = $this->custodyBody($business, $read, 'appoint', $successor);
        $successorRead = $this->postJson($base.'/internal-team', $next)->assertOk()->json('data.grant_refs')['business.context.read'];
        $this->actingAs($creator);
        $remove = $this->custodyBody($business, $originalRead, 'remove', $custodian);
        $this->postJson($base.'/internal-team', $remove)->assertOk();
        $db = DB::connection('pgsql_migrations');
        $grantCount = $db->table('security_action_grants')->count();
        $qualificationCount = $db->table('authority_qualification_receipts')->count();
        $this->postJson($base.'/internal-team', $appoint)->assertOk()->assertJsonPath('data', $receipt);
        $this->assertSame($grantCount, $db->table('security_action_grants')->count());
        $this->assertSame($qualificationCount, $db->table('authority_qualification_receipts')->count());
        $this->actingAs($custodian)->getJson($base.'/surface?access='.$read)->assertForbidden();
        $this->postJson($base.'/internal-team', $next)->assertForbidden();
        $this->actingAs($employee)->getJson($base.'/surface?access='.$staff)->assertForbidden();
        $this->getJson($base.'/surface?access='.$independent)->assertOk();
        $this->actingAs($successor)->getJson($base.'/surface?access='.$successorRead)->assertOk();
        $this->actingAs($creator);
        $replacement = $this->custodyBody($business, $originalRead, 'appoint', $custodian);
        $newRead = $this->postJson($base.'/internal-team', $replacement)->assertOk()->json('data.grant_refs')['business.context.read'];
        $this->assertNotSame($read, $newRead);
        $this->actingAs($employee)->getJson($base.'/surface?access='.$staff)->assertForbidden();
        $this->getJson($base.'/surface?access='.$independent)->assertOk();
    }

    public function test_expired_direct_custody_rejects_new_commands_and_its_own_old_receipt_without_affecting_creator(): void
    {
        $creator = User::factory()->create();
        $custodian = User::factory()->create();
        $successor = User::factory()->create();
        $business = $this->business($creator, 'Custody expiry');
        $base = $this->base($business);
        $originalRead = $business['grant_refs']['business.context.read'];
        $appoint = $this->custodyBody($business, $originalRead, 'appoint', $custodian);
        $read = $this->postJson($base.'/internal-team', $appoint)->assertOk()->json('data.grant_refs')['business.context.read'];
        $this->actingAs($custodian);
        $next = $this->custodyBody($business, $read, 'appoint', $successor);
        $this->postJson($base.'/internal-team', $next)->assertOk();
        $db = DB::connection('pgsql_migrations');
        $qualification = $db->table('security_action_grants')->where('id', $read)->value('qualification_id');
        $expiry = BusinessAuthorityFixture::expireQualificationSoon($qualification, $creator->id);
        $db->select('SELECT pg_sleep(greatest(0,extract(epoch FROM ?::timestamptz-clock_timestamp()))+0.02)', [$expiry]);
        $count = $db->table('security_action_grants')->count();
        $this->getJson($base.'/surface?access='.$read)->assertForbidden();
        $this->postJson($base.'/internal-team', $next)->assertForbidden();
        $this->postJson($base.'/internal-team', [...$next, 'command_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertSame($count, $db->table('security_action_grants')->count());
        $this->actingAs($creator)->getJson($base.'/surface?access='.$originalRead)->assertOk();
    }

    public function test_populated_pre770_upgrade_preserves_qualified_historical_rights_and_receipts_but_closes_every_new_workspace_issuer(): void
    {
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => strcmp(basename($path), '0001_01_01_000770_') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $creator = User::factory()->create();
            $custodian = User::factory()->create();
            $employee = User::factory()->create();
            $unappointed = User::factory()->create();
            $business = $this->business($creator, 'Preserved historical file');
            $base = $this->base($business);
            app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($creator, 1);
            $slug = 'closure-history-'.Str::uuid();
            $this->legacyWorkspace(['name' => 'Existing historical team', 'slug' => $slug])->assertRedirect();
            $db = DB::connection('pgsql_migrations');
            $workspace = $db->table('workspaces')->where('slug', $slug)->sole();
            foreach ([$custodian, $employee, $unappointed] as $member) {
                $db->table('workspace_memberships')->insert(['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active', 'created_at' => now()]);
            }
            $originalRead = $business['grant_refs']['business.context.read'];
            $enroll = $this->custodyBody($business, $originalRead, 'enroll', $creator, 0, $workspace->public_id);
            $enrolled = $this->postJson($base.'/internal-team', $enroll)->assertOk()->json('data');
            $appoint = $this->custodyBody($business, $originalRead, 'appoint', $custodian, 1, $workspace->public_id);
            $appointed = $this->postJson($base.'/internal-team', $appoint)->assertOk()->json('data');
            $root = $appointed['grant_refs'];
            $this->actingAs($custodian);
            $issue = $this->grantBody($root['business.context.read'], $employee, 'business.context.read', $workspace->public_id);
            $issued = $this->postJson($base.'/grants', $issue)->assertOk()->json('data');
            $write = $this->postJson($base.'/grants', $this->grantBody($root['business.context.manage'], $employee, 'business.context.manage', $workspace->public_id))
                ->assertOk()->json('data.grant_id');
            $this->actingAs($creator);
            $businessId = $db->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
            $manager = BusinessAuthorityFixture::root($businessId, $creator->id, 'business.link.manage');
            BusinessAuthorityFixture::legacyWorkspaceLink($businessId, $workspace->id, $manager, $creator->id);
            $direct = BusinessAuthorityFixture::root($businessId, $creator->id, 'business.context.read');
            $agencyRead = BusinessAuthorityFixture::insert('security_action_grants', [...(array) $direct,
                'id' => (int) $db->scalar("SELECT nextval('security_action_grants_id_seq')"), 'delegation_realm_kind' => 'agency', 'delegation_workspace_id' => $workspace->id], $creator->id);
            $agencySelection = ['realm_kind' => 'agency', 'realm_workspace_public_id' => $workspace->public_id,
                'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $agencyRead->id]]];
            $mandateBody = ['command_id' => (string) Str::uuid(), 'confirmed' => true, 'capacity' => 'client_mandate',
                'authority_reference' => 'Existing qualified historical client mandate', 'valid_until' => now()->addHour()->toIso8601String(),
                'previous_grant' => '0', 'authority_selection' => $agencySelection];
            $mandate = $this->postJson($base.'/operating-mandate', $mandateBody)->assertOk()->json('data');
            $snapshots = [];
            foreach (['business_workspace_links', 'security_action_grants', 'authority_qualification_receipts', 'business_command_receipts'] as $table) {
                $snapshots[$table] = $db->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r");
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            foreach ($snapshots as $table => $snapshot) {
                $this->assertSame($snapshot, $db->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r"), 'Upgrade must preserve '.$table);
            }
            $this->postJson($base.'/internal-team', $enroll)->assertOk()->assertJsonPath('data', $enrolled);
            $this->postJson($base.'/internal-team', $appoint)->assertOk()->assertJsonPath('data', $appointed);
            $this->postJson($base.'/operating-mandate', $mandateBody)->assertOk()->assertJsonPath('data', $mandate);
            $this->actingAs($custodian)->postJson($base.'/grants', $issue)->assertOk()->assertJsonPath('data', $issued);
            $legacySurface = $this->getJson($base.'/surface?access='.$root['business.context.read'])->assertOk()->json('data');
            $this->assertNotEmpty($legacySurface['delegation']['options']);
            foreach ($legacySurface['delegation']['options'] as $option) {
                $this->assertFalse($option['can_issue'], 'A retained Workspace path must not advertise new authority issuance.');
            }
            $choices = $this->getJson('/api/businesses')->assertOk()->json('data.items');
            $this->assertContains((string) $root['business.context.read'], array_column($choices, 'read_grant_id'), 'Existing qualified history must remain discoverable.');

            $this->actingAs($employee)->getJson($base.'/surface?access='.$issued['grant_id'])->assertOk()->assertJsonPath('data.private_history_available', false);
            $this->putJson($base.'/context', ['command_id' => (string) Str::uuid(), 'name' => 'Historical legitimate staff edit', 'business_type_id' => null,
                'expected_context' => 0, 'authority_selection' => $this->selection($write, $workspace->public_id)])->assertOk();
            $this->getJson($base.'/history?access='.$issued['grant_id'].'&kind=context')->assertForbidden();
            $this->actingAs($creator);
            $newEnroll = [...$enroll, 'command_id' => (string) Str::uuid(), 'expected_revision' => 2];
            $newAppoint = [...$appoint, 'command_id' => (string) Str::uuid(), 'recipient_public_id' => $unappointed->public_id, 'expected_revision' => 2];
            $this->postJson($base.'/internal-team', $newEnroll)->assertForbidden();
            $this->postJson($base.'/internal-team', $newAppoint)->assertForbidden();
            $this->postJson($base.'/operating-mandate', [...$mandateBody, 'command_id' => (string) Str::uuid(), 'previous_grant' => $mandate['grant_refs']['business.data.use']])->assertForbidden();
            $internalSql = 'SELECT builderad_v2_internal_team_command(?,?::uuid,?::uuid,?::uuid,?,?::uuid,?,?::jsonb)';
            foreach ([$newEnroll, $newAppoint] as $body) {
                $this->deniedSql($creator, $internalSql, [$business['business_public_id'], $body['command_id'], $body['workspace_public_id'], $body['effect'], $body['recipient_public_id'], $body['expected_revision'], json_encode($body['authority_selection'], JSON_THROW_ON_ERROR)]);
            }
            $this->deniedSql($creator, "WITH session_ref AS (SELECT ?::text) SELECT builderad_private.internal_team_admin(?::bigint,?::bigint,?::uuid,?::bigint,decode(?,'hex'))",
                [$db->table('businesses')->where('public_id', $business['business_public_id'])->value('id'), $workspace->id, $enrolled['link_ref'], $unappointed->id, hash('sha256', 'no private issuer escape')]);
            $newIssue = [...$issue, 'command_id' => (string) Str::uuid(), 'grantee_public_id' => $unappointed->public_id];
            $this->actingAs($custodian)->postJson($base.'/grants', $newIssue)->assertForbidden();
            // NULL Workspace selects the new Business-only operation, but it
            // must not turn a valid historical v1 root into its issuer.
            $escapedCustody = $this->custodyBody($business, $root['business.context.read'], 'appoint', $unappointed, 0);
            $escapedCustody['authority_selection'] = $this->selection($root['business.context.read'], $workspace->public_id);
            $this->postJson($base.'/internal-team', $escapedCustody)->assertForbidden();
            $this->deniedSql($custodian, $internalSql, [$business['business_public_id'], $escapedCustody['command_id'], null, 'appoint',
                $unappointed->public_id, 0, json_encode($escapedCustody['authority_selection'], JSON_THROW_ON_ERROR)]);
            $grantSql = 'SELECT builderad_v2_business_grant_command(?,?::uuid,?::uuid,?,?::uuid,?,?,?,?,?::timestamptz,?,?::uuid,?::jsonb)';
            $this->deniedSql($custodian, $grantSql, [$business['business_public_id'], $newIssue['command_id'], 'issue', $unappointed->public_id, 'business.context.read', $newIssue['parent_grant_id'], null, 0, null, 'false', $workspace->public_id, json_encode($newIssue['authority_selection'], JSON_THROW_ON_ERROR)]);
            $operatingSql = 'SELECT builderad_v2_business_operating_mandate(?,?::uuid,?::uuid,?::boolean,?,?,?::timestamptz,?,?::jsonb)';
            $this->deniedSql($creator, $operatingSql, [$business['business_public_id'], (string) Str::uuid(), 'true', 'client_mandate', $mandateBody['authority_reference'], $mandateBody['valid_until'], $mandate['grant_refs']['business.data.use'], json_encode($agencySelection, JSON_THROW_ON_ERROR)]);
            $this->actingAs($creator);
            $this->postJson($base.'/internal-team', $this->custodyBody($business, $originalRead, 'remove', $custodian, 2, $workspace->public_id))->assertOk();
            $this->actingAs($employee)->getJson($base.'/surface?access='.$issued['grant_id'])->assertForbidden();
            $this->actingAs($creator)->getJson($base.'/surface?access='.$originalRead)->assertOk();
            $this->postJson($base.'/internal-team', $this->custodyBody($business, $originalRead, 'end', null, 3, $workspace->public_id))->assertOk();
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
        } finally {
            $reset->install();
        }
    }
}
