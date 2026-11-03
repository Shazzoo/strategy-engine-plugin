@props([
    'article',
    'setting' => null,
])

@php
    use Illuminate\Support\Facades\Storage;
    use Shazzoo\StrategyEngine\Models\ContentStudioArticle;

    $image_url = $article->featuredImageUrl();
    $locale = app()->getLocale();
    $date = locale_date($article->generated_at);
    $readTime = read_time($article->body_html);
    $isHub = ($article->content_type ?? 'article') === 'hub';

    $previousArticle = ContentStudioArticle::query()
        ->where('locale', app()->getLocale())
        ->whereNotNull('slug')
        ->where('slug', '!=', '')
        ->where(function ($query) use ($article) {
            $query->where('generated_at', '>', $article->generated_at)->orWhere(function ($query) use ($article) {
                $query->where('generated_at', $article->generated_at)->where('id', '>', $article->id);
            });
        })
        ->orderBy('generated_at')
        ->orderBy('id')
        ->first();

    $nextArticle = ContentStudioArticle::query()
        ->where('locale', app()->getLocale())
        ->whereNotNull('slug')
        ->where('slug', '!=', '')
        ->where(function ($query) use ($article) {
            $query->where('generated_at', '<', $article->generated_at)->orWhere(function ($query) use ($article) {
                $query->where('generated_at', $article->generated_at)->where('id', '<', $article->id);
            });
        })
        ->orderByDesc('generated_at')
        ->orderByDesc('id')
        ->first();

    $processedBody = $article->body_html;
    $headings = $headings ?? [];
@endphp

@if ($isHub)
    <x-strategy-engine::hub-details :article="$article" :setting="$setting" />
@else
    <x-strategy-engine::card-details :article="$article" :previousArticle="$previousArticle" :nextArticle="$nextArticle" :setting="$setting" />
@endif
