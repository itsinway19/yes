<?php

namespace Tests\Support;

use App\Business\ObjectPurpose;
use App\Foundation\Business\BusinessScope;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Real Business semantics under an explicitly synthetic Purpose qualification. */
abstract class BusinessPurposeTestCase extends TestCase
{
    protected array $business;

    protected array $purposeSelection;

    protected function setUp(): void
    {
        parent::setUp();
        $actor = User::factory()->create();
        $this->actingAs($actor)->withCredentials();
        $this->business = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Canonical Purpose test subject'])->assertCreated()->json('data');
        $id = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $this->business['business_public_id'])->value('id');
        $grant = BusinessAuthorityFixture::root($id, $actor->id, 'business.purpose.declare');
        $this->purposeSelection = $this->selection($grant->id);
    }

    protected function selection(int|string $grant): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => (int) $grant]]];
    }

    protected function request(): Request
    {
        $request = Request::create('/api/businesses');
        $request->setUserResolver(fn () => auth()->user());
        $request->setLaravelSession(app('session')->driver());

        return $request;
    }

    public static function command(): array
    {
        return PurposeDeclarationFixture::command();
    }

    protected function contextVersion(): int
    {
        return (int) DB::connection('pgsql_migrations')->table('business_context_heads')->value('context_version');
    }

    protected function write(array $command): array
    {
        return app(BusinessScope::class)->writePurpose($this->request(), $this->business['business_public_id'], $command, $this->contextVersion(), $this->purposeSelection);
    }

    protected function markets(array $countries, int $version): int
    {
        return app(BusinessScope::class)->markets($this->request(), $this->business['business_public_id'], (string) Str::uuid(), $countries, $version, $this->selection($this->business['grant_refs']['business.markets.declare']))['versions']['context'];
    }

    protected function denied(array $command, string $state): void
    {
        try {
            $this->write($command);
            $this->fail('Canonical admission unexpectedly succeeded');
        } catch (QueryException $error) {
            $this->assertSame($state, $error->getCode());
        }
    }

    protected function project(?string $id = null, ?string $effective = null, ?string $known = null, ?array $object = null): array
    {
        $projection = app(BusinessScope::class)->purposes($this->request(), $this->business['business_public_id'], $this->business['grant_refs']['business.context.read'], $id,
            $effective ? new \DateTimeImmutable($effective) : null, $known ? new \DateTimeImmutable($known) : null)['projection'];
        if ($object === null) {
            return $projection;
        }
        // Object matching is a pure finite domain operation. Business HTTP does
        // not acquire a new discovery/filter API from this semantic test.
        $heads = array_map(fn ($item) => (object) ['id' => $item['purpose_id'], 'head_version' => $item['head_version'], 'history' => $item['history']], $projection['items']);

        return app(ObjectPurpose::class)->projectHistory($heads, $projection['current_context_version'], $projection['latest_dtm_event_context_version'], new \DateTimeImmutable($projection['effective_at']), new \DateTimeImmutable($projection['known_at']), $object);
    }
}
