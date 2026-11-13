@extends('public.layout')
@php($ar = $locale === 'ar')
@php($c = fn ($a, $e) => $ar ? $a : $e)
@php($category = $listing['category'] ?? null)
@php($heading = $category ? ($category[$ar ? 'name_ar' : 'name_en']) : $c('المقالات', 'Articles'))
@section('title', $heading.' — BuilderAd')
@section('description', $category ? ($category[$ar ? 'description_ar' : 'description_en'] ?: $heading) : $c('مقالات منشورة من BuilderAd.', 'Published BuilderAd articles.'))
@section('content')
    <div class="public-wrap public-content">
        <header class="public-heading">
            <span class="public-eyebrow">{{ $c('المعرفة والمحتوى', 'Knowledge and content') }}</span>
            <h1>{{ $heading }}</h1>
            @if($category && $category[$ar ? 'description_ar' : 'description_en'])
                <p>{{ $category[$ar ? 'description_ar' : 'description_en'] }}</p>
            @endif
        </header>
        @if(count($categories))
            <nav class="public-chips" aria-label="{{ $c('تصنيفات المقالات', 'Article categories') }}">
                <a class="public-chip" href="/articles" @if(! $category) aria-current="page" @endif>{{ $c('الكل', 'All') }}</a>
                @foreach($categories as $item)
                    <a class="public-chip" href="{{ $ar ? $item['url_ar'] : $item['url_en'] }}" @if($category && $category['public_id'] === $item['public_id']) aria-current="page" @endif>{{ $item[$ar ? 'name_ar' : 'name_en'] }}</a>
                @endforeach
            </nav>
        @endif
        @if(count($listing['articles']))
            <div class="public-cards">
                @foreach($listing['articles'] as $item)
                    <a class="public-card" href="{{ $ar ? $item['url_ar'] : $item['url_en'] }}">
                        @if($item['category_name_'.($ar ? 'ar' : 'en')])
                            <span class="public-state">{{ $item['category_name_'.($ar ? 'ar' : 'en')] }}</span>
                        @endif
                        <h2>{{ $item['title_'.($ar ? 'ar' : 'en')] }}</h2>
                        @if($item['summary_'.($ar ? 'ar' : 'en')])
                            <p>{{ $item['summary_'.($ar ? 'ar' : 'en')] }}</p>
                        @endif
                        @if($item['published_at'])
                            <time datetime="{{ $item['published_at'] }}">{{ \Illuminate\Support\Carbon::parse($item['published_at'])->isoFormat('LL') }}</time>
                        @endif
                    </a>
                @endforeach
            </div>
        @else
            <p>{{ $c('لا توجد مقالات منشورة بعد.', 'No published articles yet.') }}</p>
        @endif
    </div>
@endsection
