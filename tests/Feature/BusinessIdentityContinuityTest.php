<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\LifecycleAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BusinessIdentityContinuityTest extends TestCase
{
    private function business(User $user, string $name): array
    {
        return $this->actingAs($user)->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => $name])
            ->assertCreated()->json('data');
    }

    private function reverify(User $user): void
    {
        $this->actingAs($user);
        $request = Request::create('/profile', 'PATCH');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession($this->app['session']->driver());
        app(LifecycleAuthority::class)->changeProfile($request, $user->name, 'changed-'.$user->id.'-'.Str::random(8).'@example.com');
        app(LifecycleAuthority::class)->verifyPrimaryEmail($user->fresh());
        $this->app['session']->driver()->regenerate();
        $this->actingAs($user->fresh());
    }

    public function test_email_reverification_preserves_original_distinct_business_rights_without_new_grants(): void
    {
        $user = User::factory()->create();
        $first = $this->business($user, 'First independent business');
        $second = $this->business($user, 'Second independent business');
        $owner = DB::connection('pgsql_migrations');
        $before = $owner->table('security_action_grants')->where('subject_user_id', $user->id)->orderBy('id')->get()->keyBy('id');
        $oldGeneration = (int) $owner->table('principal_lifecycles')->where('user_id', $user->id)->value('generation');
        $this->reverify($user);
        $this->getJson('/api/businesses')->assertOk();
        foreach ([$first, $second] as $business) {
            $this->getJson('/api/businesses/'.$business['business_public_id'].'/surface?access='.$business['grant_refs']['business.context.read'])->assertOk();
        }
        $after = $owner->table('security_action_grants')->where('subject_user_id', $user->id)->orderBy('id')->get()->keyBy('id');
        $this->assertSame($before->keys()->all(), $after->keys()->all());
        foreach ($before as $id => $grant) {
            $this->assertSame($grant->qualification_id, $after[$id]->qualification_id);
            $this->assertGreaterThan($oldGeneration, (int) $after[$id]->subject_generation);
        }
        $this->assertSame(0, $owner->table('security_action_grants')->where('subject_user_id', $user->id)->where('scope_kind', 'platform')->count());
    }

    public function test_reverification_does_not_revive_revoked_expired_or_unqualified_rights(): void
    {
        $user = User::factory()->create();
        $issuer = User::factory()->create();
        $own = $this->business($user, 'An independent Business');
        $business = $this->business($issuer, 'A Business with bounded rights');
        $base = '/api/businesses/'.$business['business_public_id'];
        $owner = DB::connection('pgsql_migrations');
        $selection = static fn ($id) => ['realm_kind' => 'direct', 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => (int) $id],
        ]];
        $roots = $this->postJson($base.'/direct-team', ['command_id' => (string) Str::uuid(),
            'authority_selection' => $selection($business['grant_refs']['business.context.read'])])->assertOk()->json('data.grant_refs');
        $command = static fn (string $action, ?string $expires = null) => [
            'command_id' => (string) Str::uuid(), 'authority_selection' => $selection($roots[$action]), 'effect' => 'issue',
            'grantee_public_id' => $user->public_id, 'action' => $action, 'parent_grant_id' => (int) $roots[$action],
            'grant_id' => null, 'expected_grant' => 0, 'expires_at' => $expires, 'delegable' => false,
        ];
        $read = $command('business.context.read');
        $revoked = $this->postJson($base.'/grants', $read)->assertOk()->json('data.grant_id');
        $this->postJson($base.'/grants', [...$read, 'command_id' => (string) Str::uuid(), 'effect' => 'revoke',
            'grant_id' => (int) $revoked, 'expected_grant' => 1])->assertOk();
        $manage = $command('business.context.manage');
        $invalid = $this->postJson($base.'/grants', $manage)->assertOk()->json('data.grant_id');
        $this->postJson($base.'/grants', [...$manage, 'command_id' => (string) Str::uuid(), 'effect' => 'revoke',
            'grantee_public_id' => $issuer->public_id, 'parent_grant_id' => null, 'grant_id' => (int) $roots['business.context.manage'],
            'expected_grant' => 1])->assertOk();
        $expiry = (new \DateTimeImmutable($owner->scalar('SELECT clock_timestamp()::text')))->modify('+5 seconds')->format('Y-m-d\TH:i:s.uP');
        $expired = $this->postJson($base.'/grants', $command('business.context.read', $expiry))->assertOk()->json('data.grant_id');
        $owner->select('SELECT pg_sleep(greatest(0,extract(epoch FROM ?::timestamptz-clock_timestamp()))+0.05)', [$expiry]);
        $original = $owner->table('security_action_grants')->whereIn('id', [$revoked, $expired, $invalid])->pluck('subject_generation', 'id')->all();
        $this->reverify($user);
        $this->getJson('/api/businesses')->assertOk();
        $this->assertSame($original, $owner->table('security_action_grants')->whereIn('id', [$revoked, $expired, $invalid])->pluck('subject_generation', 'id')->all());
        $this->getJson($base.'/surface?access='.$revoked)->assertForbidden();
        $this->getJson($base.'/surface?access='.$expired)->assertForbidden();
        $this->getJson('/api/businesses/'.$own['business_public_id'].'/surface?access='.$own['grant_refs']['business.context.read'])->assertOk();
    }

    public function test_unaudited_generation_jump_cannot_restore_a_stale_grant(): void
    {
        $user = User::factory()->create();
        $business = $this->business($user, 'No synthetic recovery authority');
        $owner = DB::connection('pgsql_migrations');
        $old = (int) $owner->table('principal_lifecycles')->where('user_id', $user->id)->value('generation');
        $owner->table('principal_lifecycles')->where('user_id', $user->id)->update(['generation' => $old + 1]);
        $this->app['session']->driver()->regenerate();
        $this->actingAs($user->fresh());
        $this->getJson('/api/businesses')->assertOk();
        $this->assertSame($old, (int) $owner->table('security_action_grants')->where('id', $business['grant_refs']['business.context.read'])->value('subject_generation'));
        $this->getJson('/api/businesses/'.$business['business_public_id'].'/surface?access='.$business['grant_refs']['business.context.read'])->assertForbidden();
    }
}
