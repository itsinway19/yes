<?php

namespace App\Console\Commands;

use App\Platform\PlatformMaintenance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit offline deployment operator only; never reachable through HTTP. */
final class PlatformMaintenanceRecovery extends Command
{
    protected $signature = 'platform:maintenance-recover {--reason=} {--command=} {--authorize-founder=}';

    protected $description = 'Disable application maintenance, or explicitly provision its first Founder grant, through isolated owner credentials.';

    public function handle(): int
    {
        $reason = trim((string) $this->option('reason'));
        $command = $this->option('command') ?: (string) Str::uuid();
        if ($reason === '' || mb_strlen($reason) > 500 || ! Str::isUuid($command)) {
            $this->error('A bounded reason and UUID command are required.');

            return self::FAILURE;
        }
        $db = DB::connection('pgsql_migrations');
        if ($db->scalar('SELECT session_user') !== 'builderad_owner') {
            $this->error('Isolated builderad_owner deployment connection required.');

            return self::FAILURE;
        }
        $db->transaction(function () use ($db, $reason, $command): void {
            if ($founder = $this->option('authorize-founder')) {
                $user = $db->table('users')->where('public_id', $founder)->first();
                if (! $user || ! $user->email_verified_at) {
                    throw new \RuntimeException('Verified eligible Founder required.');
                }
                $life = $db->table('principal_lifecycles')->where('user_id', $user->id)->lockForUpdate()->sole();
                if ($life->status !== 'current' || $life->principal_class !== 'staff'
                    || ! $db->table('internal_personnel')->where('user_id', $user->id)->where('kind', 'founder')->where('status', 'active')->exists()) {
                    throw new \RuntimeException('Current active Founder required.');
                }
                // No automatic migration/role bypass. This is explicit deployment
                // provisioning, recorded like the existing local Founder bootstrap.
                if (! $db->table('security_action_grants')->where('subject_user_id', $user->id)->where('subject_generation', $life->generation)
                    ->where('action', PlatformMaintenance::ACTION)->where('status', 'active')->exists()) {
                    $db->table('security_action_grants')->insert(['subject_user_id' => $user->id, 'subject_generation' => $life->generation,
                        'source' => 'founder_bootstrap', 'scope_kind' => 'platform', 'action' => PlatformMaintenance::ACTION,
                        'reason' => $reason, 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                    $db->table('security_audit_events')->insert(['actor_user_id' => null, 'subject_user_id' => $user->id,
                        'event' => 'maintenance_authority_provisioned', 'reason' => $reason, 'prior_status' => 'current', 'new_status' => 'current',
                        'prior_generation' => $life->generation, 'new_generation' => $life->generation, 'occurred_at' => now()]);
                }

                return;
            }
            $state = $db->table('platform_maintenance')->where('id', 1)->lockForUpdate()->sole();
            $fingerprint = hash('sha256', 'offline-recovery:'.$reason);
            if ($known = $db->table('platform_maintenance_commands')->where('command_id', $command)->first()) {
                if ($known->fingerprint !== $fingerprint || $known->actor_user_id !== null) {
                    throw new \RuntimeException('Recovery command payload conflict.');
                }

                return;
            }
            $db->table('platform_maintenance')->where('id', 1)->update(['active' => false, 'revision' => $state->revision + 1, 'changed_at' => DB::raw('clock_timestamp()')]);
            $db->table('platform_maintenance_commands')->insert(['command_id' => $command, 'fingerprint' => $fingerprint,
                'previous_revision' => $state->revision, 'resulting_revision' => $state->revision + 1, 'reason' => $reason,
                'result' => json_encode(['offline_recovery' => true, 'active' => false, 'revision' => $state->revision + 1], JSON_THROW_ON_ERROR)]);
        });
        $this->info('Maintenance operation committed.');

        return self::SUCCESS;
    }
}
