<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>أهداف النشاط</title>
<script id="goals-bootstrap" type="application/json">@json(['identity' => $identity, 'locale' => $locale])</script>
@vite('resources/js/goals-spa/main.ts', 'build/goals-spa')
</head><body><div id="goals-spa"></div><noscript>يتطلب عرض الأهداف JavaScript.</noscript></body>
</html>
