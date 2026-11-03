<?php

namespace Shazzoo\StrategyEngine\Tests;

use Artesaos\SEOTools\Providers\SEOToolsServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Shazzoo\ContentStudioCore\Providers\SeoServiceProvider;
use Shazzoo\StrategyEngine\Models\ContentStudioSetting;
use Shazzoo\StrategyEngine\PluginServiceProvider;

/**
 * Een Laravel-app met de plugin en het deel van Core dat een artikelpagina
 * nodig heeft: de SEO-laag. De pagina's die de verifier ophaalt, worden
 * echt gerenderd, via de routes van de plugin.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    public const SCOUT_URL = 'https://engine.content-studio.com/scout';

    public const REVISION = 'a94a8fe5ccb19ba61c4c0873d391e987982fbbd3';

    public const REVISION_2 = 'b94a8fe5ccb19ba61c4c0873d391e987982fbbd4';

    /** Wat de Engine-API voor één artikel teruggeeft. */
    public ?array $engineArticle = null;

    /** @var callable|null Past de opgehaalde pagina aan (een cache, een thema). */
    public $mutate = null;

    /** @var array<int, string> De URL's die de verifier ophaalde. */
    public array $fetched = [];

    /** Wat /sync/changes teruggeeft. */
    public ?array $engineChanges = null;

    /** Verzoeken van vóór de laatste serveSite() tellen niet mee. */
    private int $recordedOffset = 0;

    private bool $faked = false;

    protected function getPackageProviders($app): array
    {
        return [SEOToolsServiceProvider::class, SeoServiceProvider::class, PluginServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.name', 'Klant BV');
        $app['config']->set('app.locale', 'nl');
        $app['config']->set('view.paths', [__DIR__.'/views']);

        // Nooit echt wachten in een test.
        $app['config']->set('content-studio.provenance.verifier.sleep', function () {});
    }

    protected function defineDatabaseMigrations(): void
    {
        \Shazzoo\StrategyEngine\Support\Publication\Publication::forgetInstalled();
        \Shazzoo\StrategyEngine\Support\Engine\EngineClient::forgetSchema();

        // Wat Core zelf aanmaakt en de plugin leest.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->string('type')->default('string');
            $table->timestamps();
        });
    }

    /** Een site die bij de Engine is aangemeld (V2). */
    public function connect(): void
    {
        ContentStudioSetting::singleton()->forceFill([
            'cs_api_key' => 'key',
            'cs_project_code' => 'project',
            'cs_webhook_secret' => 'secret',
        ])->save();
    }

    /** Alleen V1: sleutel en project, geen webhookgeheim. */
    public function configureV1(): void
    {
        ContentStudioSetting::singleton()->forceFill([
            'cs_api_key' => 'key',
            'cs_project_code' => 'project',
        ])->save();
    }

    public function publisher(): array
    {
        return ['type' => 'organization', 'name' => 'Klant BV', 'url' => 'https://klant.example', 'logo_url' => null];
    }

    /**
     * content_provenance zoals de Engine het stuurt (ProvenancePayload).
     *
     * @param  string  $mode  scout, organization of person
     */
    public function provenance(string $mode = 'scout', array $changes = []): array
    {
        $publisher = $this->publisher();

        switch ($mode) {
            case 'person':
                $author = ['type' => 'person', 'name' => 'Anna de Vries', 'role_title' => 'Fiscalist', 'url' => 'https://klant.example/team/anna', 'is_person' => true];
                $structuredAuthor = ['@type' => 'Person', 'name' => 'Anna de Vries', 'jobTitle' => 'Fiscalist', 'url' => 'https://klant.example/team/anna'];
                $authorship = 'human_person';
                $disclosure = 'Dit artikel is met AI-ondersteuning opgesteld en door Anna de Vries geschreven en goedgekeurd.';
                break;

            case 'organization':
                $author = $publisher;
                $structuredAuthor = ['@type' => 'Organization', 'name' => 'Klant BV', 'url' => 'https://klant.example'];
                $authorship = 'customer_organization';
                $disclosure = 'Dit artikel is met behulp van AI samengesteld door Klant BV.';
                break;

            default:
                $author = [
                    'type' => 'organization',
                    'name' => 'Content Studio Redactie',
                    'alternate_name' => 'Scout',
                    'role_title' => 'AI-redactieassistent van Content Studio',
                    'url' => self::SCOUT_URL,
                    'is_person' => false,
                ];
                $structuredAuthor = ['@type' => 'Organization', 'name' => 'Content Studio Redactie', 'alternateName' => 'Scout', 'url' => self::SCOUT_URL];
                $authorship = 'content_studio_scout';
                $disclosure = 'Dit artikel is samengesteld door Scout, de AI-redactieassistent van Content Studio, in opdracht van Klant BV.';
        }

        $payload = array_replace([
            'provenance_version' => 1,
            'article_revision_id' => self::REVISION,
            'authorship_mode' => $authorship,
            'author' => $author,
            'publisher' => $publisher,
            'assembled_by' => ['name' => 'Scout', 'role_title' => 'AI-redactieassistent van Content Studio', 'organization' => 'Content Studio Redactie', 'url' => self::SCOUT_URL, 'is_person' => false],
            'ai_assisted' => true,
            'generation_method' => 'ai_generated',
            'generation_provider' => 'Content Studio Strategy Engine',
            'expert_contributors' => [],
            'review_status' => 'automated_checks_only',
            'reviewer' => null,
            'reviewed_at' => null,
            'reviewed_revision_id' => null,
            'source_validation' => ['status' => 'passed'],
            'experience_basis' => 'none',
            'disclosure_text' => $disclosure,
            'disclosure_marker' => 'content-studio-provenance',
            'structured_data' => [
                'author' => $structuredAuthor,
                'publisher' => ['@type' => 'Organization', 'name' => 'Klant BV', 'url' => 'https://klant.example'],
            ],
        ], $changes);

        $payload['disclosure_fingerprint'] = sha1((string) $payload['disclosure_text']);

        return $payload;
    }

    /**
     * Een artikel zoals de Engine het levert.
     *
     * @param  array|null|false  $provenance  false: de sleutel ontbreekt (een Engine van vóór provenance).
     */
    public function article(array|null|false $provenance, array $changes = []): array
    {
        $article = array_replace([
            'id' => 4711,
            'status' => 'approved',
            'locale' => 'nl',
            'title' => 'Btw-aangifte zonder zorgen',
            'slug' => 'btw-aangifte',
            'body_html' => '<p>De tekst van het artikel.</p>',
            'excerpt' => null,
            'author_name' => 'Voice Persona',
            'generated_at' => '2026-11-01T10:00:00+00:00',
        ], $changes);

        if ($provenance !== false) {
            $article['content_provenance'] = $provenance;
        }

        return $article;
    }

    /**
     * Laat alle uitgaande HTTP lokaal afhandelen: de Engine-API antwoordt
     * met $engineArticle, en de verifier krijgt de pagina zoals de plugin
     * hem echt rendert.
     */
    public function serveSite(?callable $mutate = null, ?array $engineArticle = null): void
    {
        $this->mutate = $mutate;
        $this->engineArticle = $engineArticle;
        $this->fetched = [];

        // Eén fake per test: Laravel roept elke geregistreerde callback aan.
        if ($this->faked) {
            $this->recordedOffset = count(Http::recorded());

            return;
        }

        $this->faked = true;

        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            if (str_contains($url, '/v2/projects/') && str_contains($url, '/articles/')) {
                return Http::response(['data' => $this->engineArticle]);
            }

            if (str_contains($url, '/sync/changes')) {
                return Http::response($this->engineChanges ?? ['data' => [], 'next_cursor' => null, 'has_more' => false]);
            }

            if (str_contains($url, 'engine.content-studio.com')) {
                return Http::response(['ok' => true]);
            }

            $this->fetched[] = $url;

            return Http::response(...$this->renderPage($url));
        });
    }

    /**
     * Een publieke pagina, door de echte kernel en routes, zoals een bezoeker
     * hem krijgt.
     *
     * @return array{0: string, 1: int}
     */
    public function renderPage(string $url): array
    {
        // Elke pagina in een eigen proces: SEOTools houdt zijn tags in
        // singletons bij.
        foreach (['seotools', 'seotools.metatags', 'seotools.opengraph', 'seotools.twitter', 'seotools.json-ld', 'seotools.json-ld-multi'] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        Facade::clearResolvedInstances();

        $previous = $this->app['request'];
        $response = $this->app->make(Kernel::class)->handle(Request::create($url, 'GET'));
        $this->app->instance('request', $previous);

        $html = (string) $response->getContent();
        $status = $response->getStatusCode();

        if ($status === 200 && is_callable($this->mutate)) {
            $html = ($this->mutate)($html, $url);
        }

        return [$html, $status];
    }

    /**
     * Een artikel binnen laten komen zoals de sync dat doet.
     */
    public function receive(array|null|false $provenance, array $changes = []): ?\Shazzoo\StrategyEngine\Models\ContentStudioArticle
    {
        return app(\Shazzoo\StrategyEngine\Http\Controllers\SyncArticles::class)->processArticle($this->article($provenance, $changes));
    }

    /** De publieke pagina van een artikel, als HTML. */
    public function page(string $slug = 'btw-aangifte'): string
    {
        return $this->renderPage('http://localhost/blog/'.$slug)[0];
    }

    /**
     * Een thema of eerder gepubliceerde view die de component niet kent: de
     * artikelpagina zonder blok "Over dit artikel".
     */
    public function themeWithoutComponent(): void
    {
        $dir = sys_get_temp_dir().'/cs-theme-'.uniqid();
        mkdir($dir.'/components', 0777, true);
        file_put_contents($dir.'/components/card-details.blade.php', '<article class="theme-article"><h1>{{ $article->title }}</h1><div class="article-body">{!! $article->body_html !!}</div></article>');

        $this->app['view']->prependNamespace('strategy-engine', $dir);
        $this->app['view']->getFinder()->flush();
    }

    /** @return array<int, array> Alle terugmeldingen aan de Engine (V2). */
    public function acknowledgements(): array
    {
        return collect(Http::recorded())
            ->slice($this->recordedOffset)
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'sync/acknowledgements'))
            ->map(fn ($pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    /** @return array<int, ClientRequest> V1-bevestigingen. */
    public function confirmations(): array
    {
        return collect(Http::recorded())
            ->slice($this->recordedOffset)
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'confirm-published'))
            ->map(fn ($pair) => $pair[0])
            ->values()
            ->all();
    }

    /** @return array<int, array> Alle JSON-LD-nodes op een pagina. */
    public function jsonLdNodes(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        $nodes = [];

        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);

            if (isset($decoded['@graph'])) {
                array_push($nodes, ...$decoded['@graph']);
            } elseif (is_array($decoded)) {
                $nodes[] = $decoded;
            }
        }

        return $nodes;
    }
}
