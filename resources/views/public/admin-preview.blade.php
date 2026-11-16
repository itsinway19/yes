@php($ar = $locale === 'ar')
@php($c = fn ($a, $e) => $ar ? $a : $e)
@php($title = $preview['title_'.($ar ? 'ar' : 'en')] ?? $preview['title_ar'])
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <script src="/brand/theme.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $c('معاينة', 'Preview') }} — {{ $title }}</title>
    @include('public.styles')
    @include('public.content-styles')
    <style>
        .preview-banner{margin:0;padding:12px 24px;background:#fff5dd;border-bottom:1px solid #e6d5a8;color:#59451a;font-size:14px;display:flex;gap:18px;flex-wrap:wrap;align-items:center}
        .preview-banner strong{font-weight:700}.preview-banner a{color:#59451a}
    </style>
    <link rel="stylesheet" href="/brand/theme.css">
@include('public.appearance-assets')
</head>
<body>
<aside class="preview-banner" role="status">
    <strong>{{ $c('معاينة محمية — غير منشورة', 'Protected preview — unpublished') }}</strong>
    <span>{{ $c('هذه المعاينة تتطلب صلاحية إدارية ولا تُفهرس ولا تُخزَّن مؤقتًا.', 'This preview requires administration authority and is not indexed or publicly cached.') }}</span>
    <a href="{{ $kind === 'page' ? '/internal/administration/content/pages' : '/internal/administration/content/articles' }}">{{ $c('العودة إلى الإدارة', 'Back to administration') }}</a>
</aside>
<main id="main">
    <div class="public-wrap public-content">
        <article class="public-prose">
            <h1>{{ $title }}</h1>
            {!! $preview['body_'.($ar ? 'ar' : 'en')] !!}
        </article>
    </div>
</main>
</body>
</html>
