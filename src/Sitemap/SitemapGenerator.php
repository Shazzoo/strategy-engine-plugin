<?php

namespace Shazzoo\StrategyEngine\Sitemap;

use Illuminate\Support\Collection;
use Shazzoo\StrategyEngine\Models\ContentStudioArticle;
use Shazzoo\StrategyEngine\Models\ContentStudioSetting;
use Shazzoo\StrategyEngine\Support\ArticleRoutes;
use Shazzoo\StrategyEngine\Support\Seo\HreflangAlternates;
use Shazzoo\ContentStudioCore\Support\Sitemap\Contracts\SitemapGeneratorInterface;
use Shazzoo\ContentStudioCore\Support\Sitemap\SitemapBuilder;

class SitemapGenerator implements SitemapGeneratorInterface
{
    public function generate(SitemapBuilder $sitemap): void
    {
        $setting = ContentStudioSetting::singleton();
        $prefix = trim((string) ($setting->route_prefix ?: 'blog'), '/');
        $locales = ContentStudioArticle::query()
            ->whereNotNull('locale')
            ->where('locale', '!=', '')
            ->distinct()
            ->pluck('locale')
            ->all();

        if ($locales === []) {
            $locales = [app()->getLocale()];
        }

        $multilang = ArticleRoutes::usesLocalizedRoutes();
        $defaultLocale = ArticleRoutes::defaultLocale();
        $clusters = $multilang ? $this->clusters() : collect();

        foreach ($locales as $locale) {
            // Add the article overview page for each available locale.
            $sitemap->addUrl(
                index: 'articles',
                url: ArticleRoutes::indexUrl($locale, $prefix),
                lastmod: now(),
                alternates: $multilang
                    ? $this->indexAlternates($locale, $locales, $prefix, $defaultLocale)
                    : [],
            );

            foreach (ContentStudioArticle::query()
                ->where('locale', $locale)
                ->whereNotNull('slug')
                ->where('slug', '!=', '')
                ->orderBy('updated_at', 'desc')
                ->cursor() as $article) {
                $sitemap->addUrl(
                    index: 'articles',
                    url: ArticleRoutes::articleUrl($article->slug, $locale, $prefix),
                    lastmod: $article->updated_at,
                    alternates: $multilang
                        ? $this->articleAlternates($article, $locale, $clusters, $prefix, $defaultLocale)
                        : [],
                );
            }
        }
    }

    /**
     * Alle vertalingen in één keer, op cluster gegroepeerd.
     *
     * De sitemap loopt elk artikel langs; per artikel apart de vertalingen
     * opvragen zou een query per artikel kosten, en dit bestand wordt voor
     * de hele site in één keer opgebouwd.
     *
     * @return Collection<string, Collection<int, ContentStudioArticle>>
     */
    private function clusters()
    {
        return ContentStudioArticle::query()
            ->whereNotNull('cluster_key')
            ->where('cluster_key', '!=', '')
            ->whereNotNull('locale')
            ->where('locale', '!=', '')
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->get(['id', 'cluster_key', 'locale', 'slug'])
            ->groupBy('cluster_key');
    }

    /**
     * @return array<int, array{locale: string, url: string}>
     */
    private function articleAlternates(ContentStudioArticle $article, string $locale, $clusters, string $prefix, ?string $defaultLocale): array
    {
        if (blank($article->cluster_key)) {
            return [];
        }

        $candidates = [[
            'locale' => $locale,
            'url' => ArticleRoutes::articleUrl((string) $article->slug, $locale, $prefix),
        ]];

        foreach ($clusters->get((string) $article->cluster_key, collect()) as $translation) {
            $candidates[] = [
                'locale' => (string) $translation->locale,
                'url' => ArticleRoutes::articleUrl((string) $translation->slug, (string) $translation->locale, $prefix),
            ];
        }

        return HreflangAlternates::build($candidates, $defaultLocale);
    }

    /**
     * @param  array<int, string>  $locales
     * @return array<int, array{locale: string, url: string}>
     */
    private function indexAlternates(string $locale, array $locales, string $prefix, ?string $defaultLocale): array
    {
        $ordered = array_values(array_unique(array_filter([$locale, ...$locales])));

        $candidates = array_map(fn (string $loc): array => [
            'locale' => $loc,
            'url' => ArticleRoutes::indexUrl($loc, $prefix),
        ], $ordered);

        return HreflangAlternates::build($candidates, $defaultLocale);
    }
}
