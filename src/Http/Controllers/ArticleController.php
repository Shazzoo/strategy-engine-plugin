<?php

namespace Shazzoo\StrategyEngine\Http\Controllers;

use Shazzoo\StrategyEngine\Models\ContentStudioArticle;
use Shazzoo\StrategyEngine\Models\ContentStudioSetting;
use Shazzoo\StrategyEngine\Support\ArticleRoutes;
use Shazzoo\StrategyEngine\Support\Engine\ProjectInfo;
use Shazzoo\StrategyEngine\Support\Seo\ArticleSeoBuilder;
use Shazzoo\ContentStudioCore\Models\Setting;
use Shazzoo\ContentStudioCore\Support\Seo\SeoApplier;
use Shazzoo\ContentStudioCore\Support\Theming\TemplateRegistry;
use Shazzoo\ContentStudioCore\Support\Theming\ThemeManager;

class ArticleController
{
    private function resolveContext($locale = null): array
    {
        $resolvedLocale = $locale ?: (ProjectInfo::primaryLocale() ?: app()->getLocale());
        app()->setLocale($resolvedLocale);

        $setting = ContentStudioSetting::singleton();
        $prefix = ArticleRoutes::prefix(trim((string) ($setting->route_prefix ?: 'blog'), '/'), $resolvedLocale);
        $perPage = max(1, min(48, (int) ($setting->articles_per_page ?: 12)));

        $active = app(ThemeManager::class)->active();
        $ns = $active['slug'] ?? null;
        $layout = $ns ? "{$ns}::layouts.app" : 'layouts.guest';

        // Site-instellingen; themecomponenten zoals de header verwachten deze.
        $settings = class_exists(Setting::class) ? (Setting::first()?->settings ?? []) : [];

        return [
            'locale' => $resolvedLocale,
            'setting' => $setting,
            'prefix' => $prefix,
            'perPage' => $perPage,
            'ns' => $ns,
            'layout' => $layout,
            'settings' => $settings,
        ];
    }

    public function handle(?string $locale = null, ?string $slug = null)
    {
        $context = $this->resolveContext($locale);
        $prefix = $context['prefix'];

        $segments = explode('/', trim((string) $slug, '/'));
        if (($segments[0] ?? null) === $prefix || in_array($segments[0] ?? null, ArticleRoutes::prefixes(), true)) {
            array_shift($segments);
        }

        if ($segments === []) {
            return $this->index($locale);
        }

        return $this->show($locale, implode('/', $segments));
    }

