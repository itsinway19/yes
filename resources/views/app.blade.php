<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(strtolower(explode('-', str_replace('_', '-', app()->getLocale()))[0]), ['ar', 'fa', 'he', 'ur'], true) ? 'rtl' : 'ltr' }}">
    <head>
        <script>document.documentElement.dataset.visibility = 'checking';</script>
        <style>
            html:not([data-visibility="ready"]) #app { visibility:hidden !important; opacity:0 !important; pointer-events:none !important; }
            #visibility-status { display:none; position:fixed; inset:0; z-index:2147483647; background:#fafbf8; color:#182d30; align-items:center; justify-content:center; flex-direction:column; gap:16px; font-family:Arial,sans-serif; }
            html:not([data-visibility="ready"]) #visibility-status { display:flex; }
            #visibility-retry { padding:12px 20px; border:1px solid #14635b; border-radius:5px; cursor:pointer; }
        </style>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

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
        @inertia
    </body>
</html>
