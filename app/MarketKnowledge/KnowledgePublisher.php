<?php

namespace App\MarketKnowledge;

use App\Foundation\Tenancy\PrincipalContext;
use App\MarketKnowledge\Processing\Findings;
use App\MarketKnowledge\Processing\MarketMethodSelector;
use App\MarketKnowledge\Processing\MarketProcessingTask;
use App\Security\SessionAuthority;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

final class KnowledgePublisher
{
    public function publish(Request $request, string $run, array $body): array
    {
        $input = $this->transaction($request, fn () => json_decode(DB::scalar('SELECT builderad_v2_market_knowledge_input(?,?::uuid,?::uuid,?::jsonb,?::jsonb)', [
            SessionAuthority::sessionKey($request), $run, $body['output_scope_id'], json_encode($body['process_selection'], JSON_THROW_ON_ERROR), json_encode($body['publication_selection'], JSON_THROW_ON_ERROR),
        ]), true, flags: JSON_THROW_ON_ERROR));
        $finding = null;
        if ($input['run']['state'] === 'prepared') {
            if (DB::transactionLevel() !== 0) {
                throw new LogicException('Shared computation must release all database locks.');
            }
            $method = match ($input['run']['question']) {
                'record_price' => new QualifiedMarketFact('price'), 'record_geography' => new QualifiedMarketFact('geography'),
                default => app(MarketMethodSelector::class)->select($input['run']['question'], $input['run']['method_ref']),
            };
            if ($method === null || $method->reference() !== $input['run']['method_ref']) {
                abort(503, 'Qualified market method unavailable.');
            }
            $task = new MarketProcessingTask($input['run']['question'], $input['materials']);
            try {
                $finding = $method->execute($task);
                if ($method instanceof QualifiedMarketFact) {
                    $method->validate($task, $finding);
                } else {
                    Findings::validate($task, $method, $finding);
                }
            } catch (DomainException) {
                abort(422, 'Market result did not pass validation.');
            }
        }

        return $this->transaction($request, function () use ($request, $run, $body, $finding, $input): array {
            $ticket = $finding === null ? null : app(KnowledgeCompletionEvidence::class)->issue($request, $input['run'], $body, $finding);

            return json_decode(DB::scalar('SELECT builderad_v2_market_knowledge_publish(?,?::uuid,?::uuid,?::uuid,?::jsonb,?::jsonb,?::jsonb,?)', [
                SessionAuthority::sessionKey($request), $body['command_id'], $run, $body['output_scope_id'], json_encode($finding, JSON_THROW_ON_ERROR),
                json_encode($body['process_selection'], JSON_THROW_ON_ERROR), json_encode($body['publication_selection'], JSON_THROW_ON_ERROR), $ticket,
            ]), true, flags: JSON_THROW_ON_ERROR);
        });
    }

    private function transaction(Request $request, callable $command): array
    {
        if (DB::transactionLevel() !== 0 || ! $request->user()) {
            throw new LogicException('Shared publication owns its signed runtime transaction.');
        }

        return DB::transaction(function () use ($request, $command): array {
            app(PrincipalContext::class)->activate($request->user());

            return $command();
        }, 1);
    }
}
