<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Shazzoo\StrategyEngine\Events\ArticlePageChanged;
use Shazzoo\StrategyEngine\Models\ContentStudioArticle;
use Shazzoo\StrategyEngine\Support\Engine\SyncFromEngine;
use Shazzoo\StrategyEngine\Support\Provenance\Disclosure;
use Shazzoo\StrategyEngine\Support\Publication\Publication;
use Shazzoo\StrategyEngine\Support\Publication\PublicationVerifier as Verifier;

/*
 * De hele weg: de Engine kondigt aan, de site haalt op, slaat op, publiceert,
 * kijkt naar de eigen pagina en meldt wat hij zag.
 */

/** Byline en white label zoals de Engine ze zou moeten sturen. */
function complete(array $changes = []): array
{
    return array_replace(['byline' => ['text' => 'Samengesteld door Scout, AI-redactieassistent van Content Studio'], 'white_label' => false], $changes);
}

function deliver($test, array $engineArticle, ?callable $mutate = null): array
{
    $test->serveSite($mutate, $engineArticle);
    SyncFromEngine::make()->one((int) $engineArticle['id'], 'published');

    return $test->acknowledgements();
}

function stored(): ContentStudioArticle
{
    return ContentStudioArticle::firstOrFail();
}

beforeEach(fn () => $this->connect());

test('a complete scout article is published, verified and reported', function () {
    $acks = deliver($this, $this->article($this->provenance('scout', complete())));

    expect($acks)->toHaveCount(1)
        ->and($acks[0]['action'])->toBe('published')
        ->and($acks[0]['url'])->toBe('http://localhost/blog/btw-aangifte')
        ->and($acks[0]['verification'])->toMatchArray([
            'publication_status' => Verifier::VERIFIED,
            'provenance_rendered' => true,
            'structured_data_valid' => true,
            'visible_author_valid' => true,
            'disclosure_count' => 1,
            'conflicts' => [],
            'warnings' => [],
            'public_url' => 'http://localhost/blog/btw-aangifte',
            'body_revision' => self::REVISION,
        ])
        ->and($acks[0]['verification']['verified_at'])->not->toBe('');

    expect(stored()->verification_status)->toBe(Verifier::VERIFIED)
        ->and(stored()->isPublic())->toBeTrue();
});

test('today\'s engine payload, without byline and white label, is published with a warning', function () {
    $acks = deliver($this, $this->article($this->provenance('scout')));

    expect($acks[0]['action'])->toBe('published')
        ->and($acks[0]['verification']['publication_status'])->toBe(Verifier::PUBLISHED_WITH_WARNING)
        ->and($acks[0]['verification']['warnings'])->toContain('byline_missing_from_payload', 'white_label_missing_from_payload');
});

test('a human author and an organization are verified too', function (string $mode) {
    $acks = deliver($this, $this->article($this->provenance($mode, complete(['byline' => ['text' => '']])), ['author_name' => 'Anna de Vries']));

    expect($acks[0]['verification']['visible_author_valid'])->toBeTrue()
        ->and($acks[0]['verification']['structured_data_valid'])->toBeTrue()
        ->and($acks[0]['verification']['disclosure_count'])->toBe(1);
})->with(['person', 'organization']);

test('the engine decides: an article with null provenance is published without a block, with a warning', function () {
    $acks = deliver($this, $this->article(null));

    expect(stored()->isPublic())->toBeTrue()
        ->and(stored()->provenance_state)->toBe(ContentStudioArticle::STATE_INVALID)
        ->and(Disclosure::count($this->page()))->toBe(0)
        ->and($acks[0]['action'])->toBe('published')
        ->and($acks[0]['url'])->toBe('http://localhost/blog/btw-aangifte')
        ->and($acks[0]['verification']['publication_status'])->toBe(Verifier::PUBLISHED_WITH_WARNING)
        ->and($acks[0]['verification']['warnings'])->toContain('provenance_missing', 'provenance_invalid:provenance_missing');
});

test('an older engine without provenance publishes as before, with a warning', function () {
    $acks = deliver($this, $this->article(false));

    expect(stored()->isPublic())->toBeTrue()
        ->and(stored()->provenance_state)->toBe(ContentStudioArticle::STATE_LEGACY)
        ->and($acks[0]['action'])->toBe('published')
        ->and($acks[0]['verification']['publication_status'])->toBe(Verifier::PUBLISHED_WITH_WARNING)
        ->and($acks[0]['verification']['warnings'])->toContain('provenance_not_supported_by_engine');
});

