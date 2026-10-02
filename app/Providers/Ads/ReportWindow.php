<?php

namespace App\Providers\Ads;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Provider-native boundaries are retained; inclusive date ranges are not rewritten as UTC days. */
final readonly class ReportWindow implements \JsonSerializable
{
    public function __construct(
        public string $start,
        public string $end,
        public string $boundary,
        public string $grain,
        public ?string $timezone,
        public string $semantics,
    ) {
        if (! in_array($boundary, ['INCLUSIVE_DATES', 'HALF_OPEN_INSTANTS'], true)
            || ! in_array($grain, ['TOTAL', 'DAY', 'HOUR'], true)
            || $semantics === '' || strlen($semantics) > 512
            || ($timezone !== null && ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true))) {
            throw new InvalidArgumentException('Invalid reporting time semantics.');
        }
        if ($boundary === 'INCLUSIVE_DATES') {
            foreach ([$start, $end] as $value) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (! $date || $date->format('Y-m-d') !== $value) {
                    throw new InvalidArgumentException('Invalid report date.');
                }
            }
            if ($start > $end || $grain === 'HOUR') {
                throw new InvalidArgumentException('Invalid inclusive date window.');
            }
        } else {
            foreach ([$start, $end] as $value) {
                if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
                    throw new InvalidArgumentException('An explicit time offset is required.');
                }
                $date = new DateTimeImmutable($value);
                $errors = DateTimeImmutable::getLastErrors();
                if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
                    throw new InvalidArgumentException('Invalid report instant.');
                }
            }
            if (new DateTimeImmutable($start) >= new DateTimeImmutable($end)) {
                throw new InvalidArgumentException('Invalid half-open report window.');
            }
        }
    }

    public function jsonSerialize(): array
    {
        return ['start' => $this->start, 'end' => $this->end, 'boundary' => $this->boundary,
            'grain' => $this->grain, 'timezone' => $this->timezone, 'semantics' => $this->semantics];
    }
}
