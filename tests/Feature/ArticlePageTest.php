<?php

use Shazzoo\StrategyEngine\Models\ContentStudioArticle;
use Shazzoo\StrategyEngine\Support\Provenance\Disclosure;
use Shazzoo\StrategyEngine\Tests\TestCase;

/*
 * Wat een bezoeker ziet: het blok "Over dit artikel", de auteursregel en de
 * structured data, zoals de plugin ze echt rendert.
 */

function block(string $html): string
{
    preg_match('#<aside[^>]*data-provenance="content-studio-provenance".*?</aside>#s', $html, $match);

    return $match[0] ?? '';
}

test('the block shows the engine text verbatim, with its markers', function () {
    $this->receive($this->provenance('scout'));
    $block = block($this->page());

    expect($block)
        ->toContain('data-body-revision="'.self::REVISION.'"')
        ->toContain('data-provenance-version="1"')
        ->toContain('data-authorship-mode="content_studio_scout"')
        ->toContain('data-review-status="automated_checks_only"')
        ->toContain('data-disclosure-fingerprint="'.sha1($this->provenance('scout')['disclosure_text']).'"')
        ->toContain('Over dit artikel')
        ->toContain(e($this->provenance('scout')['disclosure_text']))
        ->toContain('Samengesteld door')
        ->toContain('href="'.self::SCOUT_URL.'"')
        ->toContain('AI-redactieassistent van Content Studio');
});

test('the block is accessible and carries no scripts or inline styles', function () {
    $this->receive($this->provenance('scout'));
    $block = block($this->page());

    preg_match('#aria-labelledby="([^"]+)"#', $block, $labelledBy);

    expect($labelledBy[1] ?? null)->not->toBeNull()
        ->and($block)->toContain('id="'.$labelledBy[1].'"')
        ->and($block)->not->toContain('<script')
        ->and($block)->not->toContain('style=');
});

test('the block styles follow the text colour, so they work in light and dark mode', function () {
    $this->receive($this->provenance('scout'));
    $html = $this->page();

    preg_match('#\.cs-provenance \{(.*?)\}#s', $html, $rule);

    expect($rule[1] ?? '')->toContain('color: inherit')->toContain('background: transparent');
});

test('the block appears exactly once, even when a theme places it as well', function () {
    $this->receive($this->provenance('scout'));

    expect(Disclosure::count($this->page()))->toBe(1);

    // Een thema dat de component nog eens zelf zet.
    $article = ContentStudioArticle::first();
    $first = Disclosure::renderAt($article, ['bottom']);
    $second = Disclosure::renderAt($article, ['bottom']);

    expect($first)->not->toBe('')->and($second)->toBe('');
});

test('the position setting moves the block', function (string $position, string $before, string $after) {
    config(['content-studio.provenance.position' => $position]);
    $this->receive($this->provenance('person'), ['author_name' => 'Anna de Vries', 'author_experience_summary' => 'Twintig jaar fiscalist.']);

    $html = $this->page();
    $block = strpos($html, 'data-provenance="content-studio-provenance"');

    expect($block)->toBeGreaterThan(strpos($html, $before))
        ->and($block)->toBeLessThan(strpos($html, $after));
})->with([
    'top' => ['top', '<h1', 'De tekst van het artikel.'],
    'bottom' => ['bottom', 'De tekst van het artikel.', 'Over de auteur'],
    'author' => ['author', 'Twintig jaar fiscalist.', 'Volgend artikel'],
]);

test('the engine can suggest a position', function () {
    $this->receive($this->provenance('scout', ['provenance_block_position' => 'top']));
    $html = $this->page();

    expect(strpos($html, 'data-provenance="content-studio-provenance"'))->toBeLessThan(strpos($html, 'De tekst van het artikel.'));
});

