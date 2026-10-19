<?php

namespace Shazzoo\StrategyEngine\Support\Seo;

use Shazzoo\StrategyEngine\Models\ContentStudioArticle;
use Shazzoo\StrategyEngine\Support\ArticleRoutes;
use Shazzoo\ContentStudioCore\Support\Seo\Dto\SeoMetadata;

final class ArticleSeoBuilder
{
    public function indexBuild($articles = null, ?string $locale = null): SeoMetadata
    {
        $appName = config('app.name');
        $title = "Artikelen & Insights - {$appName}";

        $currentPage = request()->get('page', 1);
        $canonical = $this->articleIndexUrl($locale);
        $alternates = $this->indexAlternates($locale);

        if ($currentPage > 1) {
            $title .= " - Pagina {$currentPage}";
            $canonical = $this->articleIndexUrl($locale, ['page' => $currentPage]);

            /* Vanaf pagina 2 geen hreflang. Een andere taal heeft een andere
               hoeveelheid artikelen, dus pagina 2 daar toont iets anders -
               en bestaat soms niet eens. Naar de eerste pagina wijzen is net
               zo fout: dat is niet dezelfde pagina. */
            $alternates = [];
        }

        $description = 'Ontdek de laatste trends over Laravel, AI-oplossingen en softwareontwikkeling bij Shazzoo.';

        // Bouw ItemList voor JSON-LD als je artikelen meegeeft
        $itemList = [];
        if ($articles) {
            foreach ($articles as $index => $article) {
                if (! filled($article->slug)) {
                    continue;
                }

                $itemList[] = [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'url' => $this->articleUrl($article->slug, $locale),
                    'name' => $article->title,
                ];
            }
        }

        return new SeoMetadata(
            title: $title,
            description: $description,
            canonical: $canonical,
            alternates: $alternates,
            openGraph: [
                'title' => $title,
                'description' => $description,
                'url' => $canonical,
                'type' => 'website',
                'image' => 'https://shazzoo.nl/images/og-blog-overview.png', // Nooit null laten!
            ],
            jsonLd: [
                '@context' => 'https://schema.org',
                '@type' => 'Blog',
                'name' => $title,
                'description' => $description,
                'url' => $canonical,
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $appName,
                    'logo' => ['@type' => 'ImageObject', 'url' => 'https://shazzoo.nl/themes/shazzoo-corporate-theme/images/shazzooVert.svg'],
                ],
                'mainEntity' => [
                    '@type' => 'ItemList',
                    'itemListElement' => $itemList,
                ],
            ],
            robots: 'index,follow',
        );
    }

    /**
     * Bouw SEO metadata specifiek voor een enkel artikel.
     */
    public function build(ContentStudioArticle $article, ?string $locale = null): SeoMetadata
    {
        // 1. De Engine levert eigen SEO- en OG-titels aan; die gaan voor op de
        //    artikeltitel. Ontbreken ze, dan valt alles terug op de titel en de
        //    meta description, zoals voorheen.
        $title = $this->firstFilled($article->seo_title, $article->title) ?? '';
        $description = $article->meta_description ?? '';

        $ogTitle = $this->firstFilled($article->og_title, $title);
        $ogDescription = $this->firstFilled($article->og_description, $description);

        // 2. Bouw de automatische Canonical URL
        $canonical = $this->articleUrl($article->slug, $locale);

        // 4. Indexering instellingen (standaard op index, follow)
        $index = 'index';
        $follow = 'follow';
        $robots = "{$index},{$follow}";

        // 5. Alternates: de vertalingen van dit artikel, zodat een
        //    anderstalige bezoeker bij zijn eigen versie uitkomt.
        $alternates = $this->articleAlternates($article, $locale);

        $image_url = $article->featured_image_url ?? null;
        // MAKE ABSOLUTE URL IF NOT ALREADY. IT IS LOCATED IN PUBLIC STORAGE.
        if ($image_url && ! str_starts_with($image_url, 'http')) {
            $image_url = asset("storage/{$image_url}");
        }

        return new SeoMetadata(
            title: $title,
            description: $description,
            canonical: $canonical,
            alternates: $alternates,
            openGraph: [
                'title' => $ogTitle,
                'description' => $ogDescription,
                'url' => $canonical,
                'type' => 'article',
                'article:published_time' => $article->created_at?->toIso8601String(),
                'article:author' => 'Shazzoo Engineering Team', // Harde referentie naar auteur
                'image' => $image_url, // Voeg eventueel een afbeelding toe
            ],
            jsonLd: [
                '@context' => 'https://schema.org',
                '@type' => 'BlogPosting',
                'headline' => $article->title ?? $title,
                'description' => $description,
                'url' => $canonical,
                'datePublished' => $article->created_at?->toIso8601String(),
                'dateModified' => $article->updated_at?->toIso8601String() ?? $article->created_at?->toIso8601String(),
                'author' => [
                    '@type' => 'Organization',
                    'name' => 'Shazzoo',
                    'url' => 'https://shazzoo.nl',
                ],
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => 'Shazzoo',
                    'logo' => [
                        '@type' => 'ImageObject',
                        'url' => 'https://shazzoo.nl/themes/shazzoo-corporate-theme/images/shazzooVert.svg',
                    ],
                ],
                'mainEntityOfPage' => [
                    '@type' => 'WebPage',
                    '@id' => $canonical,
                ],
                'image' => $image_url ? [
                    '@type' => 'ImageObject',
                    'url' => $image_url,
                ] : null,
            ],
            robots: $robots,
        );
    }

    /**
     * De vertalingen van dit artikel.
     *
     * De Engine zet vertalingen van hetzelfde artikel onder één cluster_key;
     * dat is hier de enige betrouwbare band tussen twee talen, want de slugs
     * verschillen per taal.
     *
     * @return array<int, array{locale: string, url: string}>
     */
    private function articleAlternates(ContentStudioArticle $article, ?string $locale): array
    {
        if (! ArticleRoutes::usesLocalizedRoutes()) {
            /* Eentalige site: elke taal zou op dezelfde url uitkomen, en een
               hreflang-set die naar zichzelf wijst zegt niets. */
            return [];
        }

        $own = $locale ?: (string) $article->locale;

        $candidates = [[
            'locale' => $own,
            'url' => $this->articleUrl((string) $article->slug, $own),
        ]];

        if (filled($article->cluster_key)) {
            $translations = ContentStudioArticle::query()
                ->where('cluster_key', $article->cluster_key)
                ->whereKeyNot($article->getKey())
                ->whereNotNull('locale')
                ->where('locale', '!=', '')
                ->whereNotNull('slug')
                ->where('slug', '!=', '')
                ->get(['locale', 'slug']);

            foreach ($translations as $translation) {
                $candidates[] = [
                    'locale' => (string) $translation->locale,
                    'url' => $this->articleUrl((string) $translation->slug, (string) $translation->locale),
                ];
            }
        }

        return HreflangAlternates::build($candidates, ArticleRoutes::defaultLocale(), ArticleRoutes::publishedLocales());
    }

    /**
     * Voor de overzichtspagina: elke taal waarin hier artikelen staan.
     *
     * @return array<int, array{locale: string, url: string}>
     */
    private function indexAlternates(?string $locale): array
    {
        if (! ArticleRoutes::usesLocalizedRoutes()) {
            return [];
        }

        $locales = ContentStudioArticle::query()
            ->whereNotNull('locale')
            ->where('locale', '!=', '')
            ->distinct()
            ->pluck('locale')
            ->all();

        /* De taal van de pagina zelf voorop, zodat x-default en de volgorde
           hetzelfde uitpakken als bij een artikel. */
        $ordered = array_values(array_unique(array_filter([$locale, ...$locales])));

        $candidates = array_map(fn (string $loc): array => [
            'locale' => $loc,
            'url' => $this->articleIndexUrl($loc),
        ], $ordered);

        return HreflangAlternates::build($candidates, ArticleRoutes::defaultLocale(), ArticleRoutes::publishedLocales());
    }

    /**
     * De eerste waarde die daadwerkelijk gevuld is; lege strings tellen niet mee.
     */
    private function firstFilled(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    private function articleIndexUrl(?string $locale = null, array $parameters = []): string
    {
        $path = ArticleRoutes::indexPath($locale);

        if ($parameters !== []) {
            return url($path).'?'.http_build_query($parameters);
        }

        return url($path);
    }

    private function articleUrl(string $slug, ?string $locale = null): string
    {
        return ArticleRoutes::articleUrl($slug, $locale);
    }
}
