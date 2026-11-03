<?php

namespace Shazzoo\StrategyEngine\Support\Publication;

use Shazzoo\StrategyEngine\Events\ArticlePageChanged;
use Shazzoo\StrategyEngine\Models\ContentStudioArticle;

/**
 * Leegt wat een oude versie van één artikelpagina kan vasthouden.
 *
 * Core rendert artikelpagina's bij elk verzoek; een paginacache zit er dus
 * alleen als een site die zelf toevoegt. Wat we kennen, legen we hier
 * gericht (spatie/laravel-responsecache). Voor de rest - een CDN, een
 * reverse proxy - is er het event ArticlePageChanged, waar een site zijn
 * eigen purge aan hangt. De verifier gebruikt daarnaast een cachebuster, zodat
 * hij ook zonder purge ziet wat er echt staat.
 */
final class CachePurger
{
    public static function purge(ContentStudioArticle $article): void
    {
        $url = Publication::url($article);

        if ($url === '') {
            return;
        }

        $responseCache = 'Spatie\\ResponseCache\\Facades\\ResponseCache';

        if (class_exists($responseCache)) {
            try {
                $responseCache::forget($url);
            } catch (\Throwable) {
                // Een cache die niet wil, is geen reden om niet te verifiëren.
            }
        }

        event(new ArticlePageChanged($article, $url));
    }
}
