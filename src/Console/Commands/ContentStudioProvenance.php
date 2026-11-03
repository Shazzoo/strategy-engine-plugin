<?php

namespace Shazzoo\StrategyEngine\Console\Commands;

use Illuminate\Console\Command;
use Shazzoo\StrategyEngine\Models\ContentStudioArticle;
use Shazzoo\StrategyEngine\Support\Engine\EngineClient;
use Shazzoo\StrategyEngine\Support\Engine\SyncFromEngine;
use Shazzoo\StrategyEngine\Support\Provenance\Disclosure;
use Shazzoo\StrategyEngine\Support\Publication\Publication;

/**
 * Herstel en inzage per artikel.
 *
 *   status    wat er is vastgelegd en wat de laatste verificatie zag
 *   verify    de publieke pagina nu opnieuw controleren (en melden)
 *   republish het artikel opnieuw bij de engine ophalen en publiceren
 *   dedupe    dubbele blokken uit de tekst halen en opnieuw controleren
 *   due       herhaalpogingen na een tijdelijke waarschuwing (scheduler)
 */
class ContentStudioProvenance extends Command
{
    protected $signature = 'content-studio:provenance
        {action : status, verify, republish, dedupe of due}
        {article? : id van het artikel hier, of engine:<id> voor het id in de engine}
        {--no-report : niets aan de engine melden}';

    protected $description = 'Provenance van Content Studio-artikelen bekijken, controleren en herstellen.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        if ($action === 'due') {
            $this->info('Opnieuw gecontroleerd: '.Publication::reverifyDue());

            return self::SUCCESS;
        }

        $article = $this->article();

        if (! $article) {
            $this->error('Artikel niet gevonden.');

            return self::FAILURE;
        }

        return match ($action) {
            'status' => $this->status($article),
            'verify' => $this->verify($article),
            'republish' => $this->republish($article),
            'dedupe' => $this->dedupe($article),
            default => $this->unknown($action),
        };
    }

    private function status(ContentStudioArticle $article): int
    {
        $provenance = $article->provenance;

        $this->table(['', ''], [
            ['Artikel', $article->id.' (engine '.$article->content_studio_article_id.')'],
            ['URL', Publication::url($article)],
            ['Provenance', (string) ($article->provenance_state ?: '-')],
            ['Auteursmodus', (string) ($provenance['authorship_mode'] ?? '-')],
            ['Body-revisie', (string) ($article->body_revision ?: '-')],
            ['Weergave', Disclosure::mode($article).', positie '.Disclosure::position($article)],
            ['Fouten', (string) ($article->provenance_errors ?: '-')],
            ['Verificatie', (string) ($article->verification_status ?: '-').' op '.($article->verified_at ?: '-')],
            ['Waarschuwingen', implode(', ', (array) ($article->verification['warnings'] ?? [])) ?: '-'],
        ]);

        return self::SUCCESS;
    }

    private function verify(ContentStudioArticle $article): int
    {
        $result = Publication::finalize($article);
        $this->report($article, $result);
        $this->line(json_encode(Publication::payload($result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return Publication::succeeded($result) ? self::SUCCESS : self::FAILURE;
    }

    private function republish(ContentStudioArticle $article): int
    {
        $engine = EngineClient::fromSettings();

        if (! $engine->isConnected()) {
            $this->error('Deze site is niet bij de engine aangemeld (V2); gebruik content-studio:articles --status=all.');

            return self::FAILURE;
        }

        $sync = new SyncFromEngine($engine, app(\Shazzoo\StrategyEngine\Http\Controllers\SyncArticles::class));
        $ok = $sync->one((int) $article->content_studio_article_id, 'updated');

        $this->line($ok ? 'Opnieuw gepubliceerd.' : 'Niet gelukt; zie status en het log.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function dedupe(ContentStudioArticle $article): int
    {
        Disclosure::syncBody($article);
        $this->line('Tekst opgeschoond ('.Disclosure::mode($article).').');

        return $this->verify($article);
    }

    private function unknown(string $action): int
    {
        $this->error("Onbekende actie: {$action}");

        return self::FAILURE;
    }

    private function report(ContentStudioArticle $article, array $result): void
    {
        if (! $this->option('no-report') && $article->content_studio_article_id) {
            Publication::report($article, (int) $article->content_studio_article_id, 'updated', $result);
        }
    }

    private function article(): ?ContentStudioArticle
    {
        $key = (string) $this->argument('article');

        if (str_starts_with($key, 'engine:')) {
            return ContentStudioArticle::query()->where('content_studio_article_id', substr($key, 7))->first();
        }

        return $key !== '' ? ContentStudioArticle::query()->find($key) : null;
    }
}