test('publishing the same article twice changes nothing', function () {
    $item = $this->article($this->provenance('scout', complete()));

    deliver($this, $item);
    $first = stored()->only(['provenance', 'body_html', 'body_revision', 'disclosure_hash']);
    deliver($this, $item);

    expect(ContentStudioArticle::count())->toBe(1)
        ->and(stored()->only(['provenance', 'body_html', 'body_revision', 'disclosure_hash']))->toBe($first)
        ->and(Disclosure::count($this->page()))->toBe(1);
});

test('a new revision replaces the block', function () {
    deliver($this, $this->article($this->provenance('scout', complete())));
    deliver($this, $this->article($this->provenance('scout', complete(['article_revision_id' => self::REVISION_2])), ['body_html' => '<p>Nieuwe tekst.</p>']));

    $html = $this->page();

    expect(Disclosure::count($html))->toBe(1)
        ->and($html)->toContain('data-body-revision="'.self::REVISION_2.'"')
        ->and($html)->not->toContain(self::REVISION)
        ->and($this->acknowledgements()[0]['verification']['body_revision'])->toBe(self::REVISION_2);
});

test('an invalid update is published without a block, and the old block goes', function () {
    deliver($this, $this->article($this->provenance('scout', complete())));
    $acks = deliver($this, $this->article($this->provenance('scout', ['article_revision_id' => 'kapot']), ['body_html' => '<p>Nieuwe tekst.</p>']));

    $html = $this->page();

    expect(stored()->isPublic())->toBeTrue()
        ->and(stored()->body_html)->toBe('<p>Nieuwe tekst.</p>')
        ->and($html)->toContain('Nieuwe tekst.')
        ->and(Disclosure::count($html))->toBe(0)
        ->and($acks[0]['action'])->toBe('published')
        ->and($acks[0]['verification']['warnings'])->toContain('provenance_invalid:body_revision_invalid');

    // Een geldige aanlevering daarna zet het blok terug.
    deliver($this, $this->article($this->provenance('scout', complete(['article_revision_id' => self::REVISION_2]))));

    expect(stored()->provenance_state)->toBe(ContentStudioArticle::STATE_VALID)
        ->and(stored()->provenance_errors)->toBeNull()
        ->and(Disclosure::count($this->page()))->toBe(1);
});

test('when the theme does not render the component, the body fallback is used once', function () {
    $this->themeWithoutComponent();
    $acks = deliver($this, $this->article($this->provenance('scout', complete())));

    $html = $this->page();

    expect($acks[0]['verification']['publication_status'])->toBe(Verifier::VERIFIED)
        ->and($acks[0]['verification']['warnings'])->toBe(['native_rendering_unavailable_body_fallback_used'])
        ->and(stored()->disclosure_mode)->toBe(Disclosure::MODE_BODY)
        ->and(Disclosure::count($html))->toBe(1)
        ->and(Disclosure::count(stored()->body_html))->toBe(1)
        ->and($html)->not->toContain('content-studio-provenance:start -->Over');

    // Een nieuwe revisie vervangt het blok in de tekst, en verdubbelt het niet.
    deliver($this, $this->article($this->provenance('scout', complete(['article_revision_id' => self::REVISION_2]))));

    expect(Disclosure::count(stored()->body_html))->toBe(1)
        ->and(stored()->body_html)->toContain(self::REVISION_2)
        ->and(stored()->body_html)->not->toContain(self::REVISION);
});

test('when the disclosure can not be shown at all, the article stays online and the engine hears it', function () {
    deliver($this, $this->article($this->provenance('scout', complete())));

    // Een thema dat elk blok weghaalt, ook in de tekst.
    $hideAll = fn (string $html) => preg_replace('#<aside.*?</aside>#s', '', $html);
    $acks = deliver($this, $this->article($this->provenance('scout', complete(['article_revision_id' => self::REVISION_2])), ['body_html' => '<p>Nieuwe tekst.</p>']), $hideAll);

    expect($acks[0]['action'])->toBe('published')
        ->and($acks[0]['url'])->toBe('http://localhost/blog/btw-aangifte')
        ->and($acks[0]['verification']['publication_status'])->toBe(Verifier::PROVENANCE_FAILED)
        ->and($acks[0]['verification']['failed_checks'])->toHaveKey('disclosure_visible')
        ->and($acks[0]['verification']['warnings'])->toContain('body_fallback_did_not_render')
        ->and(stored()->isPublic())->toBeTrue()
        ->and(stored()->body_revision)->toBe(self::REVISION_2)
        ->and(Disclosure::count(stored()->body_html))->toBe(0);
});

