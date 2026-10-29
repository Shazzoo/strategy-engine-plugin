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
        $title = __('strategy-engine::content_studio.index_title')." - {$appName}";

        $currentPage = request()->get('page', 1);
        $canonical = $this->articleIndexUrl($locale);
        $alternates = $this->indexAlternates($locale);

        if ($currentPage > 1) {
            $title .= ' - '.__('strategy-engine::content_studio.index_page', ['page' => $currentPage]);
            $canonical = $this->articleIndexUrl($locale, ['page' => $currentPage]);

            /* Vanaf pagina 2 geen hreflang. Een andere taal heeft een andere
               hoeveelheid artikelen, dus pagina 2 daar toont iets anders -
               en bestaat soms niet eens. Naar de eerste pagina wijzen is net
               zo fout: dat is niet dezelfde pagina. */
            $alternates = [];
        }

        $description = (string) (config('content-studio.seo.index_description')
            ?: __('strategy-engine::content_studio.blog_description'));

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
                'image' => config('content-studio.seo.index_image') ?: $this->defaultImage(),
            ],
            jsonLd: [
                '@context' => 'https://schema.org',
                '@type' => 'Blog',
                'name' => $title,
                'description' => $description,
                'url' => $canonical,
                'inLanguage' => $locale ?: app()->getLocale(),
                'publisher' => self::publisherNode((string) $appName, config('content-studio.seo.publisher_logo')),
                'mainEntity' => [
                    '@type' => 'ItemList',
                    'itemListElement' => $itemList,
                ],
            ],
            robots: 'index,follow',
        );
    }

    /**
     * De standaard Open Graph-afbeelding uit de globale instellingen, voor een
     * overzicht of artikel zonder eigen afbeelding.
     */
    private function defaultImage(): ?string
    {
        return media_url(setting('seo_default_og_image_id'));
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

        $image_url = $image_url ?: $this->defaultImage();

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
                'article:published_time' => $this->publishedAt($article)?->toIso8601String(),
                'article:author' => filled($article->author_name) ? $article->author_name : config('app.name'),
                'image' => $image_url, // Voeg eventueel een afbeelding toe
            ],
            jsonLd: [
                '@context' => 'https://schema.org',
                '@type' => 'BlogPosting',
                'headline' => $article->title ?? $title,
                'description' => $description,
                'url' => $canonical,
                'inLanguage' => $locale ?: $article->locale,
                'datePublished' => $this->publishedAt($article)?->toIso8601String(),
                'dateModified' => ($article->updated_at ?? $this->publishedAt($article))?->toIso8601String(),
                'author' => self::authorNode($article->author_name, $article->author_role_title, (string) config('app.name')),
                'publisher' => self::publisherNode((string) config('app.name'), config('content-studio.seo.publisher_logo')),
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
     * Wie het artikel geschreven heeft, als schema.org-knoop.
     *
     * De Engine levert een naam en vaak een functie; die horen als Person
     * in het schema, want dat is het E-E-A-T-signaal waar zoekmachines en
     * AI-assistenten op letten. Levert hij niets, dan valt het terug op de
     * organisatie - en dat is de naam van déze site, niet die van ons.
     *
     * @return array<string, mixed>
     */
    public static function authorNode(?string $name, ?string $role, string $organisation): array
    {
        $name = trim((string) $name);

        if ($name === '') {
            return ['@type' => 'Organization', 'name' => $organisation];
        }

        $role = trim((string) $role);

        return array_filter([
            '@type' => 'Person',
            'name' => $name,
            'jobTitle' => $role !== '' ? $role : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * De uitgever: deze site. Een logo alleen als we er een weten - een
     * verkeerd logo in het schema is erger dan geen logo.
     *
     * @return array<string, mixed>
     */
    public static function publisherNode(string $organisation, ?string $logo): array
    {
        $logo = trim((string) $logo);

        return array_filter([
            '@type' => 'Organization',
            'name' => $organisation,
            'logo' => $logo !== '' ? ['@type' => 'ImageObject', 'url' => $logo] : null,
        ], fn ($value) => $value !== null);
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

    /**
     * Wanneer het artikel is uitgekomen. generated_at komt van de Engine;
     * created_at is het moment waarop de rij hier is aangemaakt, en dat is
     * de dag van de sync - niet van het artikel.
     */
    private function publishedAt(ContentStudioArticle $article)
    {
        return $article->generated_at ?: $article->created_at;
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
