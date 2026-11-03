@props([
    'article',
    'setting' => null,
    'locale' => null,
    'date' => null,
    'image_url' => null,
    'processedBody' => null,
    'headings' => [],
])

@php
    $locale = $locale ?? app()->getLocale();
    $date = $date ?? locale_date($article->generated_at);
    $readTime = $readTime ?? read_time($article->body_html);
    $image_url = $image_url ?? $article->featuredImageUrl();
    $processedBody = $processedBody ?? $article->body_html;
    $authors = $article->bylineNames();
@endphp

<div class="mx-auto mt-5 max-w-7xl">
    <x-strategy-engine::article-breadcrumbs :article="$article" :locale="$locale" />
    <header class="pt-8">
        <div class="flex flex-col items-center gap-12 lg:flex-row">
            <div class="md:w-2/3">

                <h1 class="mb-6 text-[clamp(34px,4.8vw,58px)] leading-[1.1] text-ink-950">
                    {!! $article->title !!}
                </h1>

                <p class="max-w-2xl border-l-4 border-amber pl-6 text-xl italic leading-relaxed text-ink-600">
                    {!! $article->excerpt !!}
                </p>

                <div class="pt-6">
                    <x-strategy-engine::article-info :date="$date" :time="$readTime" :authors="$authors" :byline="$article->byline()" />
                </div>
            </div>

            @if ($image_url)
                <div class="block md:w-1/3">
                    <div class="relative">
                        <div class="absolute inset-0 rotate-2 rounded-lg bg-amber-soft"></div>
                        <img src="{{ $image_url }}" alt="{{ $article->featured_image_alt }}"
                            class="cs-featured-image relative aspect-[16/9] w-full -rotate-2 rounded-lg border border-line object-cover shadow-sm transition duration-500 hover:rotate-0">
                    </div>
                </div>
            @endif
        </div>
    </header>
    <div class="mx-auto max-w-7xl py-16">
        <div class="flex flex-col gap-16 lg:flex-row">

            <aside class="lg:w-1/4 pt-6">
                <div class="sticky top-36">
                    <div>
                        <h4 class="mb-4 ml-2 text-xs font-bold uppercase tracking-[0.08em] text-ink-600">
                            {{ __('strategy-engine::content_studio.table_of_contents') }}
                        </h4>
                        <nav class="space-y-3 border-l border-line">
                            @foreach ($headings as $heading)
                                <a href="#{{ str($heading['title'])->slug() }}"
                                    class="block pl-4 text-sm text-ink-600 transition hover:text-amber-600">{{ $heading['title'] }}</a>
                            @endforeach
                        </nav>
                    </div>

                    {{-- Een beoordeling alleen noemen als een mens het artikel las. --}}
                    @if ($article->mayClaimReview())
                        <div class="rounded-lg border border-line bg-bg-alt p-6">
                            <p class="mb-2 text-xs font-bold uppercase text-ink-400">
                                {{ __('strategy-engine::content_studio.expert_review') }}</p>
                            <p class="text-sm leading-relaxed text-ink-800">
                                {{ __('strategy-engine::content_studio.updated_on_by_team', ['date' => $date]) }}
                            </p>
                        </div>
                    @endif
                </div>
            </aside>

            <section class="lg:w-3/4">
                @include('strategy-engine::partials.article-styles')

                <x-strategy-engine::provenance :article="$article" :at="['top']" />

                <section class="article-body max-w-none">
                    {!! $processedBody !!}
                </section>

                <x-strategy-engine::provenance :article="$article" :at="['author', 'bottom']" />

                @if ($article->hubChildren()->isNotEmpty())
                <section id="verdieping" class="mt-24 border-t border-line pt-16">
                    <h2 class="mb-2 text-3xl font-black text-ink-950">
                        {{ __('strategy-engine::content_studio.cluster_overview') }}
                    </h2>
                    <p class="mb-10 text-lg leading-relaxed text-ink-600">
                        {{ __('strategy-engine::content_studio.dive_into_details') }}</p>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        @foreach ($article->hubChildren() as $child_article)
                            <x-strategy-engine::article-card :article="$child_article" :clickable="true" />
                        @endforeach
                    </div>
                </section>
                @endif
            </section>
        </div>
    </div>

    <x-strategy-engine::tracking :setting="$setting" :article="$article" />
</div>