test('a theme that adds its own article author is a warning by default and a block when asked', function () {
    $second = fn ($html) => str_replace('</head>', '<script type="application/ld+json">{"@type":"Article","author":{"@type":"Person","name":"Webredactie"},"datePublished":"2026-11-01T10:00:00+00:00"}</script></head>', $html);

    $acks = deliver($this, $this->article($this->provenance('scout', complete())), $second);

    expect($acks[0]['action'])->toBe('published')
        ->and($acks[0]['verification']['publication_status'])->toBe(Verifier::PUBLISHED_WITH_WARNING)
        ->and($acks[0]['verification']['structured_data_valid'])->toBeFalse()
        ->and($acks[0]['verification']['conflicts'])->not->toBe([]);

    config(['content-studio.provenance.schema_conflict_policy' => 'block']);
    $acks = deliver($this, $this->article($this->provenance('scout', complete(['article_revision_id' => self::REVISION_2]))), $second);

    expect($acks[0]['action'])->toBe('published')
        ->and($acks[0]['verification']['publication_status'])->toBe(Verifier::PROVENANCE_FAILED)
        ->and(stored()->isPublic())->toBeTrue();
});

test('caches are purged before every look at the page', function () {
    Event::fake([ArticlePageChanged::class]);
    deliver($this, $this->article($this->provenance('scout', complete())));

    Event::assertDispatched(ArticlePageChanged::class, fn ($event) => $event->url === 'http://localhost/blog/btw-aangifte');
});

test('a cache that serves the old page is retried, and checked again later', function () {
    deliver($this, $this->article($this->provenance('scout', complete())));

    // Revisie 2 komt binnen, maar de cache blijft revisie 1 tonen.
    $staleCache = fn ($html, $url) => str_contains($url, 'cs_verify=') ? $html : str_replace(self::REVISION_2, self::REVISION, $html);
    $acks = deliver($this, $this->article($this->provenance('scout', complete(['article_revision_id' => self::REVISION_2]))), $staleCache);

    expect($acks[0]['action'])->toBe('published')
        ->and($acks[0]['verification']['publication_status'])->toBe(Verifier::PUBLISHED_WITH_WARNING)
        ->and($acks[0]['verification']['warnings'])->toContain('stale_cache_served')
        ->and($this->fetched)->toHaveCount(3)
        ->and($this->fetched[2])->toContain('cs_verify=');

    // De scheduler kijkt na vijf minuten nog eens; inmiddels is de cache bij.
    $this->serveSite();
    $this->travel(6)->minutes();
    Artisan::call('content-studio:provenance', ['action' => 'due']);

    expect(stored()->verification_status)->toBe(Verifier::VERIFIED)
        ->and($this->acknowledgements()[0]['verification']['publication_status'])->toBe(Verifier::VERIFIED);
});

