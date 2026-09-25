<?php

namespace Shazzoo\StrategyEngine;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Shazzoo\StrategyEngine\Console\Commands\ContentStudioArticleSync;
use Shazzoo\StrategyEngine\Http\Controllers\ArticleController;
use Shazzoo\StrategyEngine\Http\Controllers\TrackingScriptController;
use Shazzoo\StrategyEngine\Models\ContentStudioSetting;
use Shazzoo\StrategyEngine\Sitemap\SitemapGenerator;
use Shazzoo\StrategyEngine\Views\Components\Blocks\ContentStudioArticles;
use Shazzoo\ContentStudioCore\Support\Blocks\BlockDefinitionNamespaceRegistry;
use Shazzoo\ContentStudioCore\Support\Routing\PluginRouteRegistry;
use Shazzoo\ContentStudioCore\Support\Sitemap\SitemapRegistry;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        require_once __DIR__.'/Support/helpers.php';
        $this->mergeConfigFrom(dirname(__DIR__).'/config/content-studio.php', 'content-studio');
    }

    public function boot(SitemapRegistry $registry, PluginRouteRegistry $pluginRouteRegistry): void
    {
        $base = dirname(__DIR__);

        $routePrefix = 'blog';
        if (Schema::hasTable('content_studio_settings')) {
            $settings = ContentStudioSetting::query()->first();
            $configuredPrefix = trim((string) ($settings?->route_prefix ?? 'blog'), '/');
            if ($configuredPrefix !== '') {
                $routePrefix = $configuredPrefix;
            }
        }

        $pluginRouteRegistry->register($routePrefix, ArticleController::class);

        Route::middleware('web')
            // Let op: het pad moet onder een prefix vallen die de catch-all
            // frontend-route van content-studio-core uitsluit ("js/"), anders
            // slokt die route dit verzoek op en volgt er een 404.
            ->get('/js/strategy-engine/tracking.js', TrackingScriptController::class)
            ->name('content-studio.tracking-script');

        Route::middleware('web')->group(function () use ($routePrefix) {
            $this->registerArticleRoutes($routePrefix, 'blog');

            if ($routePrefix !== 'blog') {
                $this->registerArticleRoutes('blog', 'blog.alias');
            }
        });

        // migrations
        $this->loadMigrationsFrom($base.'/database/migrations');

        // views
        $this->loadViewsFrom($base.'/resources/views', 'strategy-engine');

        // Core looks up a plugin's block views under the last part of its key
        // (shazzoo/strategy-engine-plugin), not under its slug. Without this,
        // themes that find block views by type never see the article block.
        $this->loadViewsFrom($base.'/resources/views', 'strategy-engine-plugin');

        // TRANSLATIONS.
        $this->loadTranslationsFrom($base.'/resources/lang', 'strategy-engine');

        // OVERRIDES.
        // Elke site heeft zijn eigen vormgeving. Publiceer de views die je wilt
        // herstylen en pas ze aan in resources/views/vendor/strategy-engine;
        // views die je niet publiceert blijven uit de package komen, zodat een
        // update van de plugin gewoon doorwerkt.
        $this->publishes([
            $base.'/resources/views' => resource_path('views/vendor/strategy-engine'),
        ], 'content-studio-views');

        $this->publishes([
            $base.'/resources/views/components' => resource_path('views/vendor/strategy-engine/components'),
        ], 'content-studio-components');

        $this->publishes([
            $base.'/resources/lang' => lang_path('vendor/strategy-engine'),
        ], 'content-studio-lang');

        $this->publishes([
            $base.'/config/content-studio.php' => config_path('content-studio.php'),
        ], 'content-studio-config');

        // SITEMAP.
        $registry->register(SitemapGenerator::class);

        $this->commands([
            ContentStudioArticleSync::class,
        ]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('content-studio:articles')
                ->everyFifteenMinutes()
                ->withoutOverlapping(); // alleen nuttig als je meerdere servers hebt
        });

        // COMPONENT NAMESPACE.
        Blade::componentNamespace(
            'Shazzoo\\StrategyEngine\\Views\\Components',
            'strategy-engine'
        );

        // Legacy alias: older saved blocks used type "content-studio-articles"
        // which resolves to "blocks.content-studio-articles" in the renderer.
        Blade::component('blocks.content-studio-articles', ContentStudioArticles::class);

        app(BlockDefinitionNamespaceRegistry::class)->addMany([
            'Shazzoo\\StrategyEngine\\Forms\\Blocks',
        ]);
    }

    private function registerArticleRoutes(string $routePrefix, string $namePrefix): void
    {
        Route::get('/'.$routePrefix, [ArticleController::class, 'index'])->name($namePrefix.'.index');
        Route::get('/'.$routePrefix.'/{slug}', function (string $slug) {
            return app(ArticleController::class)->show(null, $slug);
        })->name($namePrefix.'.show');

        if (function_exists('cms_is_multilang') && cms_is_multilang()) {
            Route::get('/{locale}/'.$routePrefix, [ArticleController::class, 'index'])
                ->where('locale', '[a-zA-Z]{2}(?:-[a-zA-Z]{2})?')
                ->name('locale.'.$namePrefix.'.index');
            Route::get('/{locale}/'.$routePrefix.'/{slug}', [ArticleController::class, 'show'])
                ->where('locale', '[a-zA-Z]{2}(?:-[a-zA-Z]{2})?')
                ->name('locale.'.$namePrefix.'.show');
        }
    }
}
