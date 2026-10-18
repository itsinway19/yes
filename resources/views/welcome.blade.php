<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>BuilderAd</title>
<style>body{font:18px system-ui;line-height:1.8;max-width:760px;margin:auto;padding:32px;color:#153e38}h1{font-size:38px}nav{display:flex;flex-wrap:wrap;gap:24px}a{color:#14635b}a:focus-visible{outline:2px solid #14635b;outline-offset:5px}</style></head>
<body><main><h1>BuilderAd</h1><p>{{ $locale === 'ar' ? 'ابدأ من حسابك لإدارة أنشطتك ومصادرك الإعلانية.' : 'Start with your account to manage your businesses and advertising sources.' }}</p><nav><a href="/login">{{ $locale === 'ar' ? 'تسجيل الدخول' : 'Sign in' }}</a><a href="/register">{{ $locale === 'ar' ? 'إنشاء حساب' : 'Create account' }}</a><a href="/forgot-password">{{ $locale === 'ar' ? 'استعادة الحساب' : 'Recover account' }}</a></nav></main></body>
</html>
