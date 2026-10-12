<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(strtolower(explode('-', str_replace('_', '-', app()->getLocale()))[0]), ['ar', 'fa', 'he', 'ur'], true) ? 'rtl' : 'ltr' }}">
    <head>
        <script>document.documentElement.dataset.visibility = 'checking';</script>
        <style>
            html:not([data-visibility="ready"]) #app { visibility:hidden !important; opacity:0 !important; pointer-events:none !important; }
            #visibility-status { display:none; position:fixed; inset:0; z-index:2147483647; background:#fafbf8; color:#182d30; align-items:center; justify-content:center; flex-direction:column; gap:16px; font-family:Arial,sans-serif; }
            html:not([data-visibility="ready"]) #visibility-status { display:flex; }
            #logout-status { display:flex; position:fixed; inset:0; z-index:2147483647; background:#fafbf8; color:#182d30; align-items:center; justify-content:center; flex-direction:column; gap:16px; padding:24px; font-family:Arial,sans-serif; }
            #logout-status[hidden] { display:none; }
            #visibility-retry { padding:12px 20px; border:1px solid #14635b; border-radius:5px; cursor:pointer; }
        </style>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @if(isset($page['props']['identity']))
            <meta name="csrf-token" content="{{ $page['props']['identity']['csrf'] }}">
            <meta name="identity-context" content="{{ json_encode($page['props']['identity']) }}">
        @endif

        <title inertia>{{ config('app.name', 'Builderad') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.ts', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        <div id="visibility-status" role="status" aria-live="polite">
            <p>{{ app()->getLocale() === 'ar' ? 'جارٍ التحقق من صلاحية عرض الصفحة. إذا تعذر الاتصال، يمكنك إعادة المحاولة.' : 'Checking access to this page. If the connection is unavailable, you can retry.' }}</p>
            <button id="visibility-retry" type="button">{{ app()->getLocale() === 'ar' ? 'إعادة المحاولة' : 'Retry' }}</button>
        </div>
        <div id="visibility-navigation-error" hidden role="status" aria-live="polite" style="position:fixed;bottom:16px;inset-inline-end:16px;z-index:2147483647;padding:16px;background:#fafbf8;color:#182d30;border:1px solid #14635b;max-width:calc(100% - 32px)">
            <p>{{ app()->getLocale() === 'ar' ? 'تعذّر التحقق من الصفحة المطلوبة. بقيت الصفحة الحالية مفتوحة؛ يمكنك إعادة المحاولة.' : 'Could not verify the requested page. Your current page remains open; you can retry.' }}</p>
            <button id="visibility-navigation-retry" type="button">{{ app()->getLocale() === 'ar' ? 'إعادة المحاولة' : 'Retry' }}</button>
        </div>
        <div id="logout-status" hidden role="status" aria-live="polite">
            <p>{{ app()->getLocale() === 'ar' ? 'بدأ طلب الخروج. هذه الوثيقة مغلقة؛ لم نؤكد إبطال الجلسة بعد. إذا لم تكتمل المغادرة، انتقل إلى الخادم للتحقق.' : 'Logout was requested. This document is closed; session revocation is not yet confirmed. If departure does not complete, continue to the server to check.' }}</p>
            <p>{{ app()->getLocale() === 'ar' ? 'استبدال الوثيقة لا يضمن استرجاع مسودة لم تُحفظ بصورة مستقلة.' : 'Replacing this document does not guarantee recovery of a draft that was not saved independently.' }}</p>
            <a id="logout-document-exit" href="{{ request()->is('internal/*') ? '/internal/login' : '/' }}">{{ app()->getLocale() === 'ar' ? 'الانتقال إلى الخادم' : 'Continue to server' }}</a>
        </div>
        @inertia
    </body>
</html>
