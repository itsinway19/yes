<?php

namespace App\Commercial;

use App\Security\CommercialAuditCustody;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CommercialAuditDelivery
{
    public function __construct(private readonly CommercialContext $context, private readonly CommercialAuditCustody $custody) {}

    public function inspect(Request $request): array
    {
        return $this->context->run($request, static fn () => DB::table('commercial_audit_obligations as o')
            ->join('commercial_audit_delivery as d', 'd.obligation_id', '=', 'o.id')
            ->orderBy('o.recorded_at')->get(['o.id', 'o.command_id', 'o.target_id', 'd.state', 'd.attempts', 'd.acknowledgment_id'])->all());
    }

    public function recover(Request $request, string $obligation): array
    {
        return $this->context->run($request, function () use ($obligation): array {
            $origin = DB::table('commercial_audit_obligations')->where('id', $obligation)->first();
            if (! $origin) {
                throw new AuthorizationException('Unavailable audit obligation.');
            }
            DB::selectOne('SELECT builderad_sa01_require(?,?,?,NULL)', [
                'RetryOrRecoverExistingAuditDelivery', $origin->target_id, $origin->workspace_id,
            ]);
            $state = 'acknowledged';
            $ack = null;
            try {
                $ack = $this->custody->accept($origin->id, $origin->fingerprint, $origin->envelope);
            } catch (Throwable) {
                // Durable accepted commercial facts remain unchanged. No exception details/PII are persisted.
                $state = 'failed';
            }
            DB::selectOne('SELECT builderad_record_commercial_audit_delivery(?,?,?)', [$obligation, $state, $ack]);

            return ['obligation_id' => $obligation, 'state' => $state, 'acknowledgment_id' => $ack];
        }, true);
    }
}