test('the webhook answers first and verifies afterwards', function () {
    $this->serveSite(null, $this->article($this->provenance('scout', complete())));

    $body = json_encode(['event' => 'article.publish', 'article_id' => 4711]);
    $timestamp = (string) time();

    $sync = SyncFromEngine::make()->deferred();
    $sync->one(4711, 'published');

    // Opgeslagen en publiek, maar nog niet bekeken of gemeld.
    expect(stored()->isPublic())->toBeTrue()
        ->and($this->acknowledgements())->toBe([])
        ->and($this->fetched)->toBe([]);

    $this->app->terminate();

    expect($this->acknowledgements()[0]['verification']['publication_status'])->toBe(Verifier::VERIFIED);

    // En via de echte route.
    $this->serveSite(null, $this->article($this->provenance('scout', complete(['article_revision_id' => self::REVISION_2]))));
    $this->call('POST', '/content-studio/webhook', [], [], [], [
        'HTTP_X_CONTENT_STUDIO_TIMESTAMP' => $timestamp,
        'HTTP_X_CONTENT_STUDIO_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, 'secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    // De test-app bewaart terminating-callbacks over verzoeken heen; in
    // productie is elk verzoek een eigen proces. Dus: de laatste melding.
    expect(collect($this->acknowledgements())->last()['verification']['body_revision'])->toBe(self::REVISION_2);
});

test('the catch-up round verifies and reports every article', function () {
    $this->serveSite();
    $this->engineChanges = ['data' => [
        $this->article($this->provenance('scout', complete())),
        $this->article(null, ['id' => 4712, 'slug' => 'tweede']),
    ], 'next_cursor' => 'c2', 'has_more' => false];

    SyncFromEngine::make()->catchUp();
    $acks = collect($this->acknowledgements())->keyBy('article_id');

    expect($acks[4711]['action'])->toBe('published')
        ->and($acks[4711]['verification']['publication_status'])->toBe(Verifier::VERIFIED)
        ->and($acks[4712]['action'])->toBe('published')
        ->and($acks[4712]['verification']['publication_status'])->toBe(Verifier::PUBLISHED_WITH_WARNING);
});

test('v1 confirms every article that is live, and only those', function () {
    ContentStudioArticle::query()->delete();
    \Shazzoo\StrategyEngine\Models\ContentStudioSetting::singleton()->forceFill(['cs_webhook_secret' => null])->save();
    $this->app['env'] = 'production';

    $this->serveSite();
    $article = $this->receive($this->provenance('scout', complete()));
    Publication::report($article, 4711, 'published', Publication::finalize($article));

    expect($this->confirmations())->toHaveCount(1)
        ->and($this->confirmations()[0]['published_url'])->toBe('http://localhost/blog/btw-aangifte');

    // Live zonder verantwoording is nog steeds live: V1 kent niets anders.
    $this->serveSite(fn ($html) => preg_replace('#<aside.*?</aside>#s', '', $html));
    $other = $this->receive($this->provenance('scout', complete()), ['id' => 4712, 'slug' => 'tweede']);
    Publication::report($other, 4712, 'published', Publication::finalize($other));

    expect($this->confirmations())->toHaveCount(1);

    // Een pagina die er niet is, wordt niet bevestigd.
    $this->serveSite();
    $missing = $this->receive($this->provenance('scout', complete()), ['id' => 4713, 'slug' => 'derde']);
    $notThere = Verifier::result(Verifier::PUBLICATION_FAILED, ['http_404'], Publication::url($missing), self::REVISION);
    Publication::report($missing, 4713, 'published', $notThere);

    expect($this->confirmations())->toBe([]);
});

test('verification does not move the modification date', function () {
    deliver($this, $this->article($this->provenance('scout', complete())));
    $modified = stored()->updated_at;

    $this->travel(2)->hours();
    Publication::finalize(stored());

    expect(stored()->updated_at->equalTo($modified))->toBeTrue();
});

test('recovery from the command line', function () {
    deliver($this, $this->article($this->provenance('scout', complete())));
    deliver($this, $this->article($this->provenance('scout', complete(['article_revision_id' => self::REVISION_2])), ['body_html' => '<p>Nieuwe tekst.</p>']));
    $id = stored()->id;

    // status
    expect(Artisan::call('content-studio:provenance', ['action' => 'status', 'article' => $id]))->toBe(0)
        ->and(Artisan::output())->toContain(self::REVISION_2)->toContain('verified');

    // dedupe: een blok dat er dubbel in de tekst kwam.
    $block = Disclosure::render(stored());
    stored()->forceFill(['body_html' => stored()->body_html.$block.$block])->save();
    expect(Disclosure::count($this->page()))->toBe(3);

    Artisan::call('content-studio:provenance', ['action' => 'dedupe', 'article' => $id, '--no-report' => true]);
    expect(Disclosure::count($this->page()))->toBe(1);

    // republish: opnieuw ophalen bij de engine en publiceren.
    $this->serveSite(null, $this->article($this->provenance('scout', complete(['article_revision_id' => self::REVISION_2])), ['body_html' => '<p>Nieuwe tekst.</p>']));
    expect(Artisan::call('content-studio:provenance', ['action' => 'republish', 'article' => 'engine:4711']))->toBe(0)
        ->and(stored()->body_revision)->toBe(self::REVISION_2)
        ->and($this->acknowledgements()[0]['verification']['publication_status'])->toBe(Verifier::VERIFIED);
});

test('a site that updated without migrating keeps receiving articles', function () {
    $migration = include dirname(__DIR__, 2).'/database/migrations/2026_11_03_000000_add_provenance_to_content_studio_articles_table.php';
    $migration->down();
    Publication::forgetInstalled();

    $this->serveSite();
    $article = $this->receive($this->provenance('scout', complete()));

    expect($article)->not->toBeNull()
        ->and(ContentStudioArticle::count())->toBe(1)
        ->and($this->page())->toContain('De tekst van het artikel.');

    $result = Publication::finalize($article);

    expect(Publication::succeeded($result))->toBeTrue()
        ->and($result['warnings'])->toBe(['provenance_migration_pending'])
        ->and(Artisan::call('content-studio:provenance', ['action' => 'due']))->toBe(0);

    // Na de migratie werkt alles weer.
    $migration->up();
    Publication::forgetInstalled();
    $article = $this->receive($this->provenance('scout', complete()));

    expect($article->provenance_state)->toBe(ContentStudioArticle::STATE_VALID)
        ->and(Disclosure::count($this->page()))->toBe(1);
});

test('an article that never arrived can be fetched by its engine id', function () {
    $this->serveSite(null, $this->article($this->provenance('scout', complete()), ['id' => 2333]));

    expect(ContentStudioArticle::count())->toBe(0)
        ->and(Artisan::call('content-studio:provenance', ['action' => 'republish', 'article' => 'engine:2333']))->toBe(0)
        ->and(ContentStudioArticle::where('content_studio_article_id', 2333)->exists())->toBeTrue()
        ->and($this->acknowledgements()[0]['action'])->toBe('published');
});
