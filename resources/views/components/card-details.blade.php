@props(['article', 'previousArticle' => null, 'nextArticle' => null, 'setting' => null])

@php
    $locale = $locale ?? app()->getLocale();
    $date = $date ?? locale_date($article->generated_at);
    $readTime = $readTime ?? read_time($article->body_html);
    $image_url = $image_url ?? \Illuminate\Support\Facades\Storage::disk('public')->url($article->featured_image_url);
    $prefix = trim((string) ($setting?->route_prefix ?? null ?: 'blog'), '/');
    $blogBasePath = \Shazzoo\StrategyEngine\Support\ArticleRoutes::indexPath($locale, $prefix);
@endphp

<div class="mx-auto mt-5 max-w-5xl">
    <x-strategy-engine::article-breadcrumbs :article="$article" :locale="$locale" />

    <header class="py-8">
        <h1 class="mb-3 text-[clamp(34px,4.2vw,50px)] leading-[1.1] text-ink-950">
            {!! $article->title !!}
        </h1>
        <x-strategy-engine::article-info :date="$date" :time="$readTime" :authors="[$article->author_name]" />
    </header>

    <div class="mb-10">
        <img src="{{ $image_url }}" alt="{{ $article->featured_image_alt }}"
            class="cs-featured-image aspect-[16/9] w-full rounded-lg border border-line bg-bg-alt object-cover shadow-sm">
    </div>

    <div>
        @include('strategy-engine::partials.article-styles')

        <div class="article-body max-w-none">
            {!! $article->body_html !!}
        </div>

        {{-- Currently hardcoded for testing, is not yet in API response --}}
        {{-- <x-strategy-engine::article-tags /> --}}

        {{-- Over de auteur. De Engine zet dit niet meer in de body van het
             artikel, juist zodat een site het hier kan opmaken. Staat er niets
             in de velden, dan blijft het blok weg. --}}
        @php
            $authorName = filled($article->author_name) ? $article->author_name : null;
            $authorRole = collect([$article->author_role_title, $article->author_experience_label])
                ->filter()
                ->implode(' · ');
            $authorIntro = collect([$article->author_experience_summary, $article->author_article_relevance])
                ->filter(fn ($line) => filled($line))
                ->values();
        @endphp

        @if ($authorName && ($authorIntro->isNotEmpty() || filled($article->author_boundary_note)))
            <section class="mt-14 border-t border-line pt-8">
                <p class="mb-4 text-xs font-bold uppercase tracking-[0.08em] text-ink-400">
                    {{ __('strategy-engine::content_studio.about_the_author') }}
                </p>

                <h2 class="text-xl font-bold text-ink-950">{{ $authorName }}</h2>

                @if (filled($authorRole))
                    <p class="mt-1 text-sm text-ink-400">{{ $authorRole }}</p>
                @endif

                @foreach ($authorIntro as $paragraph)
                    <p class="mt-4 text-ink-800">{{ $paragraph }}</p>
                @endforeach

                @if (filled($article->author_boundary_note))
                    {{-- Waar de kennis van de auteur ophoudt: een kanttekening,
                         geen alinea erbij. --}}
                    <p class="mt-6 border-l-2 border-line pl-4 text-sm text-ink-400">
                        {{ $article->author_boundary_note }}
                    </p>
                @endif
            </section>
        @endif

        <section class="mt-14 grid grid-cols-1 gap-8 border-t border-line pt-8 md:grid-cols-2">
            <div>
                <p class="mb-4 text-xs font-bold uppercase tracking-[0.08em] text-ink-400">
                    {{ __('strategy-engine::content_studio.next_article') }}</p>
                @if ($previousArticle)
                    <a href="{{ url(rtrim($blogBasePath, '/') . '/' . ltrim((string) $previousArticle->slug, '/')) }}"
                        class="text-xl font-bold text-ink-950 transition hover:text-amber-600">
                        {{ $previousArticle->title }}
                    </a>
                @else
                    <p class="text-xl font-bold text-ink-400">
                        {{ __('strategy-engine::content_studio.no_previous_article') }}</p>
                @endif
            </div>
            <div class="text-right">
                <p class="mb-4 text-xs font-bold uppercase tracking-[0.08em] text-ink-400">
                    {{ __('strategy-engine::content_studio.previous_article') }}</p>
                @if ($nextArticle)
                    <a href="{{ url(rtrim($blogBasePath, '/') . '/' . ltrim((string) $nextArticle->slug, '/')) }}"
                        class="text-lg font-bold text-ink-950 transition hover:text-amber-600">
                        {{ $nextArticle->title }}
                    </a>
                @else
                    <p class="text-xl font-bold text-ink-400">
                        {{ __('strategy-engine::content_studio.no_next_article') }}</p>
                @endif
            </div>
        </section>
    </div>

    <x-strategy-engine::tracking :setting="$setting" :article="$article" />
</div>
