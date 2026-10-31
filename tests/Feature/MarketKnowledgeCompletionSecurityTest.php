<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\MarketKnowledge\KnowledgeCompletionEvidence;
use App\MarketKnowledge\Processing\Findings;
use App\MarketKnowledge\Processing\MarketProcessingTask;
use App\MarketKnowledge\Processing\OfferEquivalence;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MarketKnowledgeFixture;
use Tests\TestCase;

final class MarketKnowledgeCompletionSecurityTest extends TestCase
{
    use MarketKnowledgeFixture;

    private function preparedKnowledge(): array
    {
        $originals = $this->knowledgeOriginals();
        $operator = $this->operator(['market.knowledge.capture', 'market.knowledge.publish']);
        $capture = $this->captureBody($operator, $originals);
        $publish = $this->knowledgePublication($operator, $originals, $capture);
        $this->actingAsInternal($operator)->postJson('/internal/market-knowledge/capture', $capture)->assertOk();
        $task = new MarketProcessingTask('compare_offers', array_map(fn ($record) => ['ref' => $record['ref'], 'fields' => $record['payload']['fields'], 'evidence' => ['schema' => 'market.offer:2']], $originals['records']));
        $method = new OfferEquivalence;
        $finding = $method->execute($task);
        Findings::validate($task, $method, $finding);
        $request = Request::create('/internal/market-knowledge/publish');
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn () => $operator);

