<?php

namespace Shazzoo\StrategyEngine\Support\Publication;

use Shazzoo\StrategyEngine\Models\ContentStudioArticle;
use Shazzoo\StrategyEngine\Support\ArticleRoutes;
use Shazzoo\StrategyEngine\Support\Engine\ConfirmPublished;
use Shazzoo\StrategyEngine\Support\Engine\EngineClient;
use Shazzoo\StrategyEngine\Support\Provenance\Disclosure;
use Shazzoo\StrategyEngine\Support\Provenance\Provenance;
use Shazzoo\StrategyEngine\Support\Provenance\ProvenanceSchema;

/**
 * De volgorde waarin een artikel live gaat, en wat er gebeurt als dat niet
 * goed gaat.
 *
 * 1. payload valideren                     prepare()
 * 2. content en provenance opslaan         SyncArticles::processArticle → record()
 * 3. disclosure configureren               Disclosure::syncBody
 * 4. structured data voorbereiden          ArticleSeoBuilder, uit de opgeslagen provenance
 * 5. artikel publiceren of bijwerken       processArticle, in dezelfde transactie
 * 6. caches gericht legen                  finalize() → CachePurger
 * 7. publieke URL ophalen                  finalize() → PublicationVerifier
 * 8. verificaties uitvoeren                finalize() → PublicationVerifier
 * 9. resultaat naar Content Studio         report()
 *
 * Of een artikel live gaat, beslist de Engine: die levert alleen wat
 * gepubliceerd mag worden. Deze plugin houdt niets tegen en haalt niets
 * offline. Wat hij wel doet: de provenance alleen tonen als die klopt, en na
 * publicatie op de echte pagina nagaan of alles er staat. Wat hij ziet, meldt
 * hij aan de Engine, die beslist wat ermee moet.
 *
 * Kan het artikel technisch niet worden opgeslagen, dan komt er niets live:
 * tekst en provenance gaan in één transactie.
 */
final class Publication
{
    public const MAX_REVERIFY = 3;

    /** Minuten tussen een tijdelijke waarschuwing en de volgende poging. */
    public const REVERIFY_AFTER_MINUTES = 5;

    /**
     * Stap 1: de provenance uit de aanlevering valideren. Ongeldig betekent
     * hier niet "niet publiceren" maar "zonder blok publiceren en het
     * melden".
     *
     * @return array{present: bool, provenance: ?array, errors: string[], warnings: string[]}
     */
    public static function prepare(array $item): array
    {
        $check = Provenance::fromArticle($item);

        return [
            'present' => $check['present'],
            'provenance' => $check['valid'] ? $check['provenance'] : null,
            'errors' => $check['errors'],
            'warnings' => $check['warnings'],
        ];
    }

    /**
     * Stap 2: legt vast wat prepare() besloot. Hoort binnen dezelfde
     * transactie als het opslaan van de tekst te gebeuren.
     *
     */
    public static function record(ContentStudioArticle $article, array $prepared): void
    {
        if ($prepared['provenance'] !== null) {
            self::store($article, $prepared['provenance']);
        } elseif (! $prepared['present']) {
            $article->forceFill([
                'provenance_state' => $article->provenance ? $article->provenance_state : ContentStudioArticle::STATE_LEGACY,
            ]);
        } else {
            /* Ongeldig: zonder verantwoording publiceren. De vorige provenance
               hoort bij de vorige tekst, dus die gaat ook weg. */
            $article->forceFill([
                'provenance' => null,
                'provenance_version' => null,
                'body_revision' => null,
                'disclosure_hash' => null,
                'provenance_state' => ContentStudioArticle::STATE_INVALID,
                'provenance_errors' => implode(',', $prepared['errors']),
            ]);
        }
    }

    /**
     * Idempotent: dezelfde provenance nog eens schrijft niets.
     */
    private static function store(ContentStudioArticle $article, array $provenance): void
    {
        $article->forceFill([
            'provenance_state' => ContentStudioArticle::STATE_VALID,
            'provenance_errors' => null,
        ]);

        if ($article->provenance === $provenance) {
            return;
        }

        $article->forceFill([
            'provenance' => $provenance,
            'provenance_version' => (int) $provenance['version'],
            'body_revision' => $provenance['body_revision'],
            'disclosure_hash' => $provenance['disclosure_hash'],
        ]);
    }

