<?php

namespace Shazzoo\StrategyEngine\Events;

use Shazzoo\StrategyEngine\Models\ContentStudioArticle;

/**
 * De publieke pagina van dit artikel is veranderd, of moet opnieuw bekeken
 * worden. Hang hier de purge van een CDN of reverse proxy aan:
 *
 *     Event::listen(ArticlePageChanged::class, fn ($e) => Cdn::purge($e->url));
 */
final class ArticlePageChanged
{
    public function __construct(
        public readonly ContentStudioArticle $article,
        public readonly string $url,
    ) {}
}
