<?php

use Shazzoo\StrategyEngine\Models\ContentStudioArticle;
use Shazzoo\StrategyEngine\Support\Publication\Publication;
use Shazzoo\StrategyEngine\Support\Publication\PublicationVerifier as Verifier;

/*
 * De vijftien controles op de publieke pagina, elk los: HTML erin, oordeel
 * eruit.
 */

function evaluatePage($test, string $html, int $status = 200): array
{
    return Verifier::evaluate($html, $status, Publication::expectations(ContentStudioArticle::first()));
}

function failedChecks(array $result): array
{
    return array_keys(array_filter($result['checks'], fn ($check) => ! $check['ok']));
}

function withHead(string $html, string $extra): string
{
    return str_replace('</head>', $extra.'</head>', $html);
}

test('a correct page is verified in every authorship mode', function (string $mode) {
    $this->receive($this->provenance($mode));
    $result = evaluatePage($this, $this->page());

    expect($result['publication_status'])->toBe(Verifier::VERIFIED, implode(',', failedChecks($result)).' '.implode(',', $result['warnings']))
        ->and($result['provenance_rendered'])->toBeTrue()
        ->and($result['structured_data_valid'])->toBeTrue()
        ->and($result['visible_author_valid'])->toBeTrue()
        ->and($result['disclosure_count'])->toBe(1)
        ->and($result['body_revision'])->toBe(self::REVISION)
        ->and($result['conflicts'])->toBe([])
        ->and($result['public_url'])->toBe('http://localhost/blog/btw-aangifte');
})->with(['scout', 'organization', 'person']);

test('all fifteen checks are run', function () {
    $this->receive($this->provenance('scout'));

    expect(array_keys(evaluatePage($this, $this->page())['checks']))->toEqualCanonicalizing([
        'http_200', 'jsonld_valid', 'scout_never_person', 'no_conflicting_article_authors', 'urls_clean',
        'dates_consistent', 'disclosure_visible', 'disclosure_once', 'body_revision', 'visible_author',
        'structured_data_author', 'human_author_is_person', 'organization_author_is_organization',
        'publisher', 'reviewer_only_when_human',
    ]);
});

test('a missing disclosure fails the provenance', function () {
    $this->receive($this->provenance('scout'));
    $result = evaluatePage($this, preg_replace('#<aside.*?</aside>#s', '', $this->page()));

    expect($result['publication_status'])->toBe(Verifier::PROVENANCE_FAILED)
        ->and($result['provenance_rendered'])->toBeFalse()
        ->and($result['disclosure_count'])->toBe(0);
});

test('a hidden disclosure fails the provenance', function (string $hide) {
    $this->receive($this->provenance('scout'));
    $result = evaluatePage($this, preg_replace('#<aside #', $hide, $this->page(), 1));

    expect(failedChecks($result))->toContain('disclosure_visible');
})->with(['<aside hidden ', '<aside style="display: none" ', '<aside aria-hidden="true" ']);

test('a duplicate disclosure is reported', function () {
    $this->receive($this->provenance('scout'));
    $page = $this->page();
    preg_match('#<aside.*?</aside>#s', $page, $block);
    $result = evaluatePage($this, str_replace('</body>', $block[0].'</body>', $page));

    expect($result['disclosure_count'])->toBe(2)
        ->and(failedChecks($result))->toContain('disclosure_once')
        ->and($result['publication_status'])->not->toBe(Verifier::VERIFIED);
});

test('an old body revision fails', function () {
    $this->receive($this->provenance('scout'));

    expect(failedChecks(evaluatePage($this, str_replace(self::REVISION, self::REVISION_2, $this->page()))))->toContain('body_revision');
});

test('a different visible author fails', function () {
    $this->receive($this->provenance('person'));
    $page = str_replace('data-provenance-author="Anna de Vries"', 'data-provenance-author="Iemand Anders"', $this->page());

    expect(failedChecks(evaluatePage($this, $page)))->toContain('visible_author');
});

test('scout as a person anywhere on the page fails', function () {
    $this->receive($this->provenance('scout'));

    $jsonld = evaluatePage($this, withHead($this->page(), '<script type="application/ld+json">{"@type":"Person","name":"Scout"}</script>'));
    expect($jsonld['publication_status'])->toBe(Verifier::PROVENANCE_FAILED)
        ->and(failedChecks($jsonld))->toContain('scout_never_person');

    $microdata = evaluatePage($this, str_replace('</body>', '<div itemscope itemtype="https://schema.org/Person"><span itemprop="name">Scout</span></div></body>', $this->page()));
    expect(failedChecks($microdata))->toContain('scout_never_person');
});

test('a reviewer on an article nobody read fails', function () {
    $this->receive($this->provenance('scout'));
    $result = evaluatePage($this, withHead($this->page(), '<script type="application/ld+json">{"@type":"WebPage","reviewedBy":{"@type":"Person","name":"Jan Jansen"}}</script>'));

    expect($result['publication_status'])->toBe(Verifier::PROVENANCE_FAILED)
        ->and(failedChecks($result))->toContain('reviewer_only_when_human');
});

