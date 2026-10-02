<?php

namespace App\Providers\Ads;

use InvalidArgumentException;

/** Completeness describes this requested read, not all historic provider coverage. */
final readonly class ObservationBatch
{
    public function __construct(
        public ProviderScope $scope,
        public array $observations,
        public string $coverage,
        public ?string $continuation,
        public ?string $failure,
    ) {
        if (! in_array($coverage, ['COMPLETE', 'PARTIAL', 'FAILED', 'UNKNOWN', 'PENDING'], true)
            || ($coverage === 'COMPLETE' && ($continuation !== null || $failure !== null))
            || ($coverage === 'FAILED' && ($observations !== [] || $failure === null))
            || ($continuation !== null && ($continuation === '' || strlen($continuation) > 4096))
            || ($failure !== null && ! preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $failure))) {
            throw new InvalidArgumentException('Invalid report completion state.');
        }
        $identities = [];
        foreach ($observations as $observation) {
            if (! $observation instanceof AdObservation || $observation->scope->jsonSerialize() !== $scope->jsonSerialize()) {
                throw new InvalidArgumentException('Observations cannot cross their provider/Workspace scope.');
            }
            $identity = $observation->identity();
            if (isset($identities[$identity])) {
                throw new InvalidArgumentException('Duplicate measurement slots require explicit reconciliation.');
            }
            $identities[$identity] = true;
        }
    }
}