test('a block the engine put in the body is replaced, not doubled', function () {
    $engineBlock = '<section data-provenance="content-studio-provenance"><p>Oude verantwoording</p></section>';
    $this->receive($this->provenance('scout'), ['body_html' => '<p>De tekst van het artikel.</p>'.$engineBlock]);

    $html = $this->page();

    expect(Disclosure::count($html))->toBe(1)
        ->and($html)->not->toContain('Oude verantwoording')
        ->and(ContentStudioArticle::first()->body_html)->toBe('<p>De tekst van het artikel.</p>');
});

test('without provenance there is no block', function () {
    $this->receive(false);

    expect(Disclosure::count($this->page()))->toBe(0);
});

test('excerpts and listings never contain the disclosure', function () {
    $article = $this->receive($this->provenance('scout'));
    Disclosure::applyBodyFallback($article);

    expect(Disclosure::count($article->body_html))->toBe(1)
        ->and($article->excerpt(500))->toBe('De tekst van het artikel.')
        ->and($article->excerpt(500))->not->toContain('Scout');

    $index = $this->renderPage('http://localhost/blog')[0];

    expect(Disclosure::count($index))->toBe(0);
});

test('scout is credited as compiler, never as a person or author bio', function () {
    // De Engine stuurt een persona als author_name; met provenance telt die niet.
    $this->receive($this->provenance('scout'), ['author_name' => 'Voice Persona', 'author_experience_summary' => 'Een stem.']);
    $html = $this->page();

    expect($html)->toContain('Samengesteld door')
        ->and($html)->not->toContain('Voice Persona')
        ->and($html)->not->toContain('Over de auteur')
        ->and($html)->not->toMatch('/"@type":"Person"[^}]*"name":"(Scout|Content Studio Redactie)"/');
});

test('a human author keeps the author bio when the names match', function () {
    $this->receive($this->provenance('person'), ['author_name' => 'Anna de Vries', 'author_experience_summary' => 'Twintig jaar fiscalist.']);
    $html = $this->page();

    expect($html)->toContain('Geschreven door')->toContain('Anna de Vries')->toContain('Over de auteur');
});

test('json-ld follows the provenance', function (string $mode, array $author) {
    $this->receive($this->provenance($mode));
    $article = collect($this->jsonLdNodes($this->page()))->firstWhere('@type', 'BlogPosting');

    unset($article['author']['@id']);

    expect($article['author'])->toBe($author)
        ->and($article['publisher']['name'])->toBe('Klant BV')
        ->and($article['publisher']['@type'])->toBe('Organization')
        ->and($article)->not->toHaveKey('contributor')
        ->and($article['mainEntityOfPage'])->not->toHaveKey('reviewedBy');
})->with([
    'scout' => ['scout', ['@type' => 'Organization', 'name' => 'Content Studio Redactie', 'alternateName' => 'Scout', 'url' => TestCase::SCOUT_URL]],
    'organization' => ['organization', ['@type' => 'Organization', 'name' => 'Klant BV', 'url' => 'https://klant.example']],
    'person' => ['person', ['@type' => 'Person', 'name' => 'Anna de Vries', 'jobTitle' => 'Fiscalist', 'url' => 'https://klant.example/team/anna']],
]);

test('contributors and reviewer appear only when they are real', function () {
    $this->receive($this->provenance('person', [
        'review_status' => 'human_reviewed',
        'reviewer' => ['name' => 'Jan Jansen', 'role_title' => 'Partner'],
        'expert_contributors' => [['name' => 'Piet Bakker', 'role_title' => 'Accountant']],
    ]));

    $article = collect($this->jsonLdNodes($this->page()))->firstWhere('@type', 'BlogPosting');

    expect($article['contributor'])->toBe([['@type' => 'Person', 'name' => 'Piet Bakker', 'jobTitle' => 'Accountant']])
        ->and($article['mainEntityOfPage']['reviewedBy'])->toBe(['@type' => 'Person', 'name' => 'Jan Jansen', 'jobTitle' => 'Partner']);
});

test('an article without provenance keeps its old author schema', function () {
    $this->receive(false, ['author_name' => 'Voice Persona']);
    $article = collect($this->jsonLdNodes($this->page()))->firstWhere('@type', 'BlogPosting');

    expect($article['author'])->toBe(['@type' => 'Person', 'name' => 'Voice Persona']);
});

