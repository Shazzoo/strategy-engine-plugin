<?php

namespace Shazzoo\StrategyEngine\Support\Publication;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Shazzoo\StrategyEngine\Support\Provenance\Disclosure;
use Shazzoo\StrategyEngine\Support\Provenance\Provenance;
use Shazzoo\StrategyEngine\Support\Provenance\ProvenanceSchema;

/**
 * Kijkt naar de pagina zoals een bezoeker hem krijgt en stelt vast of daar
 * staat wat de provenance zegt.
 *
 * Niet naar de database: daar kan alles kloppen terwijl een cache de vorige
 * versie serveert, een themetemplate de component overslaat, of een layout er
 * een tweede auteur naast zet. Dit is de enige plek die dat ziet.
 *
 * evaluate() is puur - HTML erin, oordeel eruit - zodat elke regel los te
 * testen is. run() haalt de pagina op, met beperkte retries en backoff tegen
 * caches die nog niet bij zijn.
 */
final class PublicationVerifier
{
    public const VERIFIED = 'verified';

    public const PUBLISHED_WITH_WARNING = 'published_with_warning';

    public const PROVENANCE_FAILED = 'provenance_failed';

    public const PUBLICATION_FAILED = 'publication_failed';

    public const USER_AGENT = 'ContentStudioVerifier/1.0';

    /** Checks die, als ze falen, de provenance op de pagina onjuist maken. */
    public const HARD_CHECKS = [
        'disclosure_visible',
        'body_revision',
        'visible_author',
        'scout_never_person',
        'reviewer_only_when_human',
    ];

    /**
     * Checks over het Article-schema. Hard als het conflictbeleid 'block' is,
     * anders een waarschuwing. Scout als Person valt hier niet onder: dat is
     * altijd hard.
     */
    public const SCHEMA_CHECKS = [
        'structured_data_author',
        'publisher',
        'no_conflicting_article_authors',
        'human_author_is_person',
        'organization_author_is_organization',
    ];

    /** Meldingen die iets vertellen, maar geen reden zijn om niet 'verified' te melden. */
    public const NOTES = [
        'native_rendering_unavailable_body_fallback_used',
        'duplicate_disclosure_repaired',
    ];

    /**
     * Haalt de pagina op en beoordeelt hem, tot $attempts keer. Een poging
     * die faalt op iets wat een cache kan verklaren (geen 200, oude revisie,
     * geen of een dubbel blok) wordt na een pauze en een nieuwe purge
     * herhaald; de laatste met een cachebuster, zodat "de cache loopt achter"
     * en "het staat er echt niet" uit elkaar te houden zijn.
     *
     * @param  array{attempts?: int, backoff?: int[], fetch?: callable, sleep?: callable, purge?: callable}  $options
     */
    public static function run(string $url, array $expect, array $options = []): array
    {
        $attempts = max(1, (int) ($options['attempts'] ?? 3));
        $backoff = (array) ($options['backoff'] ?? [1, 3]);
        $fetch = $options['fetch'] ?? [self::class, 'fetch'];
        $sleep = $options['sleep'] ?? 'sleep';
        $purge = $options['purge'] ?? null;

        $result = null;
        $fetchError = '';
        $busted = false;
        $attempt = 0;

        for (; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                $delay = (int) ($backoff[$attempt - 1] ?? end($backoff));

                if ($delay > 0) {
                    $sleep($delay);
                }

                if (is_callable($purge)) {
                    $purge();
                }
            }

            $busted = $attempt > 0 && $attempt === $attempts - 1;
            $response = $fetch($busted ? self::cacheBusted($url) : $url, $busted);

            if (! is_array($response)) {
                $fetchError = is_string($response) ? $response : 'fetch_failed';

                continue;
            }

            $fetchError = '';
            $result = self::evaluate((string) $response['body'], (int) $response['status'], $expect);

            if (! self::retryable($result)) {
                break;
            }
        }

        if ($result === null) {
            $result = self::unreachable($expect, $fetchError);
        } elseif ($busted && $result['publication_status'] === self::VERIFIED && $attempts > 1) {
            // Alleen met cachebuster goed: de pagina klopt, de cache nog niet.
            $result['warnings'][] = 'stale_cache_served';
            $result['publication_status'] = self::PUBLISHED_WITH_WARNING;
        }

