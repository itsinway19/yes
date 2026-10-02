<?php

namespace App\Providers\Ads;

use InvalidArgumentException;

/** Validated/mapped read awaiting durable acceptance. Construction does not advance any progress. */
final readonly class ProviderReadResult
{
    public function __construct(public ObservationBatch $batch, public array $readProvenance, public ?NativeProgress $progress)
    {
        if (count($readProvenance) !== 3 || array_diff(array_keys($readProvenance), ['api_version', 'report', 'documentation_basis'])
            || ($progress !== null && $batch->coverage !== 'COMPLETE')) {
            throw new InvalidArgumentException('Only resolved, validated reads may propose durable progress.');
        }
        foreach ($readProvenance as $value) {
            if (! is_string($value) || $value === '' || strlen($value) > 512) {
                throw new InvalidArgumentException('Every read, including empty results, requires native provenance.');
            }
        }
    }
}
