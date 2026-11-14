<?php

namespace App\Business;

use DateTimeImmutable;

/** Canonical declaration access within the signed Business transaction. */
final class ObjectPurpose
{
    /** Pure native temporal owner; consumes only qualified Business rows. */
    public function projectHistory(array $heads, int $contextVersion, int $dtmTransitionVersion, DateTimeImmutable $effectiveAt, DateTimeImmutable $knownAt, ?array $object = null, bool $historyAvailable = true): array
    {
        $items = [];
        foreach ($heads as $head) {
            $qualified = $historyAvailable ? $head->history : ($head->current === null ? [] : [$head->current]);
            $history = array_values(array_filter($qualified, fn ($revision) => new DateTimeImmutable($revision['accepted_at']) <= $knownAt));
            $segments = [];
            $transitions = [];
            foreach ($history as $revision) {
                $from = new DateTimeImmutable($revision['effective_from']);
                $transitions = array_values(array_filter($transitions,
                    fn ($transition) => new DateTimeImmutable($transition['effective_from']) < $from));
                $transitions[] = $revision;
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
            $revalidate = $dtm !== null && ! $historical && $dtmTransitionVersion > $dtm['version'];
            $items[] = ['purpose_id' => $head->id, 'head_version' => (int) $head->head_version,
                'state' => $applicable === null ? 'UNKNOWN' : 'DECLARED', 'applicable' => $applicable, 'history' => $historyAvailable ? $history : [],
                'dtm_standing' => $dtm === null ? 'NO_RELATION_DECLARED' : ($historical ? 'HISTORICAL_ATTESTATION' : ($revalidate
                    ? 'REFERENCED_CURRENT_CLAIM_REQUIRES_REVALIDATION' : 'CURRENT_REFERENCE_AT_READ')),
                'relationship_revalidation_required' => $revalidate,
                'continuity' => $applicable === null ? 'NOT_ESTABLISHED' : ($applicable['snapshot']['end_condition'] !== null
                    ? 'CONDITION_CONTINUITY_NOT_ESTABLISHED' : 'DECLARED_EFFECTIVE_STANDING'),
                'scheduled_revision_ids' => array_column(array_values(array_filter($transitions,
                    fn ($revision) => new DateTimeImmutable($revision['effective_from']) > $effectiveAt)), 'revision_id')];
        }
        $applicable = array_values(array_filter($items, fn ($item) => $item['applicable'] !== null));
        $hasDeclaredConflict = count(array_filter($applicable, fn ($item) => $item['applicable']['snapshot']['conflicts'] !== [])) > 0;

        return ['state' => $hasDeclaredConflict ? 'CONFLICTING' : (count($applicable) > 1 ? 'MULTIPLE_APPLICABLE' : ($applicable === [] ? 'UNKNOWN' : 'DECLARED')),
            'items' => $items, 'current_context_version' => $contextVersion, 'latest_dtm_event_context_version' => $dtmTransitionVersion,
            'effective_at' => $effectiveAt->format('Y-m-d\TH:i:s.uP'), 'known_at' => $knownAt->format('Y-m-d\TH:i:s.uP')];
    }

    private function covers(array $snapshot, array $object): bool
    {
        $equal = fn (array $tuple) => count($tuple) === count($object) && array_all($tuple,
            fn ($value, $key) => array_key_exists($key, $object) && $object[$key] === $value);

        return array_any($snapshot['included_objects'], $equal) && ! array_any($snapshot['excluded_objects'], $equal);
    }
}
