<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Uses rolled-back PostgreSQL fixtures without resetting preview data. */
class FounderReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('pgsql_authority')->beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::connection('pgsql_authority')->rollBack();
        parent::tearDown();
    }

    public function test_founder_requires_both_login_evidence_and_attributable_attestation(): void
    {
        $db = DB::connection('pgsql_authority');
        $user = User::factory()->create();
        $input = ['user' => $user->public_id, 'primary' => 'readiness-primary-'.$user->id,
            'backup' => 'readiness-backup-'.$user->id, '--attest-independent-factors' => true,
            '--operator' => 'isolated-test-operator', '--independence-evidence' => 'synthetic-test-only'];
        $keys = [];
        foreach (['primary', 'backup'] as $name) {
            $keys[] = $db->table('passkeys')->insertGetId([
                'user_id' => $user->id, 'name' => $name, 'credential_id' => $input[$name],
                'credential' => '{}', 'created_at' => now()->subMinute(), 'updated_at' => now(),
                'last_used_at' => now(),
            ]);
        }
        $this->assertSame(1, Artisan::call('security:bootstrap-founder', $input));
        foreach ($keys as $index => $id) {
            $db->table('security_audit_events')->insert([
                'actor_user_id' => $user->id, 'subject_user_id' => $user->id,
                'event' => 'passkey_login_verified', 'reason' => 'key:'.$id.';session:1;uv:1',
                'prior_status' => 'current', 'new_status' => 'current',
                'prior_generation' => 1, 'new_generation' => 1, 'occurred_at' => now(),
            ]);
            if ($index === 0) {
                $this->assertSame(1, Artisan::call('security:bootstrap-founder', $input));
            }
        }
        $this->assertSame(1, Artisan::call('security:bootstrap-founder', [...$input, '--operator' => '']));
        $this->assertSame(1, Artisan::call('security:bootstrap-founder', [...$input, '--independence-evidence' => '']));
        $db->table('passkeys')->where('id', $keys[1])->update(['last_used_at' => null]);
        $this->assertSame(1, Artisan::call('security:bootstrap-founder', $input));
        $db->table('passkeys')->where('id', $keys[1])->update(['last_used_at' => now()]);
        $this->assertSame(1, Artisan::call('security:bootstrap-founder', [...$input, 'backup' => $input['primary']]));
        $user->forceFill(['email_verified_at' => null])->save();
        $this->assertSame(1, Artisan::call('security:bootstrap-founder', $input));
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->assertSame(0, Artisan::call('security:bootstrap-founder', $input), Artisan::output());
        $this->assertSame('isolated-test-operator', $db->table('security_audit_events')
            ->where('subject_user_id', $user->id)->where('event', 'founder_operator_attested')->value('reason'));
        $this->assertSame(1, Artisan::call('security:bootstrap-founder', $input));
    }
}
