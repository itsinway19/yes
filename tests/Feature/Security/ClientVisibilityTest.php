<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientVisibilityTest extends TestCase
{
    private function receipt(): array
    {
        return $this->getJson('/confirm-password')->json('visibility');
    }

    public function test_probe_uses_real_page_authority_and_does_not_touch_idle_or_return_page_data(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $receipt = $this->receipt();
        $db = DB::connection('pgsql_migrations');
        $before = $db->table('security_sessions')->where('user_id', $user->id)->first();
        $this->getJson('/confirm-password', ['X-Builderad-Witness' => '1'])
            ->assertOk()->assertExactJson(['visibility' => $receipt])->assertHeader('Cache-Control', 'no-store, private');
        $after = $db->table('security_sessions')->where('id', $before->id)->first();
        $this->assertSame($before->idle_expires_at, $after->idle_expires_at);
        $this->assertSame($before->last_seen_at, $after->last_seen_at);
    }

    public function test_foreign_document_receipt_cannot_authorize_a_current_witness(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/security/devices', ['X-Builderad-Witness' => '1',
            'X-Builderad-Document' => 'foreign-document'])->assertStatus(409);
    }

    public function test_matching_receipt_does_not_bypass_an_expired_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $receipt = $this->receipt();
        $db = DB::connection('pgsql_migrations');
        $db->table('security_sessions')->where('user_id', $user->id)->update(['idle_expires_at' => now()->subMinute()]);
        $deadline = $db->table('security_sessions')->where('user_id', $user->id)->value('idle_expires_at');
        $this->get('/confirm-password', ['Accept' => 'application/json', 'X-Builderad-Witness' => '1'])->assertStatus(409)
            ->assertJsonPath('identityConflict', true);
        $this->assertSame($deadline, $db->table('security_sessions')->where('user_id', $user->id)->value('idle_expires_at'));
    }

    public function test_login_context_changes_and_foreign_document_cannot_authorize_witness(): void
    {
        $this->actingAs(User::factory()->create());
        $receipt = $this->receipt();
        $this->post('/logout');
        $this->actingAs(User::factory()->create());
        $current = $this->receipt();
        $this->assertNotSame($receipt['context'], $current['context']);
        $this->getJson('/confirm-password', ['X-Builderad-Witness' => '1', 'X-Builderad-Document' => 'foreign-document'])->assertStatus(409);
    }

    public function test_commercial_spa_preserves_existing_visibility_probe_authority_and_idle_deadlines(): void
    {
        $staff = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $db->table('principal_lifecycles')->where('user_id', $staff->id)->update(['principal_class' => 'staff']);
        $db->table('internal_personnel')->insert(['user_id' => $staff->id, 'kind' => 'founder', 'status' => 'active',
            'attested_by' => $staff->id, 'attested_at' => now(), 'factor_independence_attested_by' => $staff->id, 'factor_independence_attested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $db->table('passkeys')->insert(['user_id' => $staff->id, 'name' => 'Internal visibility fixture', 'credential_id' => 'visibility-'.$staff->id,
            'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('passkeys')->insert(['user_id' => $staff->id, 'name' => 'Second synthetic founder factor', 'credential_id' => 'second-legacy-'.$staff->id, 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('security_action_grants')->insert(['subject_user_id' => $staff->id, 'source' => 'founder_bootstrap',
            'action' => 'staff.view', 'scope_kind' => 'platform', 'subject_generation' => 1,
            'reason' => 'remaining legacy visibility fixture', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsInternal($staff);
        $this->get('/internal/commercial')->assertOk()->assertViewIs('spa')->assertDontSee('data-page=', false);
        $receipt = $this->getJson('/internal/commercial')->assertOk()->json('visibility');
        $before = $db->table('security_sessions')->where('user_id', $staff->id)->first();
        $this->getJson('/internal/commercial', ['X-Builderad-Witness' => '1'])
            ->assertOk()->assertExactJson(['visibility' => $receipt])->assertHeader('Cache-Control', 'no-store, private');
        $after = $db->table('security_sessions')->where('id', $before->id)->first();
        $this->assertSame($before->idle_expires_at, $after->idle_expires_at);
        $this->assertSame($before->last_seen_at, $after->last_seen_at);
        $this->getJson('/internal/commercial', ['X-Builderad-Witness' => '1', 'X-Builderad-Document' => 'foreign-document'])->assertStatus(409);
    }
}
