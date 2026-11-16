@php($ar = $locale === 'ar')
@php($c = fn ($a, $e) => $ar ? $a : $e)
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <script src="/brand/theme.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'BuilderAd')</title>
    <meta name="description" content="@yield('description', $c('محتوى منشور من BuilderAd.', 'Published BuilderAd content.'))">
    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">
    @yield('head')
    @include('public.styles')
    @include('public.content-styles')
    <style id="published-appearance">:root {@foreach($appearanceTokens as $token => $value){{ $token }}:{{ $value }};@endforeach}</style>
    <link rel="stylesheet" href="/brand/theme.css">
@include('public.appearance-assets')
</head>
<body>
<a class="public-skip" href="#main">{{ $c('انتقل إلى المحتوى', 'Skip to content') }}</a>
@if($maintenance['active'] ?? false)<aside class="public-notice" role="status">{{ $maintenance['message_'.$locale] }}</aside>@endif
@include('public.header', ['homeLinks' => false])
<main id="main">@yield('content')</main>
@include('public.footer')
</body>
</html>
