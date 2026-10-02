<?php

namespace App\Providers\Ads;

use InvalidArgumentException;

/** No floats, currency conversion, or implicit assumption that every provider uses micros. */
final readonly class NativeAmount implements \JsonSerializable
{
    public function __construct(
        public ?string $value,
        public ?string $currency,
        public string $unit,
        public ?int $decimalScale,
        public ?string $scaleBasis,
    ) {
        if (($value !== null && ! preg_match('/^-?\d{1,60}(?:\.\d{1,30})?$/D', $value))
            || ($currency !== null && ! preg_match('/^[A-Z]{3}$/D', $currency))
            || $unit === '' || strlen($unit) > 80 || preg_match('/[\x00-\x1f\x7f]/', $unit)
            || ($decimalScale !== null && ($decimalScale < 0 || $decimalScale > 18 || ! $scaleBasis))
            || ($decimalScale === null && $scaleBasis !== null)
            || ($scaleBasis !== null && strlen($scaleBasis) > 512)) {
            throw new InvalidArgumentException('Invalid native monetary observation or unit evidence.');
        }
    }

    /** Exact native value / 10^scale; no rounding to currency display precision. */
    public function majorUnits(): ?string
    {
        if ($this->value === null || $this->decimalScale === null) {
            return null;
        }
        $negative = str_starts_with($this->value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($this->value, '-'), 2), 2, '');
        $digits = $whole.$fraction;
        $places = strlen($fraction) + $this->decimalScale;
        $digits = str_pad($digits, $places + 1, '0', STR_PAD_LEFT);
        $integer = ltrim($places === 0 ? $digits : substr($digits, 0, -$places), '0') ?: '0';
        $decimal = $places === 0 ? '' : rtrim(substr($digits, -$places), '0');
        $result = $integer.($decimal === '' ? '' : '.'.$decimal);

        return ($negative && $result !== '0' ? '-' : '').$result;
    }

    public function jsonSerialize(): array
    {
        return ['native_value' => $this->value, 'currency' => $this->currency, 'native_unit' => $this->unit,
            'decimal_scale' => $this->decimalScale, 'scale_basis' => $this->scaleBasis,
            'major_units' => $this->majorUnits()];
    }
}
