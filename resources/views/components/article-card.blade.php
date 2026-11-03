@props(['article', 'clickable' => true, 'showExcerpt' => true, 'blogBasePath' => null])

@php
    use Illuminate\Support\Facades\Storage;

    $image_url = $article->featuredImageUrl();
    $locale = app()->getLocale();
    $setting = \Shazzoo\StrategyEngine\Models\ContentStudioSetting::singleton();
    $prefix = trim((string) ($setting->route_prefix ?: 'blog'), '/');
    $blogBasePath = $blogBasePath ?? \Shazzoo\StrategyEngine\Support\ArticleRoutes::indexPath($locale, $prefix);
    $readTime = $readTime ?? read_time($article->body_html);
    $fm = new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::NONE);
    $date = $fm->format($article->generated_at);

    $clickable = $clickable ?? true;

    $url = url(rtrim($blogBasePath, '/') . '/' . ltrim((string) $article->slug, '/'));
@endphp

@include('strategy-engine::partials.article-styles')

<article class="group h-full">
    @if ($clickable)
        <a href="{{ $url }}" class="block h-full">
    @endif

    <div class="flex h-full flex-col">
        @if ($image_url)
            <div class="mb-4 overflow-hidden rounded-lg border border-line bg-surface shadow-sm">
                <img src="{{ $image_url }}" alt="{{ $article->featured_image_alt }}"
                    class="cs-featured-image transition duration-500 group-hover:scale-105">
            </div>
        @endif

        <div class="flex-1">
            <div class="grid w-full grid-cols-[minmax(0,1fr)_auto] items-center gap-4">
                <div class="flex min-w-0 items-center gap-1.5">
                    <p class="m-0 truncate text-sm text-ink-600">{{ $article->author_name }}</p>
                    @if ($article->author_name)
                        <span class="text-ink-400">•</span>
                    @endif
                    <p class="m-0 flex-none text-sm italic text-ink-400">{{ $readTime }} min</p>
                </div>
                <p class="m-0 text-right text-sm text-ink-400">{{ $date }}</p>
            </div>

            <h3 class="mt-2 text-xl font-bold leading-snug text-ink-950 transition group-hover:text-amber-600">
                {{ $article->title }}
            </h3>

            @if ($showExcerpt)
                <p class="mt-1 text-sm leading-relaxed text-ink-600">
                    {{ $article->excerpt }}
                </p>
            @endif
        </div>

        @if ($showExcerpt)
            @if (!$clickable)
                <a href="{{ $url }}"
                    class="mt-4 text-sm font-bold text-ink-950 transition hover:text-amber-600 hover:underline">
                    {{ __('strategy-engine::content_studio.read_more') }}
                </a>
            @else
                <span
                    class="mt-4 text-sm font-bold text-ink-950 transition group-hover:text-amber-600 group-hover:underline">
                    {{ __('strategy-engine::content_studio.read_more') }}
                </span>
            @endif
        @endif

    </div>

    @if ($clickable)
        </a>
    @endif
</article>
