<?php

namespace Tests\Feature;

use App\Foundation\Business\BusinessScope;
use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture as Fixture;
use Tests\TestCase;

final class BusinessLifecycleTest extends TestCase
{
    private function request(User $u): Request
    {
        $this->actingAs($u);
        $r = Request::create('/api/businesses');
        $r->setUserResolver(fn () => $u);
        $r->setLaravelSession(app('session')->driver());

        return $r;
    }

    private function selected(int $grant): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $grant]]];
    }

    private function command(Request $r, string $business, string $effect, ?string $target, object $payload, int $expected, array $selection): array
    {
        return DB::transaction(function () use ($r, $business, $effect, $target, $payload, $expected, $selection) {
            app(PrincipalContext::class)->activate($r->user());

            return json_decode(DB::scalar('SELECT builderad_v2_business_lifecycle_command(?,?::uuid,?::uuid,?,?::uuid,?::jsonb,?,?::jsonb)',
                [SessionAuthority::sessionKey($r), $business, (string) Str::uuid(), $effect, $target, json_encode($payload), $expected, json_encode($selection)]), true, flags: JSON_THROW_ON_ERROR);
        });
    }

    private function denied(callable $op): void
    {
        try {
            $op();
            $this->fail('Unauthorized lifecycle action succeeded.');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->errorInfo[0]);
        }
    }

    public function test_draft_archive_stops_use_but_does_not_archive_other_business_or_manufacture_restore_authority(): void
    {
        $u = User::factory()->create();
        $scope = app(BusinessScope::class);
        $a = $scope->create($this->request($u), (string) Str::uuid(), 'First draft', null);
        $other = $scope->create($this->request($u), (string) Str::uuid(), 'Second draft', null);
        $r = $this->request($u);
        $result = $this->command($r, $a['business_public_id'], 'archive', null, (object) [], 0, $this->selected($a['grant_refs']['business.draft.archive']));
        $this->assertSame('archived', $result['state']);
        $this->assertSame(1, $result['versions']['lifecycle']);
        $this->denied(fn () => $scope->read($r, $a['business_public_id'], $this->selected($a['grant_refs']['business.context.read'])));
        $this->denied(fn () => $this->command($r, $a['business_public_id'], 'restore', null, (object) [], 1, $this->selected($a['grant_refs']['business.draft.archive'])));
        $this->assertSame('Second draft', $scope->read($r, $other['business_public_id'], $this->selected($other['grant_refs']['business.context.read']))['name']);
    }

    public function test_branch_identity_is_not_available_from_draft_or_workspace_right_and_uses_own_business_fk(): void
    {
        $u = User::factory()->create();
        $scope = app(BusinessScope::class);
        $a = $scope->create($this->request($u), (string) Str::uuid(), 'Branch customer', null);
        $r = $this->request($u);
        $this->denied(fn () => $this->command($r, $a['business_public_id'], 'branch_create', null, (object) ['label' => 'Riyadh'], 0, $this->selected($a['grant_refs']['business.link.manage'])));
        $db = DB::connection('pgsql_migrations');
        $b = $db->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        $g = Fixture::root($b, $u->id, 'business.branch.manage');
        $branch = $this->command($r, $a['business_public_id'], 'branch_create', null, (object) ['label' => 'Riyadh'], 0, $this->selected($g->id));
        $this->assertSame('active', $branch['status']);
        $this->assertSame(1, $branch['versions']['branch']);
        $this->assertSame($b, $db->table('business_branches')->where('public_id', $branch['branch_public_id'])->value('business_id'));
        $ended = $this->command($r, $a['business_public_id'], 'branch_archive', $branch['branch_public_id'], (object) [], 1, $this->selected($g->id));
        $this->assertSame('archived', $ended['status']);
        $this->assertSame(2, $ended['versions']['branch']);
        $this->assertSame(0, $db->table('business_current_intents')->where('business_id', $b)->value('intent_revision'));
    }
}