    public function index($locale = null)
    {
        $context = $this->resolveContext($locale);
        $setting = $context['setting'];
        $prefix = $context['prefix'];
        $perPage = $context['perPage'];
        $ns = $context['ns'];
        $layout = $context['layout'];
        $settings = $context['settings'];

        $viewCandidates = $this->viewCandidates(
            $setting->index_template_key,
            $ns,
            'articles-index',
            'strategy-engine::articles.index',
        );

        $articles = ContentStudioArticle::query()
            ->where('locale', $context['locale'])
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->orderBy('generated_at', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        $seo = app(ArticleSeoBuilder::class)->indexBuild(
            articles: $articles,
            locale: $context['locale'],
        );

        app(SeoApplier::class)->apply($seo);

        $pageBlocks = $this->pageBlocksForTemplate($setting->index_template_key, 'index', [
            'title' => 'Artikelen',
            'show_excerpt' => true,
        ]);

        return view()->first($viewCandidates, [
            'setting' => $setting,
            'layout' => $layout,
            'themeNamespace' => $ns,
            'settings' => $settings,
            'page' => $this->virtualPage(
                title: 'Artikelen',
                slug: $prefix,
                locale: $context['locale'],
                templateKey: $setting->index_template_key,
                templateSettings: $setting->index_template_settings ?? [],
                content: $pageBlocks,
            ),
            'pageBlocks' => $pageBlocks,
            'template' => $setting->index_template_key,
            'contentStudioContentView' => $this->contentViewForTemplate($setting->index_template_key, 'index'),
            'contentStudioContentData' => [
                'title' => 'Artikelen',
                'articles' => $articles,
                'showExcerpt' => true,
                'blogBasePath' => ArticleRoutes::indexPath($context['locale'], $prefix),
            ],
            'articles' => $articles,
            'blogBasePath' => ArticleRoutes::indexPath($context['locale'], $prefix),
            'seo_data' => [
                'title' => 'Artikelen',
                'description' => 'Bekijk onze nieuwste artikelen.',
                'url' => ArticleRoutes::indexUrl($context['locale'], $prefix),
                'image' => null, // TODO: add image if available
            ],
        ]);
    }

    public function show($locale = null, $slug = null)
    {
        $context = $this->resolveContext($locale);
        $setting = $context['setting'];
        $prefix = $context['prefix'];
        $ns = $context['ns'];
        $layout = $context['layout'];
        $settings = $context['settings'];

        $viewCandidates = $this->viewCandidates(
            $setting->article_template_key,
            $ns,
            'articles-show',
            'strategy-engine::articles.show',
        );

        $article = ContentStudioArticle::where('locale', $context['locale'])
            ->where('slug', $slug)
            ->with(['related_hub_articles'])
            ->firstOrFail();

        if (! $article) {
            abort(404);
        }

        $pageBlocks = $this->pageBlocksForTemplate($setting->article_template_key, 'show', [
            'article_id' => $article->id,
        ]);

        $seo = app(ArticleSeoBuilder::class)->build(
            article: $article,
            locale: $context['locale'],
        );

        app(SeoApplier::class)->apply($seo);

        return view()->first($viewCandidates, [
            'setting' => $setting,
            'slug' => $slug,
            'id' => $article->id,
            'layout' => $layout,
            'themeNamespace' => $ns,
            'settings' => $settings,
            'page' => $this->virtualPage(
                title: $article->title,
                slug: $slug,
                locale: $context['locale'],
                templateKey: $setting->article_template_key,
                templateSettings: $setting->article_template_settings ?? [],
                content: $pageBlocks,
            ),
            'pageBlocks' => $pageBlocks,
            'template' => $setting->article_template_key,
            'contentStudioContentView' => $this->contentViewForTemplate($setting->article_template_key, 'show'),
            'contentStudioContentData' => [
                'article' => $article,
                'setting' => $setting,
            ],
            'article' => $article,
            'blogBasePath' => ArticleRoutes::indexPath($context['locale'], $prefix),
            'seo_data' => [
                'title' => $article->title,
                'description' => $article->meta_description,
                'url' => ArticleRoutes::articleUrl($slug, $context['locale'], $prefix),
                'image' => $article->featured_image_url,
            ],
        ]);
    }

    /**
     * Views die dit verzoek mogen renderen, op volgorde van voorkeur.
     *
     * Een gekozen template komt vooraan; daarna het vaste themetemplate en als
     * laatste de view van de plugin zelf. Zonder gekozen template is de lijst
     * identiek aan het gedrag van voor de template-keuze, dus een lege
     * template_key verandert niets.
     */
    private function viewCandidates(?string $templateKey, ?string $themeNamespace, string $defaultTemplate, string $fallback): array
    {
        $candidates = [];
        $templateKey = trim((string) $templateKey);

        if ($templateKey !== '') {
            $template = app(TemplateRegistry::class)->all()[$templateKey] ?? null;
            $view = is_array($template) ? ($template['view'] ?? null) : null;

            if (is_string($view) && $view !== '') {
                if (str_contains($view, '::')) {
                    $candidates[] = $view;
                } elseif ($themeNamespace) {
                    $candidates[] = "{$themeNamespace}::{$view}";
                }
            }

            if ($themeNamespace) {
                $candidates[] = "{$themeNamespace}::templates.{$templateKey}";
            }
        }

        if ($themeNamespace) {
            $candidates[] = "{$themeNamespace}::templates.{$defaultTemplate}";
        }

        $candidates[] = $fallback;

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Een paginablok voor themetemplates die generieke pagina's renderen en dus
     * $page->content aflopen. De artikel-specifieke templates renderen de
     * artikelen zelf en krijgen daarom een lege lijst.
     */
    private function pageBlocksForTemplate(?string $templateKey, string $pageType, array $data): array
    {
        if (in_array($templateKey, [null, '', 'articles-index', 'articles-show'], true)) {
            return [];
        }

        return [[
            'type' => $pageType === 'show'
                ? 'strategy-engine.article-body'
                : 'strategy-engine.article-overview',
            'data' => $data,
        ]];
    }

    private function contentViewForTemplate(?string $templateKey, string $pageType): ?string
    {
        if (in_array($templateKey, [null, '', 'articles-index', 'articles-show'], true)) {
            return null;
        }

        return $pageType === 'show'
            ? 'strategy-engine::components.blocks.article-body'
            : 'strategy-engine::components.blocks.article-overview';
    }

    /**
     * Artikelpagina's bestaan niet als CMS-pagina, maar themetemplates lezen
     * hun configuratie uit $page->template_settings. Dit object levert die
     * vorm, zodat een gewoon paginatemplate een artikeloverzicht kan renderen.
     */
    private function virtualPage(string $title, string $slug, string $locale, ?string $templateKey, array $templateSettings, array $content = []): object
    {
        return (object) [
            'title' => $title,
            'slug' => $slug,
            'locale' => $locale,
            'content' => $content,
            'seo_description' => null,
            'template_key' => $templateKey,
            'template_settings' => $templateSettings,
        ];
    }
}
