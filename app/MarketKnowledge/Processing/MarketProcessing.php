<?php

namespace App\MarketKnowledge\Processing;

use App\Foundation\Tenancy\PrincipalContext;
use App\Security\SessionAuthority;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final class MarketProcessing
{
    public function __construct(private readonly PrincipalContext $principals, private readonly MarketMethodSelector $methods) {}

    public function prepare(Request $request, string $business, array $body): array
    {
        return $this->transaction($request, fn () => $this->sql('SELECT builderad_v2_market_processing_prepare(?,?::uuid,?::uuid,?,?::jsonb,?,?::jsonb,?::jsonb)', [
            SessionAuthority::sessionKey($request), $business, $body['command_id'], $body['question'], $this->json($body['material_refs']),
            $body['purpose'], $this->json($body['authority_selection']), $this->json($body['context_selection']),
        ]));
    }

    public function complete(Request $request, string $business, string $run, array $body): array
    {
        $selection = $body['authority_selection'];
        $context = $body['context_selection'];
        $input = $this->transaction($request, fn () => $this->sql('SELECT builderad_v2_market_processing_input(?,?::uuid,?::uuid,?::jsonb,?::jsonb)', [
            SessionAuthority::sessionKey($request), $business, $run, $this->json($selection), $this->json($context),
        ]));
        // SQL releases all resource/session locks before domain code starts. No worker authority is stored.
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Computation must not hold a transaction.');
        }
        $outcome = ['state' => 'cancelled', 'finding' => null, 'reason_code' => 'REQUEST_CANCELLED'];
        $elapsed = 0;
        if ($body['effect'] === 'complete' && $input['run']['state'] === 'prepared') {
            $method = $this->methods->select($input['run']['question']);
            if ($method === null) {
                $outcome = ['state' => 'unavailable', 'finding' => null, 'reason_code' => 'METHOD_UNAVAILABLE'];
            } else {
                $start = hrtime(true);
                try {
                    if ($method->reference() !== $input['run']['method_ref']) {
                        throw new DomainException('Method contract changed.');
                    }
                    $task = new MarketProcessingTask($input['run']['question'], $input['materials'], $input['business_context']);
                    $finding = $method->execute($task);
                    Findings::validate($task, $method, $finding);
                    $elapsed = (int) ceil((hrtime(true) - $start) / 1000);
                    $outcome = $elapsed > 2000000
                        ? ['state' => 'failed', 'finding' => null, 'reason_code' => 'LOCAL_COMPUTE_BUDGET_EXCEEDED']
                        : ['state' => in_array($finding['status'], ['unresolved', 'not_comparable', 'requires_information'], true) ? 'abstained' : 'validated',
                            'finding' => $finding, 'reason_code' => null];
                } catch (Throwable) {
                    // No exception message or source payload reaches response/log/receipt.
                    $elapsed = min(30000000, (int) ceil((hrtime(true) - $start) / 1000));
                    $outcome = ['state' => 'failed', 'finding' => null, 'reason_code' => 'METHOD_OR_VALIDATION_FAILED'];
                }
            }
        } elseif ($body['effect'] === 'complete') {
            if ($input['run']['finding'] !== null) {
                // Associative decoding must not turn the typed empty values object into a JSON list on replay.
                $input['run']['finding']['values'] = (object) $input['run']['finding']['values'];
            }
            $outcome = ['state' => $input['run']['state'], 'finding' => $input['run']['finding'], 'reason_code' => $input['run']['reason_code']];
        }

        return $this->transaction($request, function () use ($request, $business, $body, $run, $outcome, $elapsed, $selection, $context, $input): array {
            // Separate issuer credentials attest the trusted PHP result. A plain
            // runtime SQL call cannot mint this evidence or substitute its payload.
            $ticket = $input['run']['state'] === 'prepared'
                ? app(TrustedCompletion::class)->issue($request, $business, $input['run'], $body['command_id'], $body['effect'], $outcome, $elapsed, $selection, $context)
                : null;

            return $this->sql('SELECT builderad_v2_market_processing_complete(?,?::uuid,?::uuid,?::uuid,?,?::jsonb,?,?::jsonb,?::jsonb,?)', [
                SessionAuthority::sessionKey($request), $business, $body['command_id'], $run, $body['effect'], $this->json($outcome), $elapsed,
                $this->json($selection), $this->json($context), $ticket,
            ]);
        });
    }

    private function transaction(Request $request, callable $command): array
    {
        if (DB::connection()->transactionLevel() !== 0 || ! $request->user()) {
            throw new LogicException('Processing requires its own authenticated transaction.');
        }

        return DB::transaction(function () use ($request, $command): array {
            $this->principals->activate($request->user());

            return $command();
        }, 1);
    }

    private function json(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function sql(string $sql, array $bindings): array
    {
        $result = json_decode(DB::scalar($sql, $bindings), true, flags: JSON_THROW_ON_ERROR);
        if (isset($result['finding'])) {
            $result['finding']['values'] = (object) $result['finding']['values'];
        }

        return $result;
    }
}
