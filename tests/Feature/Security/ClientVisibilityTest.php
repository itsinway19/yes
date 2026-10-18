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

    public function test_missing_or_foreign_path_receipt_does_not_authorize_a_probe(): void
    {
        $this->actingAs(User::factory()->create());
        $receipt = $this->receipt();
        $this->get('/confirm-password', ['X-Builderad-Visibility' => '1'])->assertStatus(409);
        $this->get('/security/devices', ['X-Builderad-Visibility' => '1',
            'X-Builderad-Visibility-Context' => $receipt['context']])->assertStatus(409);
    }

    public function test_matching_receipt_does_not_bypass_an_expired_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $receipt = $this->receipt();
        $db = DB::connection('pgsql_migrations');
        $db->table('security_sessions')->where('user_id', $user->id)->update(['idle_expires_at' => now()->subMinute()]);
        $deadline = $db->table('security_sessions')->where('user_id', $user->id)->value('idle_expires_at');
        $this->get('/confirm-password', ['X-Inertia' => 'true', 'X-Builderad-Visibility' => '1',
            'X-Builderad-Visibility-Context' => $receipt['context']])->assertStatus(409)
            ->assertJsonPath('identityConflict', true);
        $this->assertSame($deadline, $db->table('security_sessions')->where('user_id', $user->id)->value('idle_expires_at'));
    }

    public function test_login_context_changes_reject_old_receipt(): void
    {
        $this->actingAs(User::factory()->create());
        $receipt = $this->receipt();
        $this->post('/logout');
        $this->actingAs(User::factory()->create());
        $this->get('/confirm-password', ['X-Inertia' => 'true', 'X-Builderad-Visibility' => '1',
            'X-Builderad-Visibility-Context' => $receipt['context']])->assertStatus(409);
    }

    public function test_remaining_internal_administration_owns_real_legacy_visibility_probes(): void
    {
        $staff = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $db->table('principal_lifecycles')->where('user_id', $staff->id)->update(['principal_class' => 'staff']);
        $db->table('internal_personnel')->insert(['user_id' => $staff->id, 'kind' => 'employee', 'status' => 'active',
            'attested_by' => $staff->id, 'attested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $db->table('passkeys')->insert(['user_id' => $staff->id, 'name' => 'Internal visibility fixture', 'credential_id' => 'visibility-'.$staff->id,
            'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('security_action_grants')->insert(['subject_user_id' => $staff->id, 'source' => 'founder_bootstrap',
            'action' => 'staff.view', 'scope_kind' => 'platform', 'subject_generation' => 1,
            'reason' => 'remaining legacy visibility fixture', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsInternal($staff);
        $page = $this->get('/internal/administration')->assertOk();
        $receipt = $page->viewData('page')['props']['clientVisibility'];
        $this->assertTrue($page->viewData('page')['encryptHistory']);
        $before = $db->table('security_sessions')->where('user_id', $staff->id)->first();
        $this->get('/internal/administration', ['X-Inertia' => 'true', 'X-Builderad-Visibility' => '1', 'X-Builderad-Visibility-Context' => $receipt['context']])
            ->assertOk()->assertExactJson($receipt)->assertHeader('Cache-Control', 'no-store, private');
        $after = $db->table('security_sessions')->where('id', $before->id)->first();
        $this->assertSame($before->idle_expires_at, $after->idle_expires_at);
        $this->assertSame($before->last_seen_at, $after->last_seen_at);
        $this->get('/internal/administration', ['X-Builderad-Visibility' => '1', 'X-Builderad-Visibility-Context' => 'foreign'])->assertStatus(409);
    }
}
