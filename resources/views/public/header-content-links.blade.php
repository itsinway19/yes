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
