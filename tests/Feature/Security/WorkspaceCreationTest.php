<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Providers\Ads\NativeSourceContext;
use App\Security\LifecycleAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceCreationTest extends TestCase
{
    public function test_business_creation_is_atomic_and_does_not_create_workspace_authority(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $command = ['command_id' => (string) Str::uuid7(), 'name' => 'North Region'];
        $created = $this->postJson('/api/businesses', $command)->assertCreated()->json('data');
        $this->postJson('/api/businesses', $command)->assertCreated()->assertJsonPath('data', $created);
        $db = DB::connection('pgsql_migrations');
        $business = $db->table('businesses')->where('public_id', $created['business_public_id'])->sole();
        $this->assertSame($owner->id, $business->created_by);
        $this->assertCount(7, $created['grant_refs']);
        $this->assertSame(7, $db->table('security_action_grants')->where('business_id', $business->id)->count());
        $this->assertNull($db->scalar("SELECT to_regclass('public.workspaces')"));
        $this->assertSame(0, $db->table('security_action_grants')->where('scope_kind', 'workspace')->count());
    }

    public function test_runtime_role_cannot_write_businesses_directly(): void
    {
        $this->expectException(QueryException::class);
        DB::table('businesses')->insert(['public_id' => (string) Str::uuid7(), 'created_by' => 1,
            'origin_kind' => 'self_declared_draft', 'lifecycle' => 'declared_draft', 'lifecycle_revision' => 0]);
    }

    public function test_failed_genesis_grant_rolls_back_business_and_all_issued_rights(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $db = DB::connection('pgsql_migrations');
        $db->unprepared(<<<'SQL'
            CREATE FUNCTION reject_test_business_genesis() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.source = 'business_draft_creation' THEN RAISE EXCEPTION 'Synthetic grant failure'; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER reject_test_business_genesis BEFORE INSERT ON security_action_grants
            FOR EACH ROW EXECUTE FUNCTION reject_test_business_genesis();
            SQL);
        try {
            $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid7(), 'name' => 'Atomic Business'])
                ->assertInternalServerError();
        } finally {
            $db->unprepared('DROP TRIGGER reject_test_business_genesis ON security_action_grants; DROP FUNCTION reject_test_business_genesis()');
        }
        $this->assertSame(0, $db->table('businesses')->count());
        $this->assertSame(0, $db->table('security_action_grants')->where('scope_kind', 'business')->count());
        $this->assertSame(0, $db->table('business_command_receipts')->count());
    }

    public function test_in_flight_business_admission_orders_lifecycle_invalidation(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $business = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid7(),
            'name' => 'Ordered Business'])->assertCreated()->json('data');
        $request = Request::create('/businesses/'.$business['business_public_id'].'/source-registry', 'GET');
        $request->setLaravelSession($this->app['session']->driver());
        $request->setUserResolver(fn () => $owner);
        config()->set('database.connections.pgsql_concurrent', config('database.connections.pgsql_authority'));
        $other = DB::connection('pgsql_concurrent');
        try {
            $other->statement("SET lock_timeout = '150ms'");
            app(NativeSourceContext::class)->run($owner, $business['business_public_id'],
                function () use ($other, $owner): void {
                    try {
                        $other->table('principal_lifecycles')->where('user_id', $owner->id)
                            ->update(['status' => 'disabled', 'generation' => 2, 'cause' => 'concurrent_invalidation']);
                        $this->fail('Lifecycle invalidation must wait for admitted Business use.');
                    } catch (QueryException $error) {
                        $this->assertSame('55P03', $error->getCode());
                    }
                }, $request);
        } finally {
            DB::purge('pgsql_concurrent');
        }
        app(LifecycleAuthority::class)->disable($owner, $owner->id, 'security_hold');
        $this->get('/businesses/'.$business['business_public_id'].'/source-registry')->assertRedirect('/login');
        $this->assertGuest();
    }
}
