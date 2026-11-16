@php($publishedBrand = ($fallbackBrand ?? false) ? ['logo' => null, 'favicon' => null] : app(\App\Appearance\GlobalAppearance::class)->current())
<meta name="builderad-logo" content="{{ $publishedBrand['logo']['url'] ?? '' }}">
<link rel="icon" type="{{ $publishedBrand['favicon'] ? 'image/png' : 'image/svg+xml' }}" href="{{ $publishedBrand['favicon']['url'] ?? '/brand/builderad-mark.svg' }}">