    /**
     * Stap 6 tot 8.
     *
     * @param  array  $options  attempts, backoff, fetch, sleep (voor tests en herhaalpogingen).
     */
    public static function finalize(ContentStudioArticle $article, array $options = []): array
    {
        if (! $article->isPublic()) {
            return self::storeResult($article, PublicationVerifier::result(
                PublicationVerifier::PUBLICATION_FAILED,
                ['article_not_public'],
                self::url($article),
                (string) $article->body_revision,
            ));
        }

        if (! config('content-studio.provenance.verify', true)) {
            return self::storeResult($article, PublicationVerifier::withWarnings(
                PublicationVerifier::result(PublicationVerifier::PUBLISHED_WITH_WARNING, [], self::url($article), (string) $article->body_revision),
                ['verification_disabled'],
            ));
        }

        $result = self::verify($article, $options);
        $required = Provenance::requiresDisclosure($article->validProvenance());
        $notes = [];

        /* De native weg kwam niet op de pagina, terwijl de pagina er wel is:
           aantoonbaar. Dan, en alleen dan, de body-fallback. */
        if ($required && $result['disclosure_count'] === 0 && ($result['checks']['http_200']['ok'] ?? false)
            && Disclosure::mode($article) === Disclosure::MODE_NATIVE
            && config('content-studio.provenance.allow_body_fallback', true)
        ) {
            Disclosure::applyBodyFallback($article);
            $notes[] = 'native_rendering_unavailable_body_fallback_used';
            $result = self::verify($article, $options);

            if ($result['disclosure_count'] === 0) {
                /* Ook in de tekst komt het niet op de pagina. Dan is de
                   fallback niet de oplossing en laten we de tekst schoon. */
                Disclosure::removeBodyFallback($article);
                $notes[] = 'body_fallback_did_not_render';
            }
        }

        /* Dubbel: een oude body-fallback naast de component, of een thema dat
           het blok zelf ook plaatst. Tekst opnieuw opbouwen en nog eens
           kijken. */
        if ($result['disclosure_count'] > 1) {
            Disclosure::syncBody($article);
            $notes[] = 'duplicate_disclosure_repaired';
            $result = self::verify($article, $options);
        }

        /* Ook als de verantwoording niet op de pagina komt, blijft het
           artikel staan: de Engine krijgt provenance_failed en beslist. */
        return self::storeResult($article, PublicationVerifier::withWarnings($result, array_merge($notes, self::intakeWarnings($article))));
    }

    /**
     * Stap 9: wat de Engine hoort.
     *
     * V2 krijgt het hele resultaat bij de terugmelding. Staat het artikel
     * online, dan is dat 'published' of 'updated', ook als de verantwoording
     * niet klopt: dat staat in de verificatie, en de Engine beslist. Alleen
     * als de pagina er niet is, is het 'failed'. V1 kent alleen "staat live".
     */
    public static function report(ContentStudioArticle $article, int $engineArticleId, string $action, array $result, ?EngineClient $engine = null): bool
    {
        $engine ??= EngineClient::fromSettings();
        $ok = self::isLive($result);

        if (! $engine->isConnected()) {
            return $ok && app()->environment('production') ? app(ConfirmPublished::class)($article) : false;
        }

        $url = self::url($article);

        return $engine->acknowledge(
            $engineArticleId,
            $ok ? $action : 'failed',
            externalRef: (string) $article->id,
            url: $ok && $url !== '' ? $url : null,
            error: $ok ? null : self::errorSummary($result),
            verification: self::payload($result),
        );
    }

    /** Staat het artikel op de site, wat de verificatie verder ook vond? */
    public static function isLive(array $result): bool
    {
        return $result['publication_status'] !== PublicationVerifier::PUBLICATION_FAILED;
    }

    /** Is alles in orde, op hoogstens een waarschuwing na? */
    public static function succeeded(array $result): bool
    {
        return in_array($result['publication_status'], [PublicationVerifier::VERIFIED, PublicationVerifier::PUBLISHED_WITH_WARNING], true);
    }

    /**
     * Mag ConfirmPublished dit artikel als live melden?
     */
    public static function mayConfirm(ContentStudioArticle $article): bool
    {
        if (! $article->isPublic()) {
            return false;
        }

        return $article->verification_status !== PublicationVerifier::PUBLICATION_FAILED;
    }

