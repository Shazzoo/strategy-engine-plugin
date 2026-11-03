@props(['article', 'blogBasePath' => null])

@php
    $image_url = $article->featuredImageUrl();
    $locale = app()->getLocale();
    $setting = \Shazzoo\StrategyEngine\Models\ContentStudioSetting::singleton();
    $prefix = trim((string) ($setting->route_prefix ?: 'blog'), '/');
    $blogBasePath = $blogBasePath ?? \Shazzoo\StrategyEngine\Support\ArticleRoutes::indexPath($locale, $prefix);
    $child_article_count = $article->hubChildren()->count();
    $hub_url = url(rtrim($blogBasePath, '/') . '/' . ltrim((string) $article->slug, '/'));
@endphp

@include('strategy-engine::partials.article-styles')

{{--
    De opbouw (volle breedte, afbeelding naast de tekst) staat in
    article-styles als cs-hub-*: het thema bouwt zijn CSS uit zijn eigen
    views, en klassen die alleen hier staan zitten daar niet in.
--}}
<div class="cs-hub overflow-hidden rounded-lg border border-line bg-surface shadow-sm {{ $image_url ? 'cs-hub--image' : '' }}">
    @if ($image_url)
        <div class="cs-hub__media">
            <img src="{{ $image_url }}" alt="{{ $article->featured_image_alt }}" class="cs-featured-image bg-bg-alt">
        </div>
    @endif
    <div class="cs-hub__content">
        <div>
            <h2 class="cs-hub__title text-ink-950">
                {!! $article->title !!}
            </h2>

            <p class="cs-hub__excerpt text-ink-600">
                {!! $article->excerpt !!}
            </p>
        </div>
        <div class="cs-hub__footer border-line">
            <div class="text-sm font-medium text-ink-600">
                <span class="cs-hub__label">
                    {{ __('strategy-engine::content_studio.pillar_content') }}
                </span>

                @if ($child_article_count > 0)
                    {{ __('strategy-engine::content_studio.contains_deep_dive_articles', ['count' => $child_article_count]) }}
                @endif
            </div>
            <a href="{{ $hub_url }}"
                class="inline-flex items-center justify-center rounded-lg border border-transparent bg-amber px-4 py-2 text-center text-sm font-bold text-amber-ink transition hover:bg-amber-hover">
                {{ __('strategy-engine::content_studio.view_full_guide') }}
            </a>
        </div>
    </div>
</div>