test('a second article author from the theme is a conflict', function () {
    $this->receive($this->provenance('scout'));
    $second = '<script type="application/ld+json">{"@type":"Article","author":{"@type":"Person","name":"Webredactie"},"publisher":{"@type":"Organization","name":"Klant BV"},"datePublished":"2026-11-01T10:00:00+00:00"}</script>';

    $result = evaluatePage($this, withHead($this->page(), $second));

    expect(failedChecks($result))->toContain('no_conflicting_article_authors')
        ->and($result['conflicts'])->not->toBe([])
        ->and($result['structured_data_valid'])->toBeFalse()
        ->and($result['publication_status'])->toBe(Verifier::PUBLISHED_WITH_WARNING);

    config(['content-studio.provenance.schema_conflict_policy' => 'block']);

    expect(evaluatePage($this, withHead($this->page(), $second))['publication_status'])->toBe(Verifier::PROVENANCE_FAILED);
});

test('a wrong publisher or author type is a schema failure', function () {
    $this->receive($this->provenance('person'));
    $page = $this->page();

    $publisher = evaluatePage($this, str_replace('"publisher":{"@type":"Organization","name":"Klant BV"', '"publisher":{"@type":"Organization","name":"Ander BV"', $page));
    expect(failedChecks($publisher))->toContain('publisher');

    $type = evaluatePage($this, str_replace('"@type":"Person","name":"Anna de Vries"', '"@type":"Organization","name":"Anna de Vries"', $page));
    expect(failedChecks($type))->toContain('human_author_is_person', 'structured_data_author');
});

test('invalid json-ld, markdown urls and inconsistent dates are warnings', function () {
    $this->receive($this->provenance('scout'));

    $invalid = evaluatePage($this, withHead($this->page(), '<script type="application/ld+json">{"@type": "Thing",</script>'));
    expect(failedChecks($invalid))->toContain('jsonld_valid')
        ->and($invalid['structured_data_valid'])->toBeFalse()
        ->and($invalid['publication_status'])->toBe(Verifier::PUBLISHED_WITH_WARNING);

    $markdown = evaluatePage($this, withHead($this->page(), '<script type="application/ld+json">{"@type":"Thing","url":"[site](https://klant.example)"}</script>'));
    expect(failedChecks($markdown))->toContain('urls_clean');

    $escaped = evaluatePage($this, withHead($this->page(), '<script type="application/ld+json">{"@type":"Thing","url":"https://klant.example/%5Bx%5D%28y%29"}</script>'));
    expect(failedChecks($escaped))->toContain('urls_clean');

    $dates = evaluatePage($this, str_replace('"datePublished":"2026-11-01T10:00:00+00:00"', '"datePublished":"2026-10-01T10:00:00+00:00"', $this->page()));
    expect(failedChecks($dates))->toContain('dates_consistent');
});

test('a page that is not there is a publication failure', function () {
    $this->receive($this->provenance('scout'));

    expect(evaluatePage($this, 'Not found', 404)['publication_status'])->toBe(Verifier::PUBLICATION_FAILED);
});

test('a stale cache is retried with backoff and a purge', function () {
    $this->receive($this->provenance('scout'));
    $fresh = $this->page();
    $responses = [str_replace(self::REVISION, self::REVISION_2, $fresh), $fresh];
    $sleeps = [];
    $purges = 0;

    $result = Verifier::run('http://localhost/blog/btw-aangifte', Publication::expectations(ContentStudioArticle::first()), [
        'attempts' => 3,
        'fetch' => function () use (&$responses) {
            return ['status' => 200, 'body' => array_shift($responses)];
        },
        'sleep' => function ($seconds) use (&$sleeps) {
            $sleeps[] = $seconds;
        },
        'purge' => function () use (&$purges) {
            $purges++;
        },
    ]);

    expect($result['publication_status'])->toBe(Verifier::VERIFIED)
        ->and($result['attempts'])->toBe(2)
        ->and($sleeps)->toBe([1])
        ->and($purges)->toBe(1);
});

test('when only the cache buster sees the new version, that is a warning', function () {
    $this->receive($this->provenance('scout'));
    $fresh = $this->page();
    $stale = str_replace(self::REVISION, self::REVISION_2, $fresh);
    $urls = [];

    $result = Verifier::run('http://localhost/blog/btw-aangifte', Publication::expectations(ContentStudioArticle::first()), [
        'fetch' => function ($url, $busted) use ($fresh, $stale, &$urls) {
            $urls[] = $url;

            return ['status' => 200, 'body' => $busted ? $fresh : $stale];
        },
        'sleep' => function () {},
    ]);

    expect($result['publication_status'])->toBe(Verifier::PUBLISHED_WITH_WARNING)
        ->and($result['warnings'])->toContain('stale_cache_served')
        ->and($result['attempts'])->toBe(3)
        ->and($urls[2])->toContain('cs_verify=')
        ->and($urls[0])->not->toContain('cs_verify=');
});

test('an unreachable page is a warning, not a success', function () {
    $this->receive($this->provenance('scout'));

    $result = Verifier::run('http://localhost/blog/btw-aangifte', Publication::expectations(ContentStudioArticle::first()), [
        'attempts' => 2,
        'fetch' => fn () => 'cURL error 7',
        'sleep' => function () {},
    ]);

    expect($result['publication_status'])->toBe(Verifier::PUBLISHED_WITH_WARNING)
        ->and($result['warnings'][0])->toBe('verification_unavailable:cURL error 7')
        ->and($result['provenance_rendered'])->toBeFalse();
});

test('the verifier identifies itself and sends no cookies', function () {
    $this->receive($this->provenance('scout'));
    $this->serveSite();

    Verifier::fetch('http://localhost/blog/btw-aangifte');

    $request = collect(\Illuminate\Support\Facades\Http::recorded())->last()[0];

    expect($request->header('User-Agent')[0])->toStartWith('ContentStudioVerifier/1.0')
        ->and($request->hasHeader('Cookie'))->toBeFalse();
});
