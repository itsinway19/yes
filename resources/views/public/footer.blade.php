@php($ar = $locale === 'ar')
@php($c = fn ($a, $e) => $ar ? $a : $e)
<footer class="public-footer-band">
    <div class="public-wrap public-footer-grid">
        <div class="public-footer-intro">
            @include('public.brand')
            @if($ar ? ($footer['description_ar'] ?? '') !== '' : ($footer['description_en'] ?? '') !== '')
                <p>{{ $ar ? $footer['description_ar'] : $footer['description_en'] }}</p>
            @endif
            @if(! empty($footer['social_links']))
                <ul class="public-social" aria-label="{{ $c('روابط التواصل', 'Social links') }}">
                    @foreach($footer['social_links'] as $social)
                        <li><a href="{{ $social['url'] }}" target="_blank" rel="noopener noreferrer">{{ $ar ? ($social['label_ar'] ?: $social['label_en']) : ($social['label_en'] ?: $social['label_ar']) }}</a></li>
                    @endforeach
                </ul>
            @endif
            @if($ar ? ($footer['copyright_ar'] ?? '') !== '' : ($footer['copyright_en'] ?? '') !== '')
                <p class="public-copyright">{{ $ar ? $footer['copyright_ar'] : $footer['copyright_en'] }}</p>
            @endif
        </div>
        @if($footer['show_sections'] ?? true)
            <div class="public-footer-sections">
                @foreach(($footer['sections'] ?? []) as $section)
                    <nav class="public-footer-section" aria-label="{{ $ar ? $section['name_ar'] : $section['name_en'] }}">
                        <h2>{{ $ar ? $section['name_ar'] : $section['name_en'] }}</h2>
                        <ul>
                            @foreach($section['links'] as $link)
                                <li><a href="{{ $ar ? $link['url_ar'] : $link['url_en'] }}" @if($link['open_in_new_tab']) target="_blank" rel="noopener noreferrer" @endif>{{ $ar ? ($link['label_ar'] ?? $link['label_en']) : ($link['label_en'] ?? $link['label_ar']) }}</a></li>
                            @endforeach
                        </ul>
                    </nav>
                @endforeach
            </div>
        @endif
    </div>
    <div class="public-wrap public-footer-bottom">
        <a href="/articles">{{ $c('المقالات', 'Articles') }}</a>
        <a href="/login">{{ $c('الدخول إلى المنصة', 'Open the platform') }}</a>
    </div>
</footer>