        return compact('originals', 'operator', 'capture', 'publish', 'finding', 'request');
    }

    private function runtime(array $f, callable $operation): mixed
    {
        return DB::transaction(function () use ($f, $operation) {
            app(PrincipalContext::class)->activate($f['operator']);
            $this->assertSame('builderad_app', DB::scalar('SELECT session_user'));

            return $operation();
        }, 1);
    }

    private function issue(array $f): string
    {
        return app(KnowledgeCompletionEvidence::class)->issue($f['request'], ['run_ref' => $f['capture']['command_id'], 'method_ref' => 'offer_equivalence:1'], $f['publish'], $f['finding']);
    }

    private function invoke(array $f, ?string $ticket): array
    {
        return json_decode(DB::scalar('SELECT builderad_v2_market_knowledge_publish(?,?::uuid,?::uuid,?::uuid,?::jsonb,?::jsonb,?::jsonb,?)', [
            SessionAuthority::sessionKey($f['request']), $f['publish']['command_id'], $f['capture']['command_id'], $f['publish']['output_scope_id'],
            json_encode($f['finding'], JSON_THROW_ON_ERROR), json_encode($f['publish']['process_selection'], JSON_THROW_ON_ERROR),
            json_encode($f['publish']['publication_selection'], JSON_THROW_ON_ERROR), $ticket,
        ]), true, flags: JSON_THROW_ON_ERROR);
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Runtime result fabrication or attestation substitution succeeded.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->errorInfo[0]);
        }
    }

    public function test_signed_direct_sql_cannot_publish_an_unattested_result(): void
    {
        $f = $this->preparedKnowledge();
        $f['finding']['status'] = 'different';
        $this->denied(fn () => $this->runtime($f, fn () => $this->invoke($f, null)));
        $db = DB::connection('pgsql_migrations');
        $this->assertSame('prepared', $db->table('market_knowledge_captures')->value('state'));
        $this->assertNull($db->table('market_knowledge_captures')->value('finding'));
        $this->assertSame(0, $db->table('material_lineage_manifests')->count());
        $this->assertSame(0, $db->table('business_command_receipts')->where('command_id', $f['publish']['command_id'])->count());
    }

    public function test_finding_and_command_tampering_and_transaction_transfer_are_denied(): void
    {
        $f = $this->preparedKnowledge();
        foreach (['finding', 'command'] as $tamper) {
            $this->denied(fn () => $this->runtime($f, function () use ($f, $tamper) {
                $token = $this->issue($f);
                if ($tamper === 'finding') {
                    $f['finding']['status'] = 'different';
                } else {
                    $f['publish']['command_id'] = (string) Str::uuid();
                }

                return $this->invoke($f, $token);
            }));
        }
        [$token, $first] = $this->runtime($f, fn () => [$this->issue($f), DB::selectOne('SELECT pg_backend_pid() AS pid,pg_current_xact_id()::text AS xid')]);
        $this->denied(fn () => $this->runtime($f, function () use ($f, $token, $first) {
            $next = DB::selectOne('SELECT pg_backend_pid() AS pid,pg_current_xact_id()::text AS xid');
            $this->assertSame($first->pid, $next->pid);
            $this->assertNotSame($first->xid, $next->xid);

            return $this->invoke($f, $token);
        }));
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('material_lineage_manifests')->count());
    }

    public function test_ticket_is_consumed_once_and_terminal_replay_needs_no_ticket(): void
    {
        $f = $this->preparedKnowledge();
        $this->runtime($f, function () use ($f): void {
            $token = $this->issue($f);
            $result = $this->invoke($f, $token);
            $this->assertSame('published', $result['state']);
            $this->denied(fn () => DB::transaction(fn () => $this->invoke($f, $token)));
            $this->assertSame($result, $this->invoke($f, null));
        });
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('market_knowledge_completion_tickets')->whereNotNull('consumed_at')->count());
    }

    public function test_public_input_returns_no_private_licensing_or_authority_proof(): void
    {
        $f = $this->preparedKnowledge();
        $input = $this->runtime($f, fn () => json_decode(DB::scalar('SELECT builderad_v2_market_knowledge_input(?,?::uuid,?::uuid,?::jsonb,?::jsonb)', [
            SessionAuthority::sessionKey($f['request']), $f['capture']['command_id'], $f['publish']['output_scope_id'],
            json_encode($f['publish']['process_selection'], JSON_THROW_ON_ERROR), json_encode($f['publish']['publication_selection'], JSON_THROW_ON_ERROR),
        ]), true, flags: JSON_THROW_ON_ERROR));
        $this->assertEqualsCanonicalizing(['run', 'materials'], array_keys($input));
        foreach (['policy_id', 'evidence_digest', 'allowed_fields', 'qualification_row', 'grant_row'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($input));
        }
    }

    public function test_final_receipt_failure_rolls_back_manifest_dependencies_ticket_and_result_then_retry_succeeds(): void
    {
        $f = $this->preparedKnowledge();
        $owner = DB::connection('pgsql_migrations');
        $owner->unprepared(<<<'SQL'
            CREATE FUNCTION public.test_knowledge_final_receipt_failure() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN IF NEW.action='market.knowledge.publish' THEN RAISE EXCEPTION 'SYNTHETIC final receipt fault' USING ERRCODE='P0001'; END IF;RETURN NEW;END $$;
            CREATE TRIGGER zzzz_test_knowledge_final_receipt BEFORE INSERT ON business_command_receipts FOR EACH ROW EXECUTE FUNCTION public.test_knowledge_final_receipt_failure();
            SQL);
        try {
            try {
                $this->runtime($f, fn () => $this->invoke($f, $this->issue($f)));
                $this->fail('Injected final receipt fault must abort the entire publication.');
            } catch (QueryException $error) {
                $this->assertSame('P0001', $error->errorInfo[0]);
            }
        } finally {
            $owner->statement('DROP TRIGGER zzzz_test_knowledge_final_receipt ON business_command_receipts');
            $owner->statement('DROP FUNCTION public.test_knowledge_final_receipt_failure()');
        }
        $this->assertSame(0, $owner->table('material_lineage_manifests')->count());
        $this->assertSame(0, $owner->table('material_use_dependencies')->count());
        $this->assertSame(0, $owner->table('market_knowledge_completion_tickets')->whereNotNull('consumed_at')->count());
        $this->assertNull($owner->table('market_knowledge_captures')->value('finding'));
        $this->assertSame('prepared', $owner->table('market_knowledge_captures')->value('state'));
        $this->assertSame(0, $owner->table('business_command_receipts')->where('command_id', $f['publish']['command_id'])->count());
        $this->postJson('/internal/market-knowledge/publish', $f['publish'])->assertOk()->assertJsonPath('data.state', 'published');
    }
}
