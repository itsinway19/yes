<?php

namespace App\Business;

use App\Models\Workspace;
use App\Security\SessionAuthority;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Canonical declaration access, within WorkspaceContext::run's signed transaction. */
final class ObjectPurpose
{
    public function write(Request $request, Workspace $workspace, array $command): array
    {
        return json_decode(DB::scalar('SELECT builderad_object_purpose_write(?,?,?::jsonb)', [
            $workspace->id, SessionAuthority::sessionKey($request), json_encode($command, JSON_THROW_ON_ERROR),
        ]), true, flags: JSON_THROW_ON_ERROR);
    }

    public function projection(Request $request, Workspace $workspace, ?string $purposeId = null,
        ?DateTimeImmutable $effectiveAt = null, ?DateTimeImmutable $knownAt = null, ?array $object = null): array
    {
        $this->authorize($request, $workspace);
        $now = new DateTimeImmutable(DB::scalar('SELECT clock_timestamp()'));
        $effectiveAt ??= $now;
        $knownAt ??= $now;
        if ($knownAt > $now) {
            throw ValidationException::withMessages(['known_at' => 'Knowledge time cannot be in the future.']);
        }
        $heads = DB::table('object_purposes')->where('workspace_id', $workspace->id)
            ->when($purposeId !== null, fn ($query) => $query->where('id', $purposeId))->orderBy('id')->get();
        if ($purposeId !== null && $heads->isEmpty()) {
            abort(404);
        }
        $contextVersion = (int) DB::table('workspaces')->where('id', $workspace->id)->value('business_context_version');
        $items = [];
        foreach ($heads as $head) {
            $history = DB::table('object_purpose_revisions')->where('workspace_id', $workspace->id)->where('purpose_id', $head->id)
                ->where('accepted_at', '<=', $knownAt->format('Y-m-d H:i:s.uP'))->orderBy('version')->get()
                ->map(fn ($row) => $this->revisionArray($row))->all();
            $segments = [];
            foreach ($history as $revision) {
                $from = new DateTimeImmutable($revision['effective_from']);
                // Every accepted transition explicitly cuts the reviewed preceding timeline.
                // Later versions are knowledge, not a timestamp-based applicability winner.
                $retained = [];
                foreach ($segments as $segment) {
                    if ($segment['from'] >= $from) {
                        continue;
                    }
                    if ($segment['until'] === null || $segment['until'] > $from) {
                        $segment['until'] = $from;
                    }
                    $retained[] = $segment;
                }
                $segments = $retained;
                if ($revision['operation'] !== 'withdraw') {
                    $segments[] = ['from' => $from,
                        'until' => $revision['effective_until'] === null ? null : new DateTimeImmutable($revision['effective_until']),
                        'revision' => $revision];
                }
            }
            $applicable = null;
            foreach ($segments as $segment) {
                if ($segment['from'] <= $effectiveAt && ($segment['until'] === null || $effectiveAt < $segment['until'])) {
                    $applicable = $segment['revision'];
                    break;
                }
            }
            if ($object !== null && ($applicable === null || ! $this->covers($applicable['snapshot'], $object))) {
                continue;
            }
            $dtm = $applicable['snapshot']['dtm'] ?? null;
            $historical = $dtm !== null && $dtm['mode'] === 'historical_attestation';
            $revalidate = $dtm !== null && ! $historical && $dtm['version'] !== $contextVersion;
            $items[] = ['purpose_id' => $head->id, 'head_version' => (int) $head->head_version,
                'state' => $applicable === null ? 'UNKNOWN' : 'DECLARED', 'applicable' => $applicable, 'history' => $history,
                'dtm_standing' => $dtm === null ? 'NO_RELATION_DECLARED' : ($historical ? 'HISTORICAL_ATTESTATION' : ($revalidate
                    ? 'REFERENCED_CURRENT_CLAIM_REQUIRES_REVALIDATION' : 'CURRENT_REFERENCE_AT_READ')),
                'relationship_revalidation_required' => $revalidate,
                'continuity' => $applicable === null ? 'NOT_ESTABLISHED' : ($applicable['snapshot']['end_condition'] !== null
                    ? 'CONDITION_CONTINUITY_NOT_ESTABLISHED' : 'DECLARED_EFFECTIVE_STANDING'),
                'scheduled_revision_ids' => array_column(array_values(array_filter($history,
                    fn ($revision) => new DateTimeImmutable($revision['effective_from']) > $effectiveAt)), 'revision_id')];
        }
        // Recheck after all queries; authority denial must never masquerade as no purpose.
        $this->authorize($request, $workspace);
        $applicable = array_values(array_filter($items, fn ($item) => $item['applicable'] !== null));
        $hasDeclaredConflict = count(array_filter($applicable, fn ($item) => $item['applicable']['snapshot']['conflicts'] !== [])) > 0;

        return ['state' => $hasDeclaredConflict ? 'CONFLICTING' : (count($applicable) > 1 ? 'MULTIPLE_APPLICABLE' : ($applicable === [] ? 'UNKNOWN' : 'DECLARED')),
            'items' => $items, 'current_context_version' => $contextVersion,
            'effective_at' => $effectiveAt->format('Y-m-d\TH:i:s.uP'), 'known_at' => $knownAt->format('Y-m-d\TH:i:s.uP')];
    }

    public function revision(Request $request, Workspace $workspace, string $revisionId): array
    {
        $this->authorize($request, $workspace);
        $row = DB::table('object_purpose_revisions')->where('workspace_id', $workspace->id)->where('revision_id', $revisionId)->first();
        abort_if($row === null, 404);
        $result = $this->revisionArray($row);
        $this->authorize($request, $workspace);

        return $result;
    }

    private function authorize(Request $request, Workspace $workspace): void
    {
        DB::scalar('SELECT builderad_object_purpose_read_begin(?,?)', [$workspace->id, SessionAuthority::sessionKey($request)]);
    }

    private function revisionArray(object $row): array
    {
        $result = (array) $row;
        foreach (['snapshot', 'confirmations', 'affected_revision_ids'] as $field) {
            $result[$field] = json_decode($result[$field], true, flags: JSON_THROW_ON_ERROR);
        }

        return $result;
    }

    private function covers(array $snapshot, array $object): bool
    {
        $equal = fn (array $tuple) => count($tuple) === count($object) && array_all($tuple,
            fn ($value, $key) => array_key_exists($key, $object) && $object[$key] === $value);

        return array_any($snapshot['included_objects'], $equal) && ! array_any($snapshot['excluded_objects'], $equal);
    }
}