        $result['attempts'] = min($attempt + 1, $attempts);
        $result['fetch_error'] = $fetchError;

        return $result;
    }

    /**
     * De pagina ophalen zoals een bezoeker. Geen cookies, geen sessie, en een
     * eigen user agent zodat de site en zijn logs dit verzoek herkennen.
     *
     * @return array{status: int, body: string}|string
     */
    public static function fetch(string $url, bool $noCache = false): array|string
    {
        $headers = ['Accept' => 'text/html'];

        if ($noCache) {
            $headers['Cache-Control'] = 'no-cache';
            $headers['Pragma'] = 'no-cache';
        }

        try {
            $request = Http::withHeaders($headers)
                ->withUserAgent(self::USER_AGENT.' (+'.url('/').')')
                ->timeout(15)
                ->withOptions(['allow_redirects' => ['max' => 3]]);

            // Lokaal en op staging vaak een eigen certificaat.
            $verifySsl = config('content-studio.provenance.verify_ssl');

            if (! ($verifySsl === null ? app()->environment('production') : filter_var($verifySsl, FILTER_VALIDATE_BOOL))) {
                $request = $request->withoutVerifying();
            }

            $response = $request->get($url);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    /**
     * Beoordeelt een pagina.
     *
     * @param  array{provenance: ?array, legacy?: bool, url?: string, date_published?: string, conflict_policy?: string}  $expect
     */
    public static function evaluate(string $html, int $status, array $expect): array
    {
        $provenance = is_array($expect['provenance'] ?? null) ? $expect['provenance'] : null;
        $checks = [];
        $warnings = [];
        $conflicts = [];

        $checks['http_200'] = self::check($status === 200, 'HTTP '.$status);

        $dom = self::dom($html);
        $xpath = new DOMXPath($dom);

        // Blokken
        $blocks = $xpath->query('//*[@data-provenance="'.Disclosure::MARKER.'"]');
        $count = $blocks ? $blocks->length : 0;
        $block = $count > 0 ? $blocks->item(0) : null;

        // JSON-LD
        $jsonld = self::jsonld($xpath);
        $nodes = $jsonld['nodes'];
        $articles = array_values(array_filter($nodes, fn (array $node) => ProvenanceSchema::isType($node, ProvenanceSchema::ARTICLE_TYPES)));

        $checks['jsonld_valid'] = self::check($jsonld['invalid'] === 0, $jsonld['invalid'].' ongeldige JSON-LD-blokken');
        $checks['scout_never_person'] = self::check(! self::scoutPersonOnPage($nodes, $xpath), 'Scout staat als Person op de pagina');

        $authors = [];

        foreach ($articles as $article) {
            foreach (self::resolveList($article['author'] ?? null, $jsonld['by_id']) as $author) {
                $authors[self::identity($author)] = $author;
            }
        }

        $checks['no_conflicting_article_authors'] = self::check(count($authors) <= 1, count($articles).' Article(s), '.count($authors).' verschillende auteurs');

        if (count($authors) > 1) {
            $conflicts[] = 'conflicting_article_authors:'.implode('|', array_keys($authors));
        }

        $dirty = self::dirtyUrls($jsonld['raw'], $block);
        $checks['urls_clean'] = self::check($dirty === [], implode(', ', $dirty));
        $checks['dates_consistent'] = self::datesCheck($articles, $xpath, $expect);

        $revisionSeen = $block instanceof DOMElement ? $block->getAttribute('data-body-revision') : '';
        $visibleAuthorValid = false;

        if ($provenance === null) {
            /* Een Engine zonder provenance: er valt niets te verantwoorden,
               maar dat is ook geen volledig succes. */
            $warnings[] = ! empty($expect['legacy']) ? 'provenance_not_supported_by_engine' : 'provenance_missing';
            $checks['disclosure_once'] = self::check($count <= 1, $count.' blokken');
        } else {
            $required = Provenance::requiresDisclosure($provenance);
            $visible = $block instanceof DOMElement && self::isVisible($block);

            $checks['disclosure_visible'] = self::check(! $required || $visible, $count ? 'blok verborgen of leeg' : 'geen blok op de pagina');
            $checks['disclosure_once'] = self::check($required ? $count === 1 : $count <= 1, $count.' blokken');
            $checks['body_revision'] = self::check(
                ! $required || $revisionSeen === $provenance['body_revision'],
                'verwacht '.$provenance['body_revision'].', gezien '.($revisionSeen !== '' ? $revisionSeen : 'niets')
            );

            $checks['visible_author'] = self::visibleAuthorCheck($block, $provenance, $required);
            $visibleAuthorValid = $checks['visible_author']['ok'];

            $expectedAuthor = ProvenanceSchema::pieces($provenance)['author'];
            $expectedType = (string) $expectedAuthor['@type'];
            $expectedName = (string) $expectedAuthor['name'];
            $articleAuthor = $authors !== [] ? reset($authors) : null;

            if ($articles === []) {
                $warnings[] = 'no_article_schema_on_page';
            }

            $checks['structured_data_author'] = self::check(
                $articles === [] || ($articleAuthor !== null && count($authors) === 1 && self::matches($articleAuthor, $expectedType, $expectedName)),
                $articleAuthor !== null ? 'gezien '.self::identity($articleAuthor).', verwacht '.$expectedType.':'.$expectedName : 'geen auteur'
            );

            $checks['human_author_is_person'] = self::check(
                $provenance['authorship_mode'] !== 'human_person' || $articleAuthor === null || self::hasType($articleAuthor, 'Person'),
                'menselijke auteur staat niet als Person'
            );

            $checks['organization_author_is_organization'] = self::check(
                $provenance['authorship_mode'] === 'human_person' || $articleAuthor === null
                    || (self::hasType($articleAuthor, 'Organization') && ! self::hasType($articleAuthor, 'Person')),
                'organisatie-auteur staat niet als Organization'
            );

            $checks['publisher'] = self::publisherCheck($articles, $jsonld['by_id'], $provenance);
            $checks['reviewer_only_when_human'] = self::reviewerCheck($nodes, $block, $provenance);
        }

        return self::summarize($checks, $warnings, $conflicts, [
            'disclosure_count' => $count,
            'revision_seen' => $revisionSeen,
            'visible_author_valid' => $visibleAuthorValid,
            'public_url' => (string) ($expect['url'] ?? ''),
            'body_revision' => $provenance !== null ? (string) $provenance['body_revision'] : '',
            'conflict_policy' => (string) ($expect['conflict_policy'] ?? 'warn'),
            'has_provenance' => $provenance !== null,
        ]);
    }

    /**
     * Voegt waarschuwingen toe die buiten de pagina zijn vastgesteld, en
     * verlaagt 'verified' naar 'published_with_warning' als er een echte
     * waarschuwing bij zit.
     *
     * @param  string[]  $warnings
     */
    public static function withWarnings(array $result, array $warnings): array
    {
        $result['warnings'] = array_values(array_unique(array_merge($result['warnings'], $warnings)));

        if ($result['publication_status'] === self::VERIFIED && array_diff($result['warnings'], self::NOTES) !== []) {
            $result['publication_status'] = self::PUBLISHED_WITH_WARNING;
        }

        return $result;
    }

    /**
     * Een resultaat zonder pagina: geparkeerd, niet publiek, of niet op te
     * halen.
     *
     * @param  string[]  $warnings
     */
    public static function result(string $status, array $warnings, string $url = '', string $bodyRevision = ''): array
    {
        return [
            'publication_status' => $status,
            'provenance_rendered' => false,
            'structured_data_valid' => false,
            'visible_author_valid' => false,
            'disclosure_count' => 0,
            'conflicts' => [],
            'warnings' => array_values($warnings),
            'verified_at' => now()->toIso8601String(),
            'public_url' => $url,
            'body_revision' => $bodyRevision,
            'revision_seen' => '',
            'checks' => [],
        ];
    }

    private static function cacheBusted(string $url): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').'cs_verify='.substr(md5(uniqid('', true)), 0, 10);
    }

    private static function retryable(array $result): bool
    {
        if ($result['publication_status'] === self::VERIFIED) {
            return false;
        }

        foreach (['http_200', 'body_revision', 'disclosure_visible', 'disclosure_once'] as $key) {
            if (isset($result['checks'][$key]) && ! $result['checks'][$key]['ok']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Van losse checks naar één status.
     */
    private static function summarize(array $checks, array $warnings, array $conflicts, array $context): array
    {
        $failed = array_keys(array_filter($checks, fn (array $check) => ! $check['ok']));

        if (in_array('http_200', $failed, true)) {
            $status = self::PUBLICATION_FAILED;
        } elseif ($context['has_provenance'] && array_intersect($failed, self::HARD_CHECKS) !== []) {
            $status = self::PROVENANCE_FAILED;
        } elseif ($context['has_provenance'] && array_intersect($failed, self::SCHEMA_CHECKS) !== []
            && $context['conflict_policy'] === 'block'
        ) {
            /* Een Article-schema dat iets anders zegt dan de provenance: het
               beleid beslist. Standaard een waarschuwing, want de zichtbare
               verantwoording klopt dan wel. */
            $status = self::PROVENANCE_FAILED;
        } elseif ($failed !== [] || $warnings !== [] || $conflicts !== []) {
            $status = self::PUBLISHED_WITH_WARNING;
        } else {
            $status = self::VERIFIED;
        }

        foreach ($failed as $key) {
            $warnings[] = 'check_failed:'.$key;
        }

        $schemaOk = true;

        foreach (array_merge(['jsonld_valid', 'scout_never_person'], self::SCHEMA_CHECKS) as $key) {
            if (isset($checks[$key]) && ! $checks[$key]['ok']) {
                $schemaOk = false;
            }
        }

        return [
            'publication_status' => $status,
            'provenance_rendered' => $context['has_provenance'] && $context['disclosure_count'] >= 1
                && ($checks['disclosure_visible']['ok'] ?? true) && ($checks['body_revision']['ok'] ?? true),
            'structured_data_valid' => $schemaOk,
            'visible_author_valid' => (bool) $context['visible_author_valid'],
            'disclosure_count' => (int) $context['disclosure_count'],
            'conflicts' => array_values(array_unique($conflicts)),
            'warnings' => array_values(array_unique($warnings)),
            'verified_at' => now()->toIso8601String(),
            'public_url' => $context['public_url'],
            'body_revision' => $context['body_revision'],
            'revision_seen' => $context['revision_seen'],
            'checks' => $checks,
        ];
    }

    private static function unreachable(array $expect, string $error): array
    {
        // Niet kunnen kijken is niet hetzelfde als iets fout zien.
        return self::result(
            self::PUBLISHED_WITH_WARNING,
            ['verification_unavailable'.($error !== '' ? ':'.$error : '')],
            (string) ($expect['url'] ?? ''),
            (string) ($expect['provenance']['body_revision'] ?? ''),
        );
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private static function check(bool $ok, string $detail = ''): array
    {
        return ['ok' => $ok, 'detail' => $ok ? '' : $detail];
    }

    private static function dom(string $html): DOMDocument
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.($html !== '' ? $html : '<html></html>'), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    /**
     * Alle JSON-LD op de pagina, platgeslagen: @graph uitgepakt, en per @id
     * opzoekbaar.
     *
     * @return array{nodes: array[], by_id: array<string, array>, invalid: int, raw: array[]}
     */
    private static function jsonld(DOMXPath $xpath): array
    {
        $nodes = [];
        $raw = [];
        $invalid = 0;

        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $decoded = json_decode(trim($script->textContent), true);

            if (! is_array($decoded)) {
                $invalid++;

                continue;
            }

            $raw[] = $decoded;
            array_push($nodes, ...self::flatten($decoded));
        }

        $byId = [];

        foreach ($nodes as $node) {
            $id = $node['@id'] ?? null;

            // Een volledige node wint van een losse verwijzing.
            if (is_string($id) && (! isset($byId[$id]) || count($node) > count($byId[$id]))) {
                $byId[$id] = $node;
            }
        }

        return ['nodes' => $nodes, 'by_id' => $byId, 'invalid' => $invalid, 'raw' => $raw];
    }

    /**
     * @return array[]
     */
    private static function flatten(array $data): array
    {
        if (is_array($data['@graph'] ?? null)) {
            return array_values(array_filter($data['@graph'], 'is_array'));
        }

        if (array_is_list($data)) {
            $nodes = [];

            foreach ($data as $item) {
                if (is_array($item)) {
                    array_push($nodes, ...self::flatten($item));
                }
            }

            return $nodes;
        }

        return [$data];
    }

    /**
     * @return array[]
     */
    private static function resolveList(mixed $value, array $byId): array
    {
        if (! is_array($value)) {
            return [];
        }

        $resolved = [];

        foreach (array_is_list($value) ? $value : [$value] as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (! isset($item['@type']) && isset($item['@id'], $byId[$item['@id']])) {
                $item = $byId[$item['@id']];
            }

            $resolved[] = $item;
        }

        return $resolved;
    }

    /** Type en naam, als sleutel om auteurs te vergelijken. */
    private static function identity(array $node): string
    {
        $types = (array) ($node['@type'] ?? ['?']);
        sort($types);

        return implode('+', $types).':'.mb_strtolower(trim(is_string($node['name'] ?? null) ? $node['name'] : ''));
    }

    private static function matches(array $node, string $type, string $name): bool
    {
        return self::hasType($node, $type)
            && is_string($node['name'] ?? null)
            && strcasecmp(trim($node['name']), trim($name)) === 0;
    }

    private static function hasType(array $node, string $type): bool
    {
        return in_array($type, (array) ($node['@type'] ?? []), true);
    }

    /**
     * Scout als Person: in JSON-LD, en in microdata als een thema die zelf
     * aanmaakt.
     */
    private static function scoutPersonOnPage(array $nodes, DOMXPath $xpath): bool
    {
        foreach ($nodes as $node) {
            if (Provenance::containsScoutPerson($node)) {
                return true;
            }
        }

        foreach ($xpath->query('//*[@itemtype and contains(@itemtype, "schema.org/Person")]') ?: [] as $person) {
            foreach ($xpath->query('.//*[@itemprop="name"]', $person) ?: [] as $name) {
                if (Provenance::isScoutName($name->textContent)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Zichtbaar: niet hidden, niet aria-hidden, niet display:none of
     * visibility:hidden inline, op het blok zelf en zijn voorouders, en met
     * tekst.
     */
    private static function isVisible(DOMElement $block): bool
    {
        if (trim($block->textContent) === '') {
            return false;
        }

        for ($node = $block; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($node->hasAttribute('hidden') || strtolower($node->getAttribute('aria-hidden')) === 'true') {
                return false;
            }

            $style = strtolower((string) preg_replace('/\s+/', '', $node->getAttribute('style')));

            if (str_contains($style, 'display:none') || str_contains($style, 'visibility:hidden')) {
                return false;
            }
        }

        return true;
    }

    /**
     * De byline in het blok noemt wie de provenance noemt - en niets anders.
     */
    private static function visibleAuthorCheck(mixed $block, array $provenance, bool $required): array
    {
        if (! $block instanceof DOMElement) {
            return self::check(! $required, 'geen blok, dus geen zichtbare auteur');
        }

        $byline = (array) ($provenance['byline'] ?? []);
        $expected = (string) ($byline['name'] ?? '');

        if ($block->getAttribute('data-authorship-mode') !== $provenance['authorship_mode']) {
            return self::check(false, 'blok hoort bij auteursmodus '.$block->getAttribute('data-authorship-mode'));
        }

        $seen = '';
        $text = '';

        foreach ($block->getElementsByTagName('*') as $element) {
            if ($element instanceof DOMElement && $element->hasAttribute('data-provenance-author')) {
                $seen = $element->getAttribute('data-provenance-author');
                $text = $element->textContent;

                break;
            }
        }

        if ($expected === '') {
            return self::check($seen === '', 'onverwachte auteur '.$seen);
        }

        if (strcasecmp(trim($seen), trim($expected)) !== 0) {
            return self::check(false, 'verwacht '.$expected.', gezien '.$seen);
        }

        // Bij white label hoort Scout niet zichtbaar te zijn.
        if (($byline['kind'] ?? '') !== 'scout' && empty($byline['text']) && preg_match('/\bScout\b/i', $text)) {
            return self::check(false, 'Scout zichtbaar terwijl de byline niet van Scout is');
        }

        return self::check(true);
    }

    private static function publisherCheck(array $articles, array $byId, array $provenance): array
    {
        $expected = (string) ($provenance['publisher']['name'] ?? '');

        if ($articles === [] || $expected === '') {
            return self::check(true);
        }

        foreach ($articles as $article) {
            $publishers = self::resolveList($article['publisher'] ?? null, $byId);

            if ($publishers === []) {
                return self::check(false, 'Article zonder publisher');
            }

            foreach ($publishers as $publisher) {
                if (! self::matches($publisher, 'Organization', $expected)) {
                    return self::check(false, 'verwacht '.$expected.', gezien '.self::identity($publisher));
                }
            }
        }

        return self::check(true);
    }

    /**
     * Een beoordelaar staat alleen op de pagina als een mens het las.
     */
    private static function reviewerCheck(array $nodes, mixed $block, array $provenance): array
    {
        $human = $provenance['review_status'] === 'human_reviewed';

        if ($block instanceof DOMElement && $block->getAttribute('data-review-status') !== $provenance['review_status']) {
            return self::check(false, 'blok toont reviewstatus '.$block->getAttribute('data-review-status'));
        }

        $expected = is_array($provenance['reviewer'] ?? null) ? (string) $provenance['reviewer']['name'] : '';

        foreach (self::withNested($nodes) as $node) {
            if (! isset($node['reviewedBy'])) {
                continue;
            }

            if (! $human) {
                return self::check(false, 'reviewedBy zonder menselijke review');
            }

            foreach (self::resolveList($node['reviewedBy'], []) as $reviewer) {
                if ($expected === '' || ! self::matches($reviewer, 'Person', $expected)) {
                    return self::check(false, 'reviewedBy noemt '.self::identity($reviewer));
                }
            }
        }

        return self::check(true);
    }

    /**
     * De nodes en alles wat erin genest zit (mainEntityOfPage.reviewedBy).
     *
     * @return array[]
     */
    private static function withNested(array $nodes): array
    {
        $all = [];

        foreach ($nodes as $node) {
            $all[] = $node;

            foreach ($node as $value) {
                if (is_array($value) && ! array_is_list($value)) {
                    array_push($all, ...self::withNested([$value]));
                }
            }
        }

        return $all;
    }

    /**
     * URL's met Markdown of geëscapete linknotatie, in de JSON-LD en in het
     * blok.
     *
     * @return string[]
     */
    private static function dirtyUrls(array $raw, mixed $block): array
    {
        $dirty = [];

        array_walk_recursive($raw, function ($value, $key) use (&$dirty) {
            if (! is_string($value)) {
                return;
            }

            if (preg_match('#^https?://#i', $value)) {
                if (! Provenance::isCleanUrl($value)) {
                    $dirty[] = $value;
                }
            } elseif (in_array($key, ['url', '@id', 'sameAs', 'logo', 'image', 'contentUrl'], true) && Provenance::containsMarkdownLink($value)) {
                $dirty[] = $value;
            }
        });

        if ($block instanceof DOMElement) {
            foreach ($block->getElementsByTagName('a') as $link) {
                $href = $link->getAttribute('href');

                if (! Provenance::isCleanUrl($href)) {
                    $dirty[] = $href;
                }
            }

            if (Provenance::containsMarkdownLink($block->textContent)) {
                $dirty[] = 'markdown-in-disclosure';
            }
        }

        return array_values(array_unique($dirty));
    }

    /**
     * datePublished en dateModified bestaan, zijn leesbaar, staan in de goede
     * volgorde en vallen samen met het artikel en de OG-tags.
     */
    private static function datesCheck(array $articles, DOMXPath $xpath, array $expect): array
    {
        $expectedPublished = isset($expect['date_published']) && $expect['date_published'] !== '' ? strtotime((string) $expect['date_published']) : false;
        $og = $xpath->query('//meta[@property="article:published_time"]/@content');
        $ogPublished = $og && $og->length ? strtotime((string) $og->item(0)->nodeValue) : false;

        foreach ($articles as $article) {
            $published = isset($article['datePublished']) ? strtotime((string) $article['datePublished']) : false;
            $modified = isset($article['dateModified']) ? strtotime((string) $article['dateModified']) : false;

            if ($published === false) {
                return self::check(false, 'datePublished ontbreekt of is onleesbaar');
            }

            if ($modified !== false && $modified < $published) {
                return self::check(false, 'dateModified ligt voor datePublished');
            }

            // Een minuut speling: tijdzones en afronding.
            if ($expectedPublished !== false && abs($published - $expectedPublished) > 60) {
                return self::check(false, 'datePublished wijkt af van het artikel');
            }

            if ($ogPublished !== false && abs($published - $ogPublished) > 60) {
                return self::check(false, 'datePublished wijkt af van article:published_time');
            }
        }

        return self::check(true);
    }
}
