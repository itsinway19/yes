<?php

namespace App\Commercial;

use JsonSerializable;

/** Read-only semantic model. Only the governed command can create a persisted version. */
final readonly class CommercialMarketConfigurationVersion implements JsonSerializable
{
    public function __construct(
        public string $id,
        public string $market_id,
        public int $version,
        public ?string $catalog_reference_id,
        public ?string $denomination_code,
        public string $recorded_at,
    ) {}

    public static function fromRecord(object $record): self
    {
        return new self($record->id, $record->market_id, (int) $record->version,
            $record->catalog_reference_id, $record->denomination_code, $record->recorded_at);
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
