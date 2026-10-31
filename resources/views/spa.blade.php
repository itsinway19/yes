<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head><script src="/brand/theme.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BuilderAd</title>
    <script id="application-bootstrap" type="application/json">@json($bootstrap)</script>
    @vite('resources/js/spa/main.ts', 'build/goals-spa')
    <style id="published-appearance">:root {@foreach($appearanceTokens as $token => $value){{ $token }}:{{ $value }};@endforeach}</style>
<link rel="stylesheet" href="/brand/theme.css"></head>
<body><div id="application-spa" data-application-spa></div><noscript>{{ $locale === 'ar' ? 'يتطلب التطبيق JavaScript.' : 'This application requires JavaScript.' }}</noscript></body>
</html>
