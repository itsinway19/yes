<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\LifecycleAuthority;
use App\Security\SessionAuthority;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BootstrapFounder extends Command
{
    protected $signature = 'security:bootstrap-founder
        {user : Public UUID of the verified founder account}
        {primary : Credential ID of the tested primary passkey}
        {backup : Credential ID of the tested independent backup passkey}
        {--attest-independent-factors : Operator confirms distinct tested authenticators}
        {--operator= : Attributable operator identity, not a secret}
        {--independence-evidence= : Reference to reviewed physical/provider independence evidence}';

    protected $description = 'One-time trusted operator bootstrap of the initial Builderad founder';

    public function handle(
        LifecycleAuthority $lifecycles,
        SessionAuthority $sessions,
        GrantAuthority $grants,
    ): int {
        $operator = trim((string) $this->option('operator'));
        $evidence = trim((string) $this->option('independence-evidence'));
        if ($operator === '' || mb_strlen($operator) > 128 ||
            $evidence === '' || mb_strlen($evidence) > 128 ||
            ! $this->option('attest-independent-factors') ||
            $this->argument('primary') === $this->argument('backup')) {
            $this->error('Two independently tested credentials and explicit operator attestation are required.');

            return self::FAILURE;
        }

        try {
            DB::connection('pgsql_authority')->transaction(function () use ($lifecycles, $sessions, $grants, $operator, $evidence): void {
                $authority = DB::connection('pgsql_authority');
                $authority->statement("SELECT pg_advisory_xact_lock(hashtext('builderad.founder.bootstrap'))");
                if ($authority->table('internal_personnel')->where('kind', 'founder')->exists()) {
                    throw new \RuntimeException('Founder bootstrap has already been used.');
                }
                $user = User::on('pgsql_authority')->where('public_id', $this->argument('user'))
                    ->lockForUpdate()->firstOrFail();
                $credentials = $authority->table('passkeys')->where('user_id', $user->id)->whereIn('credential_id', [
                    $this->argument('primary'), $this->argument('backup'),
                ])->lockForUpdate()->get();
                if ($credentials->count() !== 2 || ! $user->hasVerifiedEmail()) {
                    throw new \RuntimeException('Both tested passkeys and verified email are required.');
                }
                $currentGeneration = $authority->table('principal_lifecycles')
                    ->where('user_id', $user->id)->value('generation');
                foreach ($credentials as $credential) {
                    if (! $credential->last_used_at || ! $authority->table('security_audit_events')
                        ->where('subject_user_id', $user->id)
                        ->where('event', 'passkey_login_verified')
                        ->where('new_generation', $currentGeneration)
                        ->where('occurred_at', '>=', $credential->created_at)
                        ->where('reason', 'like', 'key:'.$credential->id.';session:%;uv:1')->exists()) {
                        throw new \RuntimeException('Each credential requires successful UV login evidence in the current lifecycle.');
                    }
                }
                if ($authority->scalar('SELECT builderad_lock_and_has_active_workspace_membership(?)', [$user->id])) {
                    throw new \RuntimeException('Founder bootstrap cannot convert an active Workspace member.');
                }

                $generation = $lifecycles->bootstrapFounder($user);
                $authority->table('internal_personnel')->insert([
                    'user_id' => $user->id,
                    'kind' => 'founder',
                    'status' => 'active',
                    'attested_at' => now(),
                    'factor_independence_attested_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $grants->issueFounderBootstrapGrants($user, $generation);
                $sessions->revokeAll($user, 'founder_bootstrap');
                foreach (['founder_operator_attested' => $operator, 'founder_independence_attested' => $evidence] as $event => $reason) {
                    $authority->table('security_audit_events')->insert([
                        'actor_user_id' => null, 'subject_user_id' => $user->id,
                        'event' => $event, 'reason' => $reason,
                        'prior_status' => 'current', 'new_status' => 'current',
                        'prior_generation' => $currentGeneration, 'new_generation' => $generation,
                        'occurred_at' => now(),
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Founder bootstrap committed. Existing sessions were revoked.');

        return self::SUCCESS;
    }
}
