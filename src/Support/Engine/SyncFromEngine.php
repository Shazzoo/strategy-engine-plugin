<?php

namespace Shazzoo\StrategyEngine\Support\Engine;

use Illuminate\Support\Facades\Log;
use Shazzoo\StrategyEngine\Http\Controllers\SyncArticles;
use Shazzoo\StrategyEngine\Models\ContentStudioArticle;
use Shazzoo\StrategyEngine\Support\ArticleRoutes;
use Shazzoo\StrategyEngine\Support\Publication\Publication;

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
    /**
     * Verifiëren en terugmelden na het antwoord aan de engine, in plaats van
     * ervoor. De engine wacht maar kort op een webhook; de pagina ophalen,
     * met retries tegen een cache, kan langer duren.
     */
    private bool $defer = false;

    /** Na zoveel mislukte pogingen geeft de site een artikel op. */
    public const MAX_RETRIES = 10;

    public function __construct(
        private readonly EngineClient $engine,
        private readonly SyncArticles $articles,
    ) {}

    /**
     * Voor de webhook: stap 6 tot 9 (cache, ophalen, verifiëren, melden)
     * gebeuren nadat het antwoord de deur uit is. Zonder queue-worker: in
     * hetzelfde proces, via app()->terminating().
     */
    public function deferred(): self
    {
        $this->defer = true;

        return $this;
    }

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
               bereikt zijn - en dan is er niets mis. Komt het later wel,
               dan meldt de engine het opnieuw; hier hoeft niets te blijven
               wachten. */
            Log::info('[StrategyEngine] Engine gaf dit artikel (nog) niet.', ['article_id' => $articleId]);
            $this->engine->forgetFailure($articleId);

            return false;
        }

        $item['id'] ??= $articleId;

        return $this->handle($item, $action);
    }

    /**
     * Eén artikel uit de engine verwerken. Lukt het opslaan niet, dan komt
     * het op de lijst om het later opnieuw te proberen: de cursor schuift
     * hoe dan ook door, en zonder die lijst kwam het nooit meer langs.
     */
    private function handle(array $item, string $action): bool
    {
        $articleId = (int) $item['id'];

        if (! ArticleRoutes::publishesLocale($item['locale'] ?? null)) {
            /* Geen fout: de Engine schrijft in de talen van het project en
               deze site toont er minder. Overgeslagen is iets anders dan
               mislukt, en de Engine hoort het verschil te weten. */
            $this->engine->acknowledge($articleId, 'skipped');
            $this->engine->forgetFailure($articleId);

            return false;
        }

        $article = $this->articles->processArticle($item);

        if (! $article) {
            $attempts = $this->engine->rememberFailure($articleId);

            if ($attempts >= self::MAX_RETRIES) {
                $this->engine->forgetFailure($articleId);
                Log::error('[StrategyEngine] Artikel na '.self::MAX_RETRIES.' pogingen opgegeven; haal het op met content-studio:provenance republish engine:'.$articleId.'.', [
                    'article_id' => $articleId,
                ]);
            }

            $this->engine->acknowledge($articleId, 'failed', error: 'De site kon het artikel niet verwerken.');

            return false;
        }

        $this->engine->forgetFailure($articleId);

        return $this->finish($article, $articleId, $action);
    }

    /**
     * Wat eerder niet opgeslagen kon worden, nog eens. Per artikel opnieuw
     * opgehaald: de engine kan het inmiddels gewijzigd of ingetrokken hebben.
     *
     * @return int Hoeveel er nu wel lukten.
     */
    public function retryFailed(): int
    {
        $handled = 0;

        foreach (array_keys($this->engine->retries()) as $articleId) {
            $known = ContentStudioArticle::query()->where('content_studio_article_id', $articleId)->exists();

            if ($this->one($articleId, $known ? 'updated' : 'published')) {
                $handled++;
            }
        }

        return $handled;
    }

    /**
     * Alles wat deze site nog niet had, vanaf zijn eigen cursor. Dit is de
     * dagelijkse vangnetronde: gemiste aankondigingen en gemiste
     * wijzigingen komen hier in dezelfde lijst voorbij.
     */
    public function catchUp(int $maxPages = 20): int
    {
        $cursor = $this->engine->cursor();
        $handled = $this->retryFailed();

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
                if (empty($item['id'])) {
                    continue;
                }

                /* Nieuw hier, of een bestaand artikel bijgewerkt - de
                   engine hoort het verschil, want alleen bij een eerste
                   publicatie gebeurt daar nog iets mee. */
                $known = ContentStudioArticle::query()->where('content_studio_article_id', $item['id'])->exists();

                if ($this->handle($item, $known ? 'updated' : 'published')) {
                    $handled++;
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

    /**
     * Stap 6 tot 9: cache, ophalen, verifiëren, melden.
     */
    private function finish(ContentStudioArticle $article, int $articleId, string $action): bool
    {
        $run = function () use ($article, $articleId, $action) {
            ignore_user_abort(true);
            Publication::report($article, $articleId, $action, Publication::finalize($article), $this->engine);
        };

        if ($this->defer) {
            app()->terminating($run);
        } else {
            $run();
        }

        return true;
    }
}
