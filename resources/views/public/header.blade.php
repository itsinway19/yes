@php($ar = $locale === 'ar')
@php($c = fn ($a, $e) => $ar ? $a : $e)
<header class="public-wrap public-nav public-nav--content">
    @include('public.brand')
    @unless($homeLinks ?? false)
        <div class="public-nav-actions">@include('public.theme-toggle')</div>
    @endunless
    <nav class="public-links" aria-label="{{ $c('التنقل الرئيسي', 'Main navigation') }}">
        @if($homeLinks ?? false)
            <a href="#how">{{ $c('الحل', 'Solution') }}</a>
            <a href="#benefits">{{ $c('الفوائد', 'Benefits') }}</a>
            <a href="#platforms">{{ $c('المنصات', 'Platforms') }}</a>
            <a href="#questions">{{ $c('الأسئلة الشائعة', 'FAQ') }}</a>
        @endif
        @include('public.header-content-links')
        @unless($homeLinks ?? false)
            <a href="/login">{{ $c('تسجيل الدخول', 'Sign in') }}</a>
        @endunless
    @if($homeLinks ?? false)
    </nav>
    <div class="public-nav-actions">
        @include('public.theme-toggle', ['iconOnly' => true])
    @endif
        <form class="public-language" method="post" action="/locale" novalidate>
            @csrf
            <input type="hidden" name="_identity_receipt" value="{{ $publicIdentity['receipt'] ?? '' }}">
            <input type="hidden" name="locale" value="{{ $ar ? 'en' : 'ar' }}">
            <button type="submit" lang="{{ $ar ? 'en' : 'ar' }}" aria-label="{{ $ar ? 'Switch language to English' : 'تغيير اللغة إلى العربية' }}">
                @if($homeLinks ?? false)
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h12M10 3v2m4 0c-.8 5-4 8-8 10m1-6c1 2 3 4 6 5m1 7 4-10 4 10m-6-3h5"/></svg>
                @else
                    {{ $ar ? 'English' : 'العربية' }}
                @endif
            </button>
        </form>
    @if($homeLinks ?? false)
        <a class="public-sign-in" href="/login">{{ $c('تسجيل الدخول', 'Sign in') }}</a>
        <a class="public-button primary" href="/register">{{ $c('ابدأ الآن', 'Get started') }}</a>
    </div>
    @else
    </nav>
    @endif
</header>
