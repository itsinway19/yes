@extends('public.layout')
@php($ar = $locale === 'ar')
@php($title = $page['title_'.($ar ? 'ar' : 'en')] ?? $page['title_ar'])
@php($description = $page['seo_description_'.($ar ? 'ar' : 'en')] ?? $page['excerpt_'.($ar ? 'ar' : 'en')])
@section('title', ($page['seo_title_'.($ar ? 'ar' : 'en')] ?: $title).' — BuilderAd')
@section('description', $description ?: ($ar ? 'صفحة منشورة من BuilderAd.' : 'A published BuilderAd page.'))
@section('head')
    <meta property="og:type" content="article">
    <meta property="og:title" content="{{ $page['seo_title_'.($ar ? 'ar' : 'en')] ?: $title }}">
    <meta property="og:description" content="{{ $description ?: '' }}">
    <meta property="og:url" content="{{ $canonical }}">
@endsection
@section('content')
    <div class="public-wrap public-content">
        <article class="public-prose" data-public-page>
            <h1>{{ $title }}</h1>
            @if($description)
                <p class="public-lead">{{ $description }}</p>
            @endif
            {!! $page['body_'.($ar ? 'ar' : 'en')] !!}
        </article>
    </div>
@endsection
