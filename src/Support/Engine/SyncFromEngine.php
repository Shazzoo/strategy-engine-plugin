<?php

namespace Shazzoo\StrategyEngine\Support\Engine;

use Illuminate\Support\Facades\Log;
use Shazzoo\StrategyEngine\Http\Controllers\SyncArticles;
use Shazzoo\StrategyEngine\Support\ArticleRoutes;

/**
 * De V2-kant: de engine zegt dat er iets klaarstaat, deze site haalt het
 * op en zet het neer.
 *
 * Eén artikel tegelijk als er gebeld wordt, en een hele inhaalronde als de
 * site zelf wil weten wat hij gemist heeft. Allebei door dezelfde
 * toewijzing als de oude ophaalronde (SyncArticles::processArticle), zodat
 * er maar één plek is waar een veld van de engine een veld hier wordt.
 *
 * Dubbele artikelen kunnen niet ontstaan: processArticle() zoekt op het
 * engine-id en werkt bij wat er al staat.
 */
final class SyncFromEngine
{
    public function __construct(
        private readonly EngineClient $engine,
        private readonly SyncArticles $articles,
    ) {}

    public static function make(): self
    {
        return new self(EngineClient::fromSettings(), app(SyncArticles::class));
    }

    /**
     * Eén aangekondigd artikel. Geeft terug of het gelukt is; wat er ook
     * gebeurt, de engine hoort het - ook een mislukking, want anders blijft
     * daar staan dat het is afgeleverd.
     */
    public function one(int $articleId, string $action = 'published'): bool
    {
        $item = $this->engine->article($articleId);

        if (! $item) {
            /* Hier niet melden als fout: de engine kan het artikel met
               opzet nog niet geven - het publicatiemoment kan net nog niet
               bereikt zijn - en dan is er niets mis. */
            Log::info('[StrategyEngine] Engine gaf dit artikel (nog) niet.', ['article_id' => $articleId]);

            return false;
        }

        if (! ArticleRoutes::publishesLocale($item['locale'] ?? null)) {
            /* Geen fout: de Engine schrijft in de talen van het project en
               deze site toont er minder. Overgeslagen is iets anders dan
               mislukt, en de Engine hoort het verschil te weten. */
            $this->engine->acknowledge($articleId, 'skipped');

            return false;
        }

        $article = $this->articles->processArticle($item);

        if (! $article) {
            $this->engine->acknowledge($articleId, 'failed', error: 'De site kon het artikel niet verwerken.');

            return false;
        }

        $this->engine->acknowledge(
            $articleId,
            $action,
            externalRef: (string) $article->id,
            url: $this->urlFor($article),
        );

        return true;
    }

    /**
     * Alles wat deze site nog niet had, vanaf zijn eigen cursor. Dit is de
     * dagelijkse vangnetronde: gemiste aankondigingen en gemiste
     * wijzigingen komen hier in dezelfde lijst voorbij.
     */
    public function catchUp(int $maxPages = 20): int
    {
        $cursor = $this->engine->cursor();
        $handled = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            $changes = $this->engine->changes($cursor);

            if ($changes === null && $page === 0 && $this->engine->lastStatus() === 403) {
                /* De engine kent deze site niet meer: de koppeling is daar
                   verbroken. Opnieuw aanmelden en één keer opnieuw vragen,
                   anders zou deze site met een geheim in de hand blijven
                   wachten op gebeld worden dat nooit meer komt.

                   Lukt het aanmelden niet - het project hangt inmiddels aan
                   een andere website - dan blijft het oude geheim staan en
                   zegt het log waarom. */
                if ($this->engine->register($this->webhookUrl())) {
                    $changes = $this->engine->changes($cursor);
                }
            }

            if (! $changes || $changes['data'] === []) {
                break;
            }

            foreach ($changes['data'] as $item) {
                if (! ArticleRoutes::publishesLocale($item['locale'] ?? null)) {
                    /* Terugmelden, anders komt hij elke ronde opnieuw
                       voorbij als iets wat nog afgehandeld moet worden. */
                    $this->engine->acknowledge((int) $item['id'], 'skipped');

                    continue;
                }

                $article = $this->articles->processArticle($item);

                if ($article) {
                    $handled++;

                    /* Nieuw hier, of een bestaand artikel bijgewerkt -
                       de engine hoort het verschil, want alleen bij een
                       eerste publicatie gebeurt daar nog iets mee. */
                    $this->engine->acknowledge(
                        (int) $item['id'],
                        $article->wasRecentlyCreated ? 'published' : 'updated',
                        externalRef: (string) $article->id,
                        url: $this->urlFor($article),
                    );
                }
            }

            $cursor = $changes['next_cursor'];

            /* Na elke pagina bewaard, niet pas aan het eind: breekt de rit
               af, dan begint de volgende waar deze ophield. */
            $this->engine->rememberCursor($cursor);

            if (! $changes['has_more']) {
                break;
            }
        }

        return $handled;
    }

    /** Het adres waarop de engine deze site mag bellen. */
    public function webhookUrl(): string
    {
        return route('content-studio.webhook');
    }

    private function urlFor($article): ?string
    {
        try {
            return ArticleRoutes::articleUrl((string) $article->slug, (string) $article->locale);
        } catch (\Throwable) {
            return null;
        }
    }
}