    /**
     * Nog eens kijken bij artikelen waar alleen iets tijdelijks misging: een
     * cache die achterliep, of een site die zichzelf even niet bereikte.
     * Draait via de scheduler, dus zonder queue-worker.
     *
     * @return int Hoeveel artikelen opnieuw zijn bekeken.
     */
    public static function reverifyDue(): int
    {
        $due = ContentStudioArticle::query()
            ->where('verification_status', PublicationVerifier::PUBLISHED_WITH_WARNING)
            ->where('reverify_count', '<', self::MAX_REVERIFY)
            ->where('verified_at', '<=', now()->subMinutes(self::REVERIFY_AFTER_MINUTES))
            ->get()
            ->filter(fn (ContentStudioArticle $article) => self::temporary((array) $article->verification));

        foreach ($due as $article) {
            $article->persistQuietly(['reverify_count' => (int) $article->reverify_count + 1]);

            $result = self::finalize($article, ['attempts' => 2, 'backoff' => [5]]);

            if ($article->content_studio_article_id) {
                self::report($article, (int) $article->content_studio_article_id, 'updated', $result);
            }
        }

        return $due->count();
    }

    /**
     * Wat de verifier op dit artikel moet terugzien.
     */
    public static function expectations(ContentStudioArticle $article): array
    {
        return [
            'provenance' => $article->validProvenance(),
            'legacy' => $article->provenance_state === ContentStudioArticle::STATE_LEGACY,
            'url' => self::url($article),
            'date_published' => (string) ($article->generated_at ?: $article->created_at)?->toIso8601String(),
            'conflict_policy' => ProvenanceSchema::conflictPolicy(),
        ];
    }

    public static function url(ContentStudioArticle $article): string
    {
        if (! filled($article->slug)) {
            return '';
        }

        try {
            return ArticleRoutes::articleUrl((string) $article->slug, (string) ($article->locale ?: config('app.locale', 'nl')));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Wat de Engine meekrijgt.
     */
    public static function payload(array $result): array
    {
        $failed = [];

        foreach ($result['checks'] ?? [] as $key => $check) {
            if (empty($check['ok'])) {
                $failed[$key] = $check['detail'];
            }
        }

        return [
            'publication_status' => $result['publication_status'],
            'provenance_rendered' => (bool) $result['provenance_rendered'],
            'structured_data_valid' => (bool) $result['structured_data_valid'],
            'visible_author_valid' => (bool) $result['visible_author_valid'],
            'disclosure_count' => (int) $result['disclosure_count'],
            'conflicts' => array_values($result['conflicts']),
            'warnings' => array_values($result['warnings']),
            'verified_at' => (string) $result['verified_at'],
            'public_url' => (string) $result['public_url'],
            'body_revision' => (string) $result['body_revision'],
            'failed_checks' => (object) $failed,
        ];
    }

    private static function verify(ContentStudioArticle $article, array $options): array
    {
        CachePurger::purge($article);

        return PublicationVerifier::run(
            self::url($article),
            self::expectations($article),
            array_merge(['purge' => fn () => CachePurger::purge($article)], (array) config('content-studio.provenance.verifier', []), $options),
        );
    }

    /**
     * Wat er al bij de ontvangst te waarschuwen viel.
     *
     * @return string[]
     */
    private static function intakeWarnings(ContentStudioArticle $article): array
    {
        $provenance = $article->validProvenance();
        $warnings = [];

        if ($article->provenance_state === ContentStudioArticle::STATE_INVALID) {
            foreach (array_filter(explode(',', (string) $article->provenance_errors)) as $error) {
                $warnings[] = 'provenance_invalid:'.$error;
            }
        }

        if ($provenance === null) {
            return $warnings;
        }

        if ((string) ($provenance['byline']['text'] ?? '') === '') {
            $warnings[] = 'byline_missing_from_payload';
        }

        if ($provenance['authorship_mode'] === 'content_studio_scout' && ($provenance['white_label'] ?? null) === null) {
            $warnings[] = 'white_label_missing_from_payload';
        }

        return $warnings;
    }

    private static function storeResult(ContentStudioArticle $article, array $result): array
    {
        $article->persistQuietly([
            'verification' => $result,
            'verification_status' => $result['publication_status'],
            'verified_at' => now(),
            'reverify_count' => self::temporary($result) ? (int) $article->reverify_count : 0,
        ]);

        return $result;
    }

    private static function temporary(array $result): bool
    {
        foreach ((array) ($result['warnings'] ?? []) as $warning) {
            if (str_starts_with((string) $warning, 'stale_cache_served') || str_starts_with((string) $warning, 'verification_unavailable')) {
                return true;
            }
        }

        return false;
    }

    /** Max 1000 tekens, zoals de Engine toestaat. */
    private static function errorSummary(array $result): string
    {
        $parts = [$result['publication_status'], ...$result['warnings']];

        return mb_substr(implode('; ', $parts), 0, 1000);
    }
}
