@extends('public.layout')
@php($ar = $locale === 'ar')
@php($title = $article['title_'.($ar ? 'ar' : 'en')] ?? $article['title_ar'])
@php($summary = $article['summary_'.($ar ? 'ar' : 'en')] ?? null)
@php($description = $article['seo_description_'.($ar ? 'ar' : 'en')] ?: $summary)
@section('title', ($article['seo_title_'.($ar ? 'ar' : 'en')] ?: $title).' — BuilderAd')
@section('description', $description ?: ($ar ? 'مقالة منشورة من BuilderAd.' : 'A published BuilderAd article.'))
@section('head')
    <meta property="og:type" content="article">
    <meta property="og:title" content="{{ $article['seo_title_'.($ar ? 'ar' : 'en')] ?: $title }}">
    <meta property="og:description" content="{{ $description ?: '' }}">
    <meta property="og:url" content="{{ $canonical }}">
    @if($article['image_path'])
        <meta property="og:image" content="{{ $article['image_path'] }}">
    @endif
@endsection
@section('content')
    <div class="public-wrap public-content">
        <article class="public-prose" data-public-article>
            <div class="public-article-meta">
                @if($article['category_name_'.($ar ? 'ar' : 'en')])
                    <a href="/articles/category/{{ $ar ? $article['category_slug_ar'] : $article['category_slug_en'] }}">{{ $article['category_name_'.($ar ? 'ar' : 'en')] }}</a>
                @endif
                @if($article['published_at'])
                    <time datetime="{{ $article['published_at'] }}">{{ \Illuminate\Support\Carbon::parse($article['published_at'])->isoFormat('LL') }}</time>
                @endif
            </div>
            <h1>{{ $title }}</h1>
            @if($summary)
                <p class="public-lead">{{ $summary }}</p>
            @endif
            @if($article['image_path'])
                <img class="public-article-image" src="{{ $article['image_path'] }}" alt="{{ $article['image_alt_'.($ar ? 'ar' : 'en')] ?? '' }}" loading="lazy">
            @endif
            {!! $article['body_'.($ar ? 'ar' : 'en')] !!}
        </article>
    </div>
@endsection
