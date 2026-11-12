@php($ar = $locale === 'ar')
@php($c = fn ($a, $e) => $ar ? $a : $e)
<header class="public-wrap public-nav public-nav--content">
    @include('public.brand')
    <div class="public-nav-actions">@include('public.theme-toggle')</div>
    <nav class="public-links" aria-label="{{ $c('التنقل الرئيسي', 'Main navigation') }}">
        @if($homeLinks ?? false)
            <a href="#how">{{ $c('كيف تعمل؟', 'How it works') }}</a>
            <a href="#available">{{ $c('المتاح اليوم', 'Available today') }}</a>
            <a href="#questions">{{ $c('أسئلة شائعة', 'Common questions') }}</a>
        @endif
        @foreach(($navigation['header'] ?? []) as $section)
            @if(count($section['links']) === 1)
                @php($link = $section['links'][0])
                <a href="{{ $ar ? $link['url_ar'] : $link['url_en'] }}" @if($link['open_in_new_tab']) target="_blank" rel="noopener noreferrer" @endif>{{ $ar ? $section['name_ar'] : $section['name_en'] }}</a>
            @else
                <details class="public-menu">
                    <summary>{{ $ar ? $section['name_ar'] : $section['name_en'] }}</summary>
                    <div class="public-menu-panel">
                        @foreach($section['links'] as $link)
                            <a href="{{ $ar ? $link['url_ar'] : $link['url_en'] }}" @if($link['open_in_new_tab']) target="_blank" rel="noopener noreferrer" @endif>{{ $ar ? ($link['label_ar'] ?? $link['label_en']) : ($link['label_en'] ?? $link['label_ar']) }}</a>
                        @endforeach
                    </div>
                </details>
            @endif
        @endforeach
        <a href="/articles">{{ $c('المقالات', 'Articles') }}</a>
        <a href="/login">{{ $c('تسجيل الدخول', 'Sign in') }}</a>
        <form class="public-language" method="post" action="/locale" novalidate>
            @csrf
            <input type="hidden" name="_identity_receipt" value="{{ $publicIdentity['receipt'] ?? '' }}">
            <input type="hidden" name="locale" value="{{ $ar ? 'en' : 'ar' }}">
            <button type="submit" lang="{{ $ar ? 'en' : 'ar' }}">{{ $ar ? 'English' : 'العربية' }}</button>
        </form>
    </nav>
</header>
