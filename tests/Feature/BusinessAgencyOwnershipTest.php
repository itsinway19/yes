<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\DisposableDatabaseReset;
use Tests\TestCase;

final class BusinessAgencyOwnershipTest extends TestCase
{
    private function selection(int|string $grant): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => (int) $grant]]];
    }

    private function business(User $owner, string $name): array
    {
        $business = $this->actingAs($owner)->postJson('/api/businesses', [
            'command_id' => (string) Str::uuid(), 'name' => $name,
        ])->assertCreated()->json('data');
        $business['team'] = $this->postJson('/api/businesses/'.$business['business_public_id'].'/direct-team', [
            'command_id' => (string) Str::uuid(),
            'authority_selection' => $this->selection($business['grant_refs']['business.context.read']),
        ])->assertOk()->json('data.grant_refs');

        return $business;
    }

    private function employee(User $owner, array $business, User $employee): int
    {
        return $this->actingAs($owner)->postJson('/api/businesses/'.$business['business_public_id'].'/grants', [
            'command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($business['team']['business.context.read']),
            'effect' => 'issue', 'grantee_public_id' => $employee->public_id, 'action' => 'business.context.read',
            'parent_grant_id' => (int) $business['team']['business.context.read'], 'grant_id' => null,
            'expected_grant' => 0, 'expires_at' => null, 'delegable' => false, 'realm_workspace_public_id' => null,
        ])->assertOk()->json('data.grant_id');
    }

    private function agencyCommand(array $client, array $agency, User $recipient, int|string $parent, int|string $membership, string $effect): array
    {
        return ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($parent),
            'effect' => $effect, 'agency_business_public_id' => $agency['business_public_id'],
            'recipient_public_id' => $recipient->public_id, 'membership_grant_id' => (int) $membership,
            'action' => 'business.context.read', 'parent_grant_id' => (int) $parent,
            'expires_at' => now()->addHour()->toIso8601String(), 'confirmed' => true];
    }

    private function revoke(User $issuer, array $business, User $recipient, int|string $parent, int $grant): void
    {
        $this->actingAs($issuer)->postJson('/api/businesses/'.$business['business_public_id'].'/grants', [
            'command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($parent),
            'effect' => 'revoke', 'grantee_public_id' => $recipient->public_id, 'action' => 'business.context.read',
            'parent_grant_id' => null, 'grant_id' => $grant, 'expected_grant' => 1,
            'expires_at' => null, 'delegable' => false, 'realm_workspace_public_id' => null,
        ])->assertOk();
    }

    public function test_lost_agency_responses_replay_after_expiry_or_employee_withdrawal_without_new_grants(): void
    {
        $clientOwner = User::factory()->create();
        $agencyOwner = User::factory()->create();
        $staff = User::factory()->create();
        $client = $this->business($clientOwner, 'Replay client');
        $agency = $this->business($agencyOwner, 'Replay agency');
        $membership = $this->employee($agencyOwner, $agency, $staff);
        $db = DB::connection('pgsql_migrations');
        $base = '/api/businesses/'.$client['business_public_id'];
        $mandateBody = $this->agencyCommand($client, $agency, $agencyOwner, $client['team']['business.context.read'], $agency['grant_refs']['business.context.read'], 'mandate');
        $mandate = $this->actingAs($clientOwner)->postJson($base.'/agency-grants', $mandateBody)->assertOk()->json('data');
        $assignBody = $this->agencyCommand($client, $agency, $staff, $mandate['grant_id'], $membership, 'assign');
        $assignBody['expires_at'] = (new \DateTimeImmutable($db->scalar('SELECT clock_timestamp()::text')))->modify('+6 seconds')->format('Y-m-d\TH:i:s.uP');
        $assigned = $this->actingAs($agencyOwner)->postJson($base.'/agency-grants', $assignBody)->assertOk()->json('data');
        $this->revoke($agencyOwner, $agency, $staff, $agency['team']['business.context.read'], $membership);
        $count = $db->table('security_action_grants')->count();
        $this->assertSame($assigned, $this->postJson($base.'/agency-grants', $assignBody)->assertOk()->json('data'));
        $db->select('SELECT pg_sleep(greatest(0,extract(epoch FROM ?::timestamptz-clock_timestamp()))+0.05)', [$assignBody['expires_at']]);
        $this->assertSame($assigned, $this->postJson($base.'/agency-grants', $assignBody)->assertOk()->json('data'));
        $this->postJson($base.'/agency-grants', [...$assignBody, 'expires_at' => $mandateBody['expires_at']])->assertConflict();
        $this->postJson($base.'/agency-grants', [...$assignBody, 'command_id' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($staff)->postJson($base.'/agency-grants', $assignBody)->assertForbidden();
        $this->getJson($base.'/surface?access='.$assigned['grant_id'])->assertForbidden();
        $this->assertSame($count, $db->table('security_action_grants')->count());
        $this->assertSame(1, $db->table('business_command_receipts')->where('command_id', $assignBody['command_id'])->count());
        $expired = $this->agencyCommand($client, $agency, $agencyOwner, $client['team']['business.context.read'], $agency['grant_refs']['business.context.read'], 'mandate');
        $expired['expires_at'] = (new \DateTimeImmutable($db->scalar('SELECT clock_timestamp()::text')))->modify('+6 seconds')->format('Y-m-d\TH:i:s.uP');
        $short = $this->actingAs($clientOwner)->postJson($base.'/agency-grants', $expired)->assertOk()->json('data');
        $db->select('SELECT pg_sleep(greatest(0,extract(epoch FROM ?::timestamptz-clock_timestamp()))+0.05)', [$expired['expires_at']]);
        $count = $db->table('security_action_grants')->count();
        $this->assertSame($short, $this->postJson($base.'/agency-grants', $expired)->assertOk()->json('data'));
        $this->postJson($base.'/agency-grants', [...$expired, 'command_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertSame($count, $db->table('security_action_grants')->count());
        $this->assertSame($mandate, $this->postJson($base.'/agency-grants', $mandateBody)->assertOk()->json('data'));
        BusinessAuthorityFixture::revokeQualification($db->table('security_action_grants')->where('id', $client['team']['business.context.read'])->value('qualification_id'), $clientOwner->id);
        $this->postJson($base.'/agency-grants', $mandateBody)->assertForbidden();
        $this->actingAs($agencyOwner)->postJson($base.'/agency-grants', $assignBody)->assertForbidden();
    }

    public function test_business_employee_and_named_agency_assignment_need_no_workspace_and_client_withdrawal_preserves_independent_rights(): void
    {
        $clientOwner = User::factory()->create();
        $agencyOwner = User::factory()->create();
        $employee = User::factory()->create();
        $unassigned = User::factory()->create();
        $client = $this->business($clientOwner, 'First client');
        $other = $this->business($clientOwner, 'Second client');
        // Any independent Business may receive a mandate. No agency account,
        // organizational membership or commercial-plan classification is enrolled.
        $agency = $this->business($agencyOwner, 'Partner shop');
        $membership = $this->employee($agencyOwner, $agency, $employee);
        $this->employee($agencyOwner, $agency, $unassigned);
        $independent = $this->employee($clientOwner, $client, $employee);
        $base = '/api/businesses/'.$client['business_public_id'];
        $this->actingAs($clientOwner)->getJson($base.'/surface?access='.$client['grant_refs']['business.context.read'])->assertOk()
            ->assertJsonPath('data.agency_task.can_mandate', true)->assertJsonPath('data.agency_task.can_assign', false);
        $options = $this->actingAs($clientOwner)->getJson($base.'/agency-options?access='.$client['grant_refs']['business.context.read'].'&agency='.$agency['business_public_id'])
            ->assertOk()->assertJsonPath('data.effect', 'mandate')->assertJsonPath('data.scope', 'current_declaration_agency_only')->json('data');
        $this->assertSame($agencyOwner->public_id, $options['recipient']['public_id']);
        $this->assertCount(5, $options['options']);
        $this->assertArrayNotHasKey('name', $options['recipient']);
        $mandateBody = $this->agencyCommand($client, $agency, $agencyOwner, $client['team']['business.context.read'], $agency['grant_refs']['business.context.read'], 'mandate');
        $mandate = $this->actingAs($clientOwner)->postJson($base.'/agency-grants', $mandateBody)->assertOk()->json('data.grant_id');
        $this->postJson($base.'/agency-grants', $mandateBody)->assertOk()->assertJsonPath('data.grant_id', $mandate);
        $this->actingAs($agencyOwner)->getJson($base.'/surface?access='.$mandate)->assertOk()
            ->assertJsonPath('data.agency_business_public_id', $agency['business_public_id'])
            ->assertJsonPath('data.agency_task.can_mandate', false)->assertJsonPath('data.agency_task.can_assign', true)
            ->assertJsonPath('data.delegation.options.0.can_issue', false)
            ->assertJsonPath('data.delegation.options.0.can_delegate_onward', false);
        $assignmentBody = $this->agencyCommand($client, $agency, $employee, $mandate, $membership, 'assign');
        $assignmentOptions = $this->actingAs($agencyOwner)->getJson($base.'/agency-options?access='.$mandate.'&agency='.$agency['business_public_id'].'&recipient='.$employee->public_id)
            ->assertOk()->assertJsonPath('data.effect', 'assign')->assertJsonPath('data.recipient.membership_grant_id', $membership)->json('data.options');
        $this->assertCount(1, $assignmentOptions);
        $this->assertSame($mandate, $assignmentOptions[0]['parent_grant_id']);
        $assignmentBody['expires_at'] = $mandateBody['expires_at'];
        $assignment = $this->actingAs($agencyOwner)->postJson($base.'/agency-grants', $assignmentBody)->assertOk()->json('data.grant_id');
        $this->actingAs($employee)->getJson($base.'/surface?access='.$assignment)->assertOk()->assertJsonPath('data.private_history_available', false)
            ->assertJsonPath('data.agency_task.can_mandate', false)->assertJsonPath('data.agency_task.can_assign', false);
        $this->getJson('/api/businesses/'.$other['business_public_id'].'/surface?access='.$assignment)->assertForbidden();
        $this->actingAs($unassigned)->getJson($base.'/surface?access='.$assignment)->assertForbidden();
        $this->getJson($base.'/agency-options?access='.$assignment.'&agency='.$agency['business_public_id'].'&recipient='.$employee->public_id)->assertForbidden();
        $this->actingAs($employee)->postJson($base.'/agency-grants', $assignmentBody)->assertForbidden();
        $this->getJson($base.'/history?access='.$assignment.'&kind=context')->assertForbidden();
        $db = DB::connection('pgsql_migrations');
        $agencyId = $db->table('businesses')->where('public_id', $agency['business_public_id'])->value('id');
        $cohort = $db->table('migration_cohort_members')->where('business_id', $agencyId)->value('cohort_id');
        $db->scalar("SELECT builderad_v2_cohort_transition(?::uuid,?::uuid,'V2_ACTIVE',0,'RECOVERY_HOLD',NULL,decode(?,'hex'))", [(string) Str::uuid(), $cohort, hash('sha256', 'agency-hold')]);
        $this->getJson($base.'/surface?access='.$assignment)->assertForbidden();
        $this->getJson($base.'/surface?access='.$independent)->assertOk();
        $this->actingAs($agencyOwner)->getJson($base.'/agency-options?access='.$mandate.'&agency='.$agency['business_public_id'].'&recipient='.$employee->public_id)->assertForbidden();
        $db->scalar("SELECT builderad_v2_cohort_transition(?::uuid,?::uuid,'RECOVERY_HOLD',1,'V2_ACTIVE',NULL,decode(?,'hex'))", [(string) Str::uuid(), $cohort, hash('sha256', 'agency-resume')]);
        $this->actingAs($employee)->getJson($base.'/surface?access='.$assignment)->assertOk();
        $this->revoke($clientOwner, $client, $agencyOwner, $client['team']['business.context.read'], $mandate);
        $this->actingAs($employee)->getJson($base.'/surface?access='.$assignment)->assertForbidden();
        $this->getJson($base.'/surface?access='.$independent)->assertOk();
        $db = DB::connection('pgsql_migrations');
        $this->assertSame(0, $db->table('workspaces')->count());
        $this->assertSame(0, $db->table('security_action_grants')->whereIn('scope_kind', ['source', 'client_data', 'data_use'])->count());
        $this->assertSame('active', $db->table('security_action_grants')->where('id', $assignment)->value('status'), 'Withdrawal denies the path without rewriting descendant history.');
    }

    public function test_departure_and_rejoining_do_not_reactivate_an_old_assignment_and_ordinary_grants_cannot_strip_its_basis(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $employee = User::factory()->create();
        $outsider = User::factory()->create();
        $client = $this->business($owner, 'Client');
        $agency = $this->business($manager, 'Agency');
        $membership = $this->employee($manager, $agency, $employee);
        $base = '/api/businesses/'.$client['business_public_id'];
        $body = $this->agencyCommand($client, $agency, $manager, $client['team']['business.context.read'], $agency['grant_refs']['business.context.read'], 'mandate');
        $mandate = $this->actingAs($owner)->postJson($base.'/agency-grants', $body)->assertOk()->json('data.grant_id');
        $assignmentBody = $this->agencyCommand($client, $agency, $employee, $mandate, $membership, 'assign');
        $assignmentBody['expires_at'] = $body['expires_at'];
        $assignment = $this->actingAs($manager)->postJson($base.'/agency-grants', $assignmentBody)->assertOk()->json('data.grant_id');
        $this->postJson($base.'/grants', ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($mandate),
            'effect' => 'issue', 'grantee_public_id' => $outsider->public_id, 'action' => 'business.context.read',
            'parent_grant_id' => $mandate, 'grant_id' => null, 'expected_grant' => 0, 'expires_at' => $body['expires_at'],
            'delegable' => false, 'realm_workspace_public_id' => null])->assertForbidden();
        $this->revoke($manager, $agency, $employee, $agency['team']['business.context.read'], $membership);
        $this->actingAs($employee)->getJson($base.'/surface?access='.$assignment)->assertForbidden();
        $replacement = $this->employee($manager, $agency, $employee);
        $this->assertNotSame($membership, $replacement);
        $this->actingAs($employee)->getJson($base.'/surface?access='.$assignment)->assertForbidden();
        $beforeReplay = DB::connection('pgsql_migrations')->table('security_action_grants')->count();
        $this->actingAs($manager)->postJson($base.'/agency-grants', $assignmentBody)->assertOk()->assertJsonPath('data.grant_id', $assignment);
        $this->postJson($base.'/agency-grants', [...$assignmentBody, 'command_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertSame($beforeReplay, DB::connection('pgsql_migrations')->table('security_action_grants')->count());
        $this->actingAs($employee)->getJson($base.'/surface?access='.$assignment)->assertForbidden();
    }

    public function test_expired_or_foreign_employee_basis_and_source_actions_are_denied_without_new_authority(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $employee = User::factory()->create();
        $client = $this->business($owner, 'Client');
        $agency = $this->business($manager, 'Agency');
        $foreign = $this->business($manager, 'Other agency');
        $membership = $this->employee($manager, $agency, $employee);
        $foreignMembership = $this->employee($manager, $foreign, $employee);
        $base = '/api/businesses/'.$client['business_public_id'];
        $body = $this->agencyCommand($client, $agency, $manager, $client['team']['business.context.read'], $agency['grant_refs']['business.context.read'], 'mandate');
        $this->actingAs($owner)->postJson($base.'/agency-grants', [...$body, 'action' => 'business.data.use'])->assertUnprocessable();
        $this->postJson($base.'/agency-grants', [...$body, 'confirmed' => false])->assertUnprocessable();
        $mandate = $this->postJson($base.'/agency-grants', $body)->assertOk()->json('data.grant_id');
        $assignment = $this->agencyCommand($client, $agency, $employee, $mandate, $membership, 'assign');
        $assignment['expires_at'] = $body['expires_at'];
        $this->actingAs($manager)->postJson($base.'/agency-grants', [...$assignment, 'membership_grant_id' => $foreignMembership])->assertForbidden();
        $this->postJson($base.'/agency-grants', [...$assignment, 'expires_at' => now()->subMinute()->toIso8601String()])->assertForbidden();
        $this->postJson($base.'/agency-grants', [...$assignment, 'agency_business_public_id' => $foreign['business_public_id']])->assertForbidden();
        $issued = $this->postJson($base.'/agency-grants', $assignment)->assertOk()->json('data.grant_id');
        $this->postJson($base.'/agency-grants', [...$assignment, 'expires_at' => now()->addMinutes(30)->toIso8601String()])->assertConflict();
        $db = DB::connection('pgsql_migrations');
        $qualification = $db->table('security_action_grants')->where('id', $membership)->value('qualification_id');
        BusinessAuthorityFixture::revokeQualification($qualification, $manager->id);
        $this->actingAs($employee)->getJson($base.'/surface?access='.$issued)->assertForbidden();
        $this->getJson('/api/businesses')->assertOk()->assertJsonMissing(['read_grant_id' => (string) $issued]);
        $beforeReplay = $db->table('security_action_grants')->count();
        $this->actingAs($manager)->postJson($base.'/agency-grants', $assignment)->assertOk()->assertJsonPath('data.grant_id', $issued);
        $this->postJson($base.'/agency-grants', [...$assignment, 'command_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertSame($beforeReplay, $db->table('security_action_grants')->count());
        $this->actingAs($employee)->getJson($base.'/surface?access='.$issued)->assertForbidden();
    }

    public function test_employee_qualification_expiring_during_real_final_receipt_wait_rolls_back_assignment(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $employee = User::factory()->create();
        $client = $this->business($owner, 'Client');
        $agency = $this->business($manager, 'Agency');
        $membership = $this->employee($manager, $agency, $employee);
        $base = '/api/businesses/'.$client['business_public_id'];
        $body = $this->agencyCommand($client, $agency, $manager, $client['team']['business.context.read'], $agency['grant_refs']['business.context.read'], 'mandate');
        $mandate = $this->actingAs($owner)->postJson($base.'/agency-grants', $body)->assertOk()->json('data.grant_id');
        $this->actingAs($manager);
        $request = Request::create('/api/businesses');
        $request->setLaravelSession(app('session')->driver());
        $db = DB::connection('pgsql_migrations');
        $qualification = $db->table('security_action_grants')->where('id', $membership)->value('qualification_id');
        $run = 'agency-final-'.Str::uuid();
        $path = storage_path('framework/testing/'.$run.'.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $input = ['run' => $run, 'ready' => $path.'.ready', 'release' => $path.'.release', 'require_release_content' => true,
            'operation' => 'agency-grant', 'actor' => $manager->id, 'business' => $client['business_public_id'],
            'session' => SessionAuthority::sessionKey($request), 'command' => (string) Str::uuid(), 'effect' => 'assign',
            'agency' => $agency['business_public_id'], 'recipient' => $employee->public_id, 'membership' => $membership,
            'parent' => $mandate, 'selection' => $this->selection($mandate)];
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));
        $release = fopen($input['release'], 'x+b');
        config(['database.connections.agency_receipt_barrier' => config('database.connections.pgsql_migrations')]);
        $barrier = DB::connection('agency_receipt_barrier');
        $barrier->getPdo();
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $evidence = ['run' => $run];
        $wait = function (callable $condition) use ($worker) {
            $deadline = microtime(true) + 15;
            do {
                if ($result = $condition()) {
                    return $result;
                }
                if (! $worker->isRunning()) {
                    throw new \RuntimeException($worker->getOutput().$worker->getErrorOutput());
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            throw new \RuntimeException('Required actual agency contention absent: '.$worker->getOutput().$worker->getErrorOutput());
        };
        try {
            $worker->start();
            $evidence['ready'] = $wait(function () use ($input) {
                clearstatcache(true, $input['ready']);

                return is_file($input['ready']) ? json_decode(file_get_contents($input['ready']), true, flags: JSON_THROW_ON_ERROR) : false;
            });
            $barrier->beginTransaction();
            $blocker = (int) $barrier->scalar('SELECT pg_backend_pid()');
            $barrier->statement('LOCK TABLE business_command_receipts IN SHARE MODE');
            $expires = BusinessAuthorityFixture::expireQualificationSoon($qualification, $manager->id);
            $evidence['expires'] = $expires;
            $counts = [$db->table('security_action_grants')->count(), $db->table('authority_qualification_receipts')->count()];
            fwrite($release, $expires);
            fflush($release);
            $evidence['wait'] = $wait(function () use ($db, $run, $blocker) {
                $rows = $db->select("SELECT a.pid,a.backend_xid::text xid,a.wait_event,pg_blocking_pids(a.pid)::text blockers FROM pg_stat_activity a WHERE application_name=? AND ?=ANY(pg_blocking_pids(a.pid)) AND EXISTS(SELECT 1 FROM pg_locks WHERE pid=a.pid AND relation='business_command_receipts'::regclass AND mode='RowExclusiveLock' AND NOT granted)", [$run, $blocker]);

                return $rows ? (array) $rows[0] : false;
            });
            $this->assertSame((int) $evidence['ready']['pid'], (int) $evidence['wait']['pid']);
            $wait(fn () => (bool) $db->scalar('SELECT clock_timestamp()>=?::timestamptz', [$expires]));
            $barrier->commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertStringContainsString('Selected authority expired after final receipt wait', $worker->getOutput());
            $this->assertSame($counts, [$db->table('security_action_grants')->count(), $db->table('authority_qualification_receipts')->count()]);
            $this->assertSame(0, $db->table('business_command_receipts')->where('command_id', $input['command'])->count());
        } finally {
            if ($barrier->transactionLevel()) {
                $barrier->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(), 'exit' => $worker->getExitCode()];
            file_put_contents($path.'.evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            fclose($release);
            DB::disconnect('agency_receipt_barrier');
            foreach ([$path, $input['release'], $input['ready']] as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
    }

    public function test_client_withdraws_every_action_mandate_atomically_without_removing_independent_staff_rights(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $employee = User::factory()->create();
        $client = $this->business($owner, 'Client');
        $agency = $this->business($manager, 'Partner shop');
        $membership = $this->employee($manager, $agency, $employee);
        $independent = $this->employee($owner, $client, $employee);
        $base = '/api/businesses/'.$client['business_public_id'];
        $assignments = [];
        foreach (['business.context.read', 'business.context.manage'] as $action) {
            $body = [...$this->agencyCommand($client, $agency, $manager, $client['team'][$action], $agency['grant_refs']['business.context.read'], 'mandate'), 'action' => $action];
            $mandate = $this->actingAs($owner)->postJson($base.'/agency-grants', $body)->assertOk()->json('data.grant_id');
            $assigned = [...$this->agencyCommand($client, $agency, $employee, $mandate, $membership, 'assign'), 'action' => $action, 'expires_at' => $body['expires_at']];
            $assignments[$action] = $this->actingAs($manager)->postJson($base.'/agency-grants', $assigned)->assertOk()->json('data.grant_id');
        }
        $this->actingAs($owner);
        $group = $this->getJson($base.'/agency-mandates?access='.$client['grant_refs']['business.context.read'])->assertOk()->json('data.items.0');
        $this->assertTrue($group['complete']);
        $this->assertCount(2, $group['grants']);
        $withdraw = ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($client['grant_refs']['business.context.read']),
            'agency_business_public_id' => $agency['business_public_id'], 'expected_grants' => $group['grants'], 'confirmed' => true];
        $extra = $this->agencyCommand($client, $agency, $manager, $client['team']['business.context.read'], $agency['grant_refs']['business.context.read'], 'mandate');
        $this->postJson($base.'/agency-grants', $extra)->assertOk();
        $this->postJson($base.'/agency-mandates', $withdraw)->assertConflict();
        $this->actingAs($employee)->getJson($base.'/surface?access='.$assignments['business.context.read'])->assertOk();
        $this->actingAs($owner);
        $withdraw['expected_grants'] = $this->getJson($base.'/agency-mandates?access='.$client['grant_refs']['business.context.read'])->assertOk()->json('data.items.0.grants');
        $this->postJson($base.'/agency-mandates', $withdraw)->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->postJson($base.'/agency-mandates', $withdraw)->assertOk();
        $this->getJson($base.'/agency-mandates?access='.$client['grant_refs']['business.context.read'])->assertOk()->assertJsonPath('data.items', []);
        $this->actingAs($employee)->getJson($base.'/surface?access='.$assignments['business.context.read'])->assertForbidden();
        $this->putJson($base.'/context', ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($assignments['business.context.manage']),
            'name' => 'Denied after whole mandate withdrawal', 'business_type_id' => null, 'expected_context' => 0])->assertForbidden();
        $this->getJson($base.'/surface?access='.$independent)->assertOk();
    }

    public function test_populated_upgrade_preserves_every_original_qualification_and_creates_no_agency_mapping(): void
    {
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => strcmp(basename($path), '0001_01_01_000750_business_agency_ownership.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $owner = User::factory()->create();
            $employee = User::factory()->create();
            $business = $this->business($owner, 'Existing business');
            $grant = $this->employee($owner, $business, $employee);
            $db = DB::connection('pgsql_migrations');
            $tables = ['businesses', 'business_context_heads', 'security_action_grants', 'authority_qualification_receipts', 'business_command_receipts', 'business_workspace_links', 'workspaces'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = json_decode($db->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r"), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            foreach ($tables as $table) {
                if ($table === 'authority_qualification_receipts') {
                    $before[$table] = array_map(fn ($row) => [...$row, 'agency_business_id' => null, 'agency_membership_grant_id' => null], $before[$table]);
                }
                $actual = json_decode($db->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r"), true, flags: JSON_THROW_ON_ERROR);
                foreach ($before[$table] as $index => $row) {
                    ksort($row);
                    ksort($actual[$index]);
                    $this->assertSame($row, $actual[$index], $table);
                }
                $this->assertCount(count($before[$table]), $actual, $table);
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            $reset->assertInstalledCatalog();
            $this->actingAs($employee)->getJson('/api/businesses/'.$business['business_public_id'].'/surface?access='.$grant)->assertOk();
        } finally {
            $reset->install();
        }
    }
}
