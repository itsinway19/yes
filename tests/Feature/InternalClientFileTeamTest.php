<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\DisposableDatabaseReset;
use Tests\Support\InquiryEvidenceFixture;
use Tests\TestCase;

final class InternalClientFileTeamTest extends TestCase
{
    use ApplicationContractAssertions;
    use InquiryEvidenceFixture { selection as private inquirySelection; }

    private function selection(int|string $grant, ?string $workspace = null): array
    {
        return ['realm_kind' => $workspace ? 'internal_team' : 'direct', 'realm_workspace_public_id' => $workspace,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => (int) $grant]]];
    }

    private function team(User $creator, string $slug, array $staff = []): object
    {
        $this->actingAs($creator);
        if (! DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $creator->id)->where('action', 'workspace.create')->exists()) {
            app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($creator, 1);
        }
        $this->post('/workspaces', ['name' => $slug, 'slug' => $slug])->assertRedirect();
        $workspace = DB::connection('pgsql_migrations')->table('workspaces')->where('slug', $slug)->sole();
        foreach ($staff as $user) {
            DB::connection('pgsql_migrations')->table('workspace_memberships')->insert(['workspace_id' => $workspace->id,
                'user_id' => $user->id, 'role' => 'member', 'status' => 'active', 'created_at' => now()]);
        }

        return $workspace;
    }

    private function file(User $creator, string $name): array
    {
        return $this->actingAs($creator)->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => $name])
            ->assertCreated()->json('data');
    }

    private function custody(array $file, object $team, string $effect, ?User $recipient, int $version, ?array $selection = null): array
    {
        return ['command_id' => (string) Str::uuid(), 'workspace_public_id' => $team->public_id, 'effect' => $effect,
            'recipient_public_id' => $recipient?->public_id, 'expected_revision' => $version,
            'authority_selection' => $selection ?? $this->selection($file['grant_refs']['business.context.read'])];
    }

    private function grant(array $root, User $employee, string $action, string $workspace): array
    {
        return ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($root[$action], $workspace),
            'effect' => 'issue', 'grantee_public_id' => $employee->public_id, 'action' => $action,
            'parent_grant_id' => (int) $root[$action], 'grant_id' => null, 'expected_grant' => 0,
            'expires_at' => now()->addHour()->toIso8601String(), 'delegable' => false, 'realm_workspace_public_id' => $workspace];
    }

    public function test_three_client_files_two_staff_and_unknown_receipt_replay_preserve_exact_internal_authority(): void
    {
        $manager = User::factory()->create();
        $employees = [User::factory()->create(), User::factory()->create()];
        $team = $this->team($manager, 'client-team', $employees);
        $records = [];
        for ($i = 0; $i < 3; $i++) {
            $file = $this->file($manager, 'Client file '.$i);
            $url = '/api/businesses/'.$file['business_public_id'];
            $enroll = $this->custody($file, $team, 'enroll', $manager, 0);
            $receipt = $this->postJson($url.'/internal-team', $enroll)->assertOk()->assertJsonPath('data.state', 'active')->json();
            $this->assertApplicationContract('InternalTeamReceipt', $receipt['data']);
            $teamSurface = $this->getJson($url.'/internal-team?access='.$file['grant_refs']['business.context.read'])->assertOk()->json('data');
            $this->assertApplicationContract('InternalTeamSurface', $teamSurface);
            $this->postJson($url.'/internal-team', $enroll)->assertOk()->assertExactJson($receipt);
            $root = $receipt['data']['grant_refs'];
            $employee = $employees[$i % 2];
            $issued = [];
            foreach (['business.context.read', 'business.context.manage'] as $action) {
                $command = $this->grant($root, $employee, $action, $team->public_id);
                $grant = $this->postJson($url.'/grants', $command)->assertOk()->json('data');
                $this->postJson($url.'/grants', $command)->assertOk()->assertJsonPath('data.grant_id', $grant['grant_id']);
                $issued[$action] = $grant['grant_id'];
            }
            $this->actingAs($employee);
            $selection = $this->selection($issued['business.context.read'], $team->public_id);
            $this->postJson($url.'/read', ['authority_selection' => $selection])->assertOk()->assertJsonPath('data.name', 'Client file '.$i);
            $edit = ['command_id' => (string) Str::uuid(), 'name' => 'Edited by staff '.$i, 'business_type_id' => null,
                'expected_context' => 0, 'authority_selection' => $this->selection($issued['business.context.manage'], $team->public_id)];
            $ack = $this->putJson($url.'/context', $edit)->assertOk()->json();
            $this->putJson($url.'/context', $edit)->assertOk()->assertExactJson($ack);
            $this->getJson($url.'/history?access='.$issued['business.context.read'].'&kind=context')->assertForbidden();
            $this->getJson($url.'/legacy-history?access='.$issued['business.context.read'].'&kind=context')->assertForbidden();
            $this->postJson($url.'/grants', $this->grant($issued, $employees[1 - ($i % 2)], 'business.context.read', $team->public_id))->assertForbidden();
            $this->actingAs($employees[1 - ($i % 2)])->postJson($url.'/read', ['authority_selection' => $selection])->assertForbidden();
            $this->actingAs($manager);
            $bad = $this->grant($root, $employee, 'business.context.read', $team->public_id);
            $bad['delegable'] = true;
            $this->postJson($url.'/grants', $bad)->assertForbidden();
            $bad['delegable'] = false;
            $bad['realm_workspace_public_id'] = null;
            $this->postJson($url.'/grants', $bad)->assertForbidden();
            $records[] = [$file, $root, $issued];
        }
        [$first, $root, $issued] = $records[0];
        $url = '/api/businesses/'.$first['business_public_id'];
        $revoke = $this->grant($root, $employees[0], 'business.context.read', $team->public_id);
        $revoke['effect'] = 'revoke';
        $revoke['parent_grant_id'] = null;
        $revoke['grant_id'] = (int) $issued['business.context.read'];
        $revoke['expected_grant'] = 1;
        $this->postJson($url.'/grants', $revoke)->assertOk();
        $this->actingAs($employees[0])->postJson($url.'/read', ['authority_selection' => $this->selection($issued['business.context.read'], $team->public_id)])->assertForbidden();
        [$third, , $thirdIssued] = $records[2];
        $this->postJson('/api/businesses/'.$third['business_public_id'].'/read', ['authority_selection' => $this->selection($thirdIssued['business.context.read'], $team->public_id)])->assertOk();
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(3, $db->table('users')->count());
        $this->assertSame(0, $db->table('business_workspace_links')->where('kind', 'agency')->count());
        $this->assertSame(0, $db->table('security_action_grants')->whereIn('scope_kind', ['source', 'client_data', 'data_use'])->count());
        $this->assertSame(0, $db->table('security_action_grants')->where('business_id', $db->table('businesses')->where('public_id', $first['business_public_id'])->value('id'))
            ->whereIn('action', ['business.data.use', 'business.inquiry_evidence.read', 'business.link.manage'])->where('delegation_realm_kind', 'internal_team')->count());
    }

    public function test_explicit_custodian_continuity_survives_creator_departure_without_workspace_owner_takeover(): void
    {
        $creator = User::factory()->create();
        $backup = User::factory()->create();
        $newOwner = User::factory()->create();
        $team = $this->team($creator, 'continuity-team', [$backup, $newOwner]);
        $file = $this->file($creator, 'Continuity file');
        $url = '/api/businesses/'.$file['business_public_id'];
        $this->postJson($url.'/internal-team', $this->custody($file, $team, 'enroll', $creator, 0))->assertOk();
        $this->postJson($url.'/internal-team', $this->custody($file, $team, 'appoint', $backup, 1))->assertOk();
        $db = DB::connection('pgsql_migrations');
        // Test fixture models an independently qualified organizational ownership change.
        // The internal policy does not issue that organizational authority.
        $db->table('workspace_ownerships')->where('workspace_id', $team->id)->update(['responsible_owner_user_id' => $newOwner->id]);
        $db->table('workspace_memberships')->where('workspace_id', $team->id)->where('user_id', $newOwner->id)->update(['role' => 'owner']);
        $db->table('workspace_memberships')->where('workspace_id', $team->id)->where('user_id', $creator->id)->update(['status' => 'revoked', 'revoked_at' => now()]);
        $choices = $this->actingAs($backup)->getJson('/api/businesses')->assertOk()->json('data.items');
        $this->assertCount(1, $choices);
        $this->assertSame('internal_team', $choices[0]['realm_kind']);
        $this->postJson($url.'/read', ['authority_selection' => $this->selection($choices[0]['read_grant_id'], $team->public_id)])->assertOk();
        $this->actingAs($newOwner)->getJson('/api/businesses')->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson($url.'/internal-team', $this->custody($file, $team, 'appoint', $newOwner, 2))->assertForbidden();
        // Creator retains only its own direct file basis and explicitly appoints recovery.
        $this->actingAs($creator)->postJson($url.'/internal-team', $this->custody($file, $team, 'appoint', $newOwner, 2))->assertOk();
        $this->actingAs($newOwner)->getJson('/api/businesses')->assertOk()->assertJsonCount(1, 'data.items');
        $db->table('workspace_memberships')->where('workspace_id', $team->id)->where('user_id', $backup->id)->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->actingAs($backup)->postJson($url.'/read', ['authority_selection' => $this->selection($choices[0]['read_grant_id'], $team->public_id)])->assertForbidden();
    }

    public function test_two_internal_team_realms_end_independently_and_do_not_become_external_agency_authority(): void
    {
        $creator = User::factory()->create();
        $staff = User::factory()->create();
        $a = $this->team($creator, 'internal-realm-a', [$staff]);
        $b = $this->team($creator, 'internal-realm-b', [$staff]);
        $file = $this->file($creator, 'Shared file across teams');
        $url = '/api/businesses/'.$file['business_public_id'];
        $paths = [];
        foreach ([$a, $b] as $team) {
            $root = $this->postJson($url.'/internal-team', $this->custody($file, $team, 'enroll', $creator, 0))->assertOk()->json('data.grant_refs');
            $paths[] = $this->postJson($url.'/grants', $this->grant($root, $staff, 'business.context.read', $team->public_id))->assertOk()->json('data.grant_id');
        }
        $this->actingAs($staff)->postJson($url.'/read', ['authority_selection' => $this->selection($paths[0], $a->public_id)])->assertOk();
        $this->postJson($url.'/read', ['authority_selection' => $this->selection($paths[0], $b->public_id)])->assertForbidden();
        $forged = $this->selection($paths[0], $a->public_id);
        $forged['realm_kind'] = 'agency';
        $this->postJson($url.'/read', ['authority_selection' => $forged])->assertForbidden();
        $this->actingAs($creator)->postJson($url.'/internal-team', $this->custody($file, $a, 'end', null, 1))->assertOk();
        $this->actingAs($staff)->postJson($url.'/read', ['authority_selection' => $this->selection($paths[0], $a->public_id)])->assertForbidden();
        $this->postJson($url.'/read', ['authority_selection' => $this->selection($paths[1], $b->public_id)])->assertOk();
        $this->actingAs(User::factory()->create())->getJson('/api/businesses')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson($url.'/internal-team?access='.$file['grant_refs']['business.context.read'])->assertForbidden();
        $this->actingAs($creator);
        $this->postJson($url.'/workspace-links', ['command_id' => (string) Str::uuid(), 'workspace_public_id' => $a->public_id,
            'kind' => 'agency', 'effect' => 'add', 'expected_revision' => 0,
            'authority_selection' => $this->selection($file['grant_refs']['business.link.manage'])])->assertForbidden();
    }

    public function test_custodian_withdrawal_invalidates_its_staff_chain_without_reviving_it_on_reappointment(): void
    {
        $creator = User::factory()->create();
        $backup = User::factory()->create();
        $staff = User::factory()->create();
        $team = $this->team($creator, 'custodian-withdrawal-team', [$backup, $staff]);
        $file = $this->file($creator, 'Custody withdrawal file');
        $url = '/api/businesses/'.$file['business_public_id'];
        $creatorRoot = $this->postJson($url.'/internal-team', $this->custody($file, $team, 'enroll', $creator, 0))->assertOk()->json('data.grant_refs');
        $appointed = $this->postJson($url.'/internal-team', $this->custody($file, $team, 'appoint', $backup, 1))->assertOk()->json('data.grant_refs');
        $this->actingAs($backup);
        $leaf = $this->postJson($url.'/grants', $this->grant($appointed, $staff, 'business.context.read', $team->public_id))->assertOk()->json('data.grant_id');
        $this->actingAs($staff)->postJson($url.'/read', ['authority_selection' => $this->selection($leaf, $team->public_id)])->assertOk();
        $this->actingAs($creator)->postJson($url.'/internal-team', $this->custody($file, $team, 'remove', $backup, 2))->assertOk();
        $this->actingAs($backup)->postJson($url.'/read', ['authority_selection' => $this->selection($appointed['business.context.read'], $team->public_id)])->assertForbidden();
        $this->actingAs($staff)->postJson($url.'/read', ['authority_selection' => $this->selection($leaf, $team->public_id)])->assertForbidden();
        $this->actingAs($creator)->postJson($url.'/read', ['authority_selection' => $this->selection($creatorRoot['business.context.read'], $team->public_id)])->assertOk();
        $replacement = $this->postJson($url.'/internal-team', $this->custody($file, $team, 'appoint', $backup, 3))->assertOk()->json('data.grant_refs');
        $this->assertNotSame($appointed['business.context.read'], $replacement['business.context.read']);
        $this->actingAs($staff)->postJson($url.'/read', ['authority_selection' => $this->selection($leaf, $team->public_id)])->assertForbidden();
    }

    public function test_unauthorized_custody_and_raw_database_access_remain_denied(): void
    {
        $creator = User::factory()->create();
        $staff = User::factory()->create();
        $outsider = User::factory()->create();
        $team = $this->team($creator, 'restricted-file-team', [$staff]);
        $file = $this->file($creator, 'Restricted file');
        $url = '/api/businesses/'.$file['business_public_id'];
        $this->postJson($url.'/internal-team', $this->custody($file, $team, 'enroll', $staff, 0))->assertForbidden();
        $this->postJson($url.'/internal-team', $this->custody($file, $team, 'enroll', $creator, 0))->assertOk();
        $this->postJson($url.'/internal-team', $this->custody($file, $team, 'appoint', $outsider, 1))->assertForbidden();
        $db = DB::connection('pgsql_migrations');
        $this->assertFalse($db->scalar("SELECT has_function_privilege('builderad_app','builderad_private.internal_team_admin(bigint,bigint,uuid,bigint,bytea)','EXECUTE')"));
        $this->assertFalse($db->scalar("SELECT has_table_privilege('builderad_app','authority_qualification_receipts','INSERT')"));
        $this->assertFalse($db->scalar("SELECT has_schema_privilege('builderad_scope_executor','public','CREATE')"));
    }

    public function test_membership_revocation_during_observed_row_wait_denies_staff_write_and_leaves_no_receipt(): void
    {
        $creator = User::factory()->create();
        $staff = User::factory()->create();
        $team = $this->team($creator, 'waiting-team', [$staff]);
        $file = $this->file($creator, 'Before membership wait');
        $url = '/api/businesses/'.$file['business_public_id'];
        $root = $this->postJson($url.'/internal-team', $this->custody($file, $team, 'enroll', $creator, 0))->assertOk()->json('data.grant_refs');
        $issued = $this->postJson($url.'/grants', $this->grant($root, $staff, 'business.context.manage', $team->public_id))->assertOk()->json('data.grant_id');
        $this->actingAs($staff)->getJson('/api/businesses')->assertOk();
        $request = Request::create('/api/businesses');
        $request->setUserResolver(fn () => $staff);
        $request->setLaravelSession(app('session')->driver());
        $run = 'internal-member-wait-'.Str::uuid();
        $dir = storage_path('framework/testing');
        $path = $dir.'/'.$run.'.json';
        $release = $path.'.release';
        $ready = $path.'.ready';
        $command = (string) Str::uuid();
        file_put_contents($path, json_encode(['run' => $run, 'actor' => $staff->id, 'session' => SessionAuthority::sessionKey($request),
            'business' => $file['business_public_id'], 'command' => $command, 'operation' => 'context', 'name' => 'Must not commit',
            'expected' => 0, 'selection' => $this->selection($issued, $team->public_id), 'release' => $release, 'ready' => $ready], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(120);
        $db = DB::connection('pgsql_migrations');
        $evidence = ['run' => $run];
        try {
            $worker->start();
            $this->observe(fn () => is_file($ready), $worker);
            $pid = json_decode(file_get_contents($ready), true, flags: JSON_THROW_ON_ERROR)['pid'];
            $db->beginTransaction();
            $holder = (int) $db->scalar('SELECT pg_backend_pid()');
            $db->selectOne('SELECT user_id FROM workspace_memberships WHERE workspace_id=? AND user_id=? FOR UPDATE', [$team->id, $staff->id]);
            file_put_contents($release, 'start');
            $wait = $this->observe(fn () => DB::selectOne("SELECT pid,wait_event_type,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE pid=? AND wait_event_type='Lock'", [$pid]), $worker);
            $this->assertStringContainsString((string) $holder, $wait->blockers);
            $evidence['wait'] = $wait;
            $evidence['holder'] = $holder;
            $db->table('workspace_memberships')->where('workspace_id', $team->id)->where('user_id', $staff->id)->update(['status' => 'revoked', 'revoked_at' => now()]);
            $db->commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertSame(0, $db->table('business_command_receipts')->where('command_id', $command)->count());
            $this->assertSame('Before membership wait', $db->table('business_context_heads')->value('display_name'));
        } finally {
            if ($db->transactionLevel()) {
                $db->rollBack();
            }
            $evidence['stdout'] = $worker->getOutput();
            $evidence['stderr'] = $worker->getErrorOutput();
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
            file_put_contents($dir.'/'.$run.'-evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ([$path, $release, $ready] as $artifact) {
                if (is_file($artifact)) {
                    unlink($artifact);
                }
            }
        }
    }

    public function test_preexisting_private_inquiry_and_history_are_not_shared_by_team_activation(): void
    {
        $f = $this->fixture();
        $staff = User::factory()->create();
        $team = $this->team($f['user'], 'private-record-team', [$staff]);
        $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $this->value(),
            $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record']));
        $db = DB::connection('pgsql_migrations');
        $before = $db->scalar('SELECT jsonb_agg(to_jsonb(r) ORDER BY declaration_id,revision)::text FROM inquiry_evidence_revisions r');
        $url = '/api/businesses/'.$f['business'];
        $root = $this->postJson($url.'/internal-team', $this->custody($f['draft'], $team, 'enroll', $f['user'], 0))->assertOk()->json('data.grant_refs');
        $issued = $this->postJson($url.'/grants', $this->grant($root, $staff, 'business.context.read', $team->public_id))->assertOk()->json('data.grant_id');
        $this->actingAs($staff)->getJson($url.'/surface?access='.$issued)->assertOk();
        $this->getJson($url.'/inquiry-evidence?access='.$f['enrolled']['grants']['business.inquiry_evidence.read'])->assertForbidden();
        $this->getJson($url.'/inquiry-evidence/eligibility?access='.$issued)->assertForbidden();
        $this->getJson($url.'/history?access='.$issued.'&kind=context')->assertForbidden();
        $this->assertSame($before, $db->scalar('SELECT jsonb_agg(to_jsonb(r) ORDER BY declaration_id,revision)::text FROM inquiry_evidence_revisions r'));
        $this->actingAs($f['user'])->getJson($url.'/inquiry-evidence?access='.$f['enrolled']['grants']['business.inquiry_evidence.read'])->assertOk();
    }

    public function test_populated_pre560_schema_upgrades_without_auto_enrollment_or_rewriting_existing_authority(): void
    {
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => strcmp(basename($path), '0001_01_01_000560_internal_client_file_team.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $db = DB::connection('pgsql_migrations');
            // Install the actual shipped finite pre560 constraints, without editing
            // historical installation DDL to anticipate the new issuer policy.
            $constraint = $db->scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid='security_action_grants'::regclass AND conname='security_action_grants_scope_check'");
            $this->assertStringNotContainsString('internal_team', $constraint);
            $f = $this->fixture();
            $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $this->value(), $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record']));
            $snapshots = [];
            foreach (['businesses', 'business_workspace_links', 'authority_qualification_receipts', 'security_action_grants', 'business_command_receipts', 'inquiry_evidence_revisions'] as $table) {
                $snapshots[$table] = $db->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r");
            }
            $db->unprepared("CREATE FUNCTION public.builderad_test_internal_team_drift() RETURNS integer LANGUAGE sql SECURITY DEFINER SET search_path=pg_catalog,pg_temp AS 'SELECT 1'");
            try {
                $migration = require database_path('migrations/0001_01_01_000560_internal_client_file_team.php');
                $this->denied(fn () => DB::usingConnection('pgsql_migrations', fn () => $db->transaction(fn () => $migration->up())));
            } finally {
                $db->statement('DROP FUNCTION public.builderad_test_internal_team_drift()');
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            foreach ($snapshots as $table => $snapshot) {
                $this->assertSame($snapshot, $db->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r"));
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            $this->assertSame(0, $db->table('business_workspace_links')->where('kind', 'internal_team')->count());
            $this->assertSame(0, $db->table('security_action_grants')->where('delegation_realm_kind', 'internal_team')->count());
            $this->assertTrue($db->transaction(function () use ($db) {
                $db->statement('SET LOCAL ROLE builderad_scope_executor');

                return $db->scalar('SELECT builderad_private.legacy_catalog_digest()=builderad_private.legacy_installation_digest()');
            }));
        } finally {
            $reset->install();
        }
    }

    private function observe(callable $probe, Process $worker): mixed
    {
        $deadline = microtime(true) + 60;
        do {
            if ($result = $probe()) {
                return $result;
            }
            if (! $worker->isRunning()) {
                $this->fail('Worker exited before contention proof: '.$worker->getOutput().$worker->getErrorOutput());
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Required real lock observation missing: '.$worker->getOutput().$worker->getErrorOutput());
    }
}
