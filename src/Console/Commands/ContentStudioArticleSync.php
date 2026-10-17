<?php

namespace Shazzoo\StrategyEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Shazzoo\StrategyEngine\Http\Controllers\SyncArticles;
use Shazzoo\StrategyEngine\Support\Engine\EngineClient;
use Shazzoo\StrategyEngine\Support\Engine\SyncFromEngine;

class ContentStudioArticleSync extends Command
{
    protected $signature = 'content-studio:articles {--status=approved : Statusfilter voor de Engine: approved, published, een kommalijst, of "all" voor alles}';

    protected $description = 'Sync content articles from the Content Studio API.';

    /**
     * Dezelfde opdracht, twee manieren van werken.
     *
     * Is deze site aangemeld bij de engine, dan wordt hij gebeld zodra er
     * iets klaarstaat en is dit alleen nog het vangnet: ophalen wat een
     * gemiste webhook heeft laten liggen, vanaf de eigen cursor. Zo niet,
     * dan blijft het de oude ophaalronde.
     *
     * Nooit allebei: dat is precies de weg waarlangs hetzelfde artikel
     * twee keer gepubliceerd zou worden.
     */
    public function handle(SyncArticles $syncArticles): int
    {
        $engine = EngineClient::fromSettings();

        if ($engine->isConfigured() && ! $engine->isConnected()) {
            /* Zelf aanmelden met de sleutels die hier al staan. Lukt het
               niet - een engine die V2 nog niet kent, een adres dat van
               buiten niet te bereiken is - dan valt deze site gewoon terug
               op de oude weg. */
            $engine->register(route('content-studio.webhook'));
        }

        if ($engine->isConnected()) {
            $handled = SyncFromEngine::make()->catchUp();

            $this->info("Bijgewerkt via de engine: {$handled} artikel(en).");

            return self::SUCCESS;
        }

        Log::info('[ContentStudioArticleSync] Starting article sync...');
        $this->info('Starting Content Studio sync...');

        $status = (string) $this->option('status');

        $result = $syncArticles->__invoke(Request::create('/', 'GET', ['status' => $status]));

        if (! is_array($result)) {
            $this->warn('Sync finished without structured output. Check logs.');

            return self::SUCCESS;
        }

        $this->line('Message: '.$result['message']);
        $this->line('Synced articles: '.($result['synced'] ?? 0));
        $this->line('Skipped (not approved): '.($result['skipped'] ?? 0));

        if (null !== ($result['expected'] ?? null)) {
            $this->line('Available on Engine: '.$result['expected']);
        }
        $this->line('Pages processed: '.($result['pages'] ?? 0));

        if (! empty($result['errors'])) {
            $first = $result['errors'][0] ?? [];
            $this->error('Sync failed. Status: '.($first['status'] ?? 'n/a'));
            if (! empty($first['url'])) {
                $this->line('URL: '.$first['url']);
            }

            return self::FAILURE;
        }

        $this->info('Content Studio sync completed successfully.');

        return self::SUCCESS;
    }
}
