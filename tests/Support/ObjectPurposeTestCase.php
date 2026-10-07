<?php

namespace Tests\Support;

use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Models\Workspace;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\ObjectPurposeDatabaseTest;
use Tests\TestCase;

abstract class ObjectPurposeTestCase extends TestCase
{
    protected object $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Purpose business', 'slug' => 'purpose-test'])->assertRedirect();
        $this->workspace = DB::connection('pgsql_migrations')->table('workspaces')->where('slug', 'purpose-test')->first();
    }

    public static function command(): array
    {
        return ['command_id' => (string) Str::uuid(), 'purpose_id' => null, 'expected_version' => 0, 'operation' => 'declare',
            'declaration' => ObjectPurposeDatabaseTest::declaration(), 'effective_from' => null, 'effective_until' => null,
            'supersedes_revision_id' => null, 'affected_revision_ids' => [], 'correction_reason' => null, 'withdrawal_reason' => null,
            'confirmations' => ['meaning' => true, 'scope' => true, 'authority' => true, 'identity' => true, 'standing' => true,
                'dtm' => false, 'intent' => false, 'historical' => false, 'transition' => false]];
    }

    protected function context(callable $callback, bool $mutation = false): mixed
    {
        $request = Request::create('/', 'POST');
        $request->setLaravelSession($this->app['session']->driver());
        $request->setUserResolver(fn () => auth()->user());

        return app(WorkspaceContext::class)->{$mutation ? 'runBusinessMutation' : 'run'}(auth()->user(), $this->workspace->public_id,
            fn (Workspace $workspace) => $callback($request, $workspace), $request);
    }

    protected function write(array $command): array
    {
        return $this->context(fn ($request, $workspace) => json_decode(DB::scalar('SELECT builderad_object_purpose_write(?,?,?::jsonb)',
            [$workspace->id, SessionAuthority::sessionKey($request), json_encode($command)]), true));
    }

    protected function denied(array $command, string $state): void
    {
        try {
            $this->write($command);
            $this->fail('Admission unexpectedly succeeded');
        } catch (QueryException $error) {
            $this->assertSame($state, $error->getCode());
        }
    }
}
