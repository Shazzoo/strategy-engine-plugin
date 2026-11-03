<?php

namespace Shazzoo\StrategyEngine\Support\Provenance;

use Shazzoo\StrategyEngine\Models\ContentStudioArticle;

/**
 * Het blok "Over dit artikel": wie het schreef en hoe het tot stand kwam.
 *
 * Native, als Blade-component in de artikelpagina, en niet in de tekst van
 * het artikel. Zo vervangt een nieuwe provenance de oude vanzelf, staat er
 * nooit een verouderde versie in de database, en blijft het uit excerpts,
 * kaarten en alles wat de tekst hergebruikt.
 *
 * Alleen als de verificatie aantoont dat de native weg op deze site niet op
 * de pagina komt - een themetemplate of een eerder gepubliceerde view die de
 * component niet kent - gaat het blok voor dat ene artikel in de tekst,
 * tussen markers, zodat opnieuw plaatsen een vervanging is en geen tweede
 * blok.
 *
 * De tekst komt letterlijk uit de Engine. Dit blok verzint niets over auteur
 * of beoordeling; het toont alleen wat de Engine vaststelde.
 */
final class Disclosure
{
    /** Dezelfde marker als de Engine (DisclosureBlockRenderer::MARKER). */
    public const MARKER = 'content-studio-provenance';

    public const MODE_NATIVE = 'native';

    public const MODE_BODY = 'body';

    public const BODY_START = '<!-- content-studio-provenance:start -->';

    public const BODY_END = '<!-- content-studio-provenance:end -->';

    /** Request-attribuut met de artikelen die hun blok al kregen. */
    private const RENDERED = 'content_studio_provenance_rendered';

    /**
     * Het blok voor een plek in de pagina, of ''. Eén keer per artikel per
     * verzoek: een thema dat de component zelf ook plaatst, krijgt geen
     * tweede.
     *
     * @param  string[]  $positions  De posities die deze plek bedient.
     */
    public static function renderAt(ContentStudioArticle $article, array $positions): string
    {
        if (self::mode($article) !== self::MODE_NATIVE || ! in_array(self::position($article), $positions, true)) {
            return '';
        }

        $rendered = (array) request()->attributes->get(self::RENDERED, []);

        if (isset($rendered[$article->getKey()])) {
            return '';
        }

        $html = self::render($article);

        if ($html !== '') {
            $rendered[$article->getKey()] = true;
            request()->attributes->set(self::RENDERED, $rendered);
        }

        return $html;
    }

    /**
     * Het blok, of '' als dit artikel er geen heeft.
     */
    public static function render(ContentStudioArticle $article): string
    {
        $provenance = $article->validProvenance();

        if ($provenance === null) {
            return '';
        }

        return self::renderProvenance($provenance, (string) ($article->locale ?: app()->getLocale()));
    }

    public static function renderProvenance(array $provenance, string $locale): string
    {
        $byline = (array) ($provenance['byline'] ?? []);
        $text = trim((string) ($provenance['disclosure_text'] ?? ''));

        if ($text === '' && empty($byline['name'])) {
            return '';
        }

        $kind = (string) ($byline['kind'] ?? '');

        return trim(view('strategy-engine::partials.provenance', [
            'marker' => self::MARKER,
            'headingId' => 'content-studio-provenance-'.substr((string) $provenance['body_revision'], 0, 8),
            'title' => __('strategy-engine::content_studio.about_this_article', [], $locale),
            'version' => (int) $provenance['version'],
            'bodyRevision' => (string) $provenance['body_revision'],
            'fingerprint' => (string) $provenance['disclosure_hash'],
            'mode' => (string) $provenance['authorship_mode'],
            'reviewStatus' => (string) $provenance['review_status'],
            'bylineText' => (string) ($byline['text'] ?? ''),
            'bylinePrefix' => __('strategy-engine::content_studio.'.($kind === 'scout' ? 'compiled_by' : 'written_by'), [], $locale),
            'bylineName' => (string) ($byline['name'] ?? ''),
            'bylineRole' => (string) ($byline['role'] ?? ''),
            'bylineUrl' => Provenance::isCleanUrl($byline['url'] ?? '') ? (string) $byline['url'] : '',
            'bylineKind' => $kind,
            'disclosureText' => $text,
        ])->render());
    }

    /**
     * Haalt elk provenanceblok weg: de body-fallback en een blok dat de
     * Engine zelf in de tekst zette.
     */
    public static function strip(?string $html): string
    {
        $html = (string) $html;

        if (! str_contains($html, self::MARKER)) {
            return $html;
        }

        $html = (string) preg_replace(
            '/\s*'.preg_quote(self::BODY_START, '/').'.*?'.preg_quote(self::BODY_END, '/').'/s',
            '',
            $html
        );

        return (string) preg_replace(
            '/\s*<(section|aside)\b[^>]*data-provenance="'.preg_quote(self::MARKER, '/').'"[^>]*>.*?<\/\1>/is',
            '',
            $html
        );
    }

    /** Hoe vaak het blok in een stuk HTML staat. */
    public static function count(?string $html): int
    {
        return (int) preg_match_all('/data-provenance="'.preg_quote(self::MARKER, '/').'"/', (string) $html);
    }

    /**
     * native, tenzij de site of dit artikel aantoonbaar de body-fallback
     * nodig heeft.
     */
    public static function mode(ContentStudioArticle $article): string
    {
        if (config('content-studio.provenance.rendering') === self::MODE_BODY) {
            return self::MODE_BODY;
        }

        return $article->disclosure_mode === self::MODE_BODY ? self::MODE_BODY : self::MODE_NATIVE;
    }

    /**
     * author, top of bottom: de instelling van de site, anders wat de Engine
     * voorstelt, anders onderaan.
     */
    public static function position(ContentStudioArticle $article): string
    {
        $setting = (string) config('content-studio.provenance.position');

        if (in_array($setting, Provenance::POSITIONS, true)) {
            return $setting;
        }

        $position = (string) ($article->validProvenance()['position'] ?? '');

        return in_array($position, Provenance::POSITIONS, true) ? $position : 'bottom';
    }

    /**
     * Zet het blok in de tekst van het artikel, voor een site waar de
     * component het niet op de pagina krijgt. Eerst alles wat er stond weg,
     * dus nooit twee blokken en nooit een oude versie.
     */
    public static function applyBodyFallback(ContentStudioArticle $article): void
    {
        $body = self::strip($article->body_html);
        $html = self::render($article);

        if ($html !== '') {
            $block = self::BODY_START.$html.self::BODY_END;
            $body = self::position($article) === 'top' ? $block."\n".$body : rtrim($body)."\n".$block;
        }

        $article->forceFill(['body_html' => $body, 'disclosure_mode' => self::MODE_BODY])->save();
    }

    /** Haalt een body-fallback weg en gaat terug naar native. */
    public static function removeBodyFallback(ContentStudioArticle $article): void
    {
        $article->forceFill(['body_html' => self::strip($article->body_html), 'disclosure_mode' => null])->save();
    }

    /**
     * Brengt de tekst in lijn met de modus: native heeft geen blok in de
     * tekst, body precies één, met de provenance van nu.
     */
    public static function syncBody(ContentStudioArticle $article): void
    {
        if (! \Shazzoo\StrategyEngine\Support\Publication\Publication::installed()) {
            return;
        }

        if (self::mode($article) === self::MODE_BODY) {
            self::applyBodyFallback($article);
        } elseif (self::count($article->body_html) > 0 || $article->disclosure_mode !== null) {
            self::removeBodyFallback($article);
        }
    }
}