test('the hub page only claims an expert review when a human reviewed', function () {
    $this->receive($this->provenance('scout'), ['content_type' => 'hub']);

    expect($this->page())->not->toContain('Expert review');

    $this->receive($this->provenance('person', [
        'review_status' => 'human_reviewed',
        'reviewer' => ['name' => 'Jan Jansen'],
    ]), ['content_type' => 'hub']);

    expect($this->page())->toContain('Expert review');
});

test('an article with invalid provenance is published without a block', function () {
    $this->receive($this->provenance('scout', ['article_revision_id' => 'kapot']));

    [$html, $status] = $this->renderPage('http://localhost/blog/btw-aangifte');

    expect($status)->toBe(200)
        ->and(Disclosure::count($html))->toBe(0)
        ->and(ContentStudioArticle::first()->provenance_state)->toBe(ContentStudioArticle::STATE_INVALID)
        ->and(ContentStudioArticle::first()->provenance)->toBeNull();
});

test('a hub without an image gets a full-width card without a broken image', function () {
    $this->receive($this->provenance('scout'), ['content_type' => 'hub', 'featured_image_url' => null]);

    $hub = ContentStudioArticle::first();
    $overview = $this->renderPage('http://localhost/blog')[0];
    preg_match('#<div class="cs-hub .*?</a>#s', $overview, $card);

    expect($hub->featured_image_url)->toBeNull()
        ->and($card[0] ?? '')->not->toBe('')
        ->and($card[0])->not->toContain('<img')
        ->and($card[0])->not->toContain('cs-hub--image')
        ->and($card[0])->not->toContain('verdiepende')
        ->and($overview)->toContain('.cs-hub {')
        ->and($this->page())->not->toContain('content_studio_images/');
});

test('an old empty image path counts as no image, also in the share image', function () {
    $this->receive($this->provenance('scout'));
    ContentStudioArticle::first()->forceFill(['featured_image_url' => 'content_studio_images/4711/.'])->save();

    $html = $this->page();

    expect(ContentStudioArticle::first()->featuredImagePath())->toBeNull()
        ->and($html)->not->toContain('content_studio_images/4711/.')
        ->and($html)->not->toContain('<img src="http://localhost/storage/content_studio_images');
});

test('a hub counts its articles from its own link list as well as hub_content_id', function () {
    // Een pillar-hub: de Engine zet geen hub_content_id, alleen de linklijst.
    $this->receive($this->provenance('scout'), ['id' => 5001, 'slug' => '5001-eerste', 'title' => 'Eerste']);
    $this->receive($this->provenance('scout'), ['id' => 5002, 'slug' => '5002-tweede', 'title' => 'Tweede']);
    // Een cluster-artikel dat via hub_content_id bij de hub hoort.
    $this->receive($this->provenance('scout'), ['id' => 5003, 'slug' => '5003-derde', 'title' => 'Derde', 'hub_content_id' => 4711]);
    // In een andere taal: telt niet.
    $this->receive($this->provenance('scout'), ['id' => 5004, 'slug' => '5004-fourth', 'title' => 'Fourth', 'locale' => 'en']);

    $this->receive($this->provenance('scout'), [
        'content_type' => 'hub',
        'body_html' => '<p>Gids.</p><!--HUB_LINKS_START--><ul><li><a href="5001-eerste">Eerste</a></li><li><a href="https://elders.example/blog/5002-tweede">Tweede</a></li><li><a href="5004-fourth">Fourth</a></li><li><a href="bestaat-niet">Weg</a></li></ul><!--HUB_LINKS_END-->',
    ]);

    $hub = ContentStudioArticle::where('content_studio_article_id', 4711)->first();

    expect($hub->hubChildren()->pluck('content_studio_article_id')->sort()->values()->all())->toBe([5001, 5002, 5003])
        ->and($this->renderPage('http://localhost/blog')[0])->toContain('3 verdiepende');
});
