<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientVisibilityTest extends TestCase
{
    private function receipt(): array
    {
        return $this->get('/confirm-password')->original->getData()['page']['props']['clientVisibility'];
    }

    public function test_probe_uses_real_page_authority_and_does_not_touch_idle_or_return_page_data(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $receipt = $this->receipt();
        $db = DB::connection('pgsql_migrations');
        $before = $db->table('security_sessions')->where('user_id', $user->id)->first();
        $this->get('/confirm-password', ['X-Inertia' => 'true', 'X-Builderad-Visibility' => '1',
            'X-Builderad-Visibility-Context' => $receipt['context']])
            ->assertOk()->assertExactJson($receipt)->assertHeader('Cache-Control', 'no-store, private');
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
}
