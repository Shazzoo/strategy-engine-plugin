<?php

namespace Shazzoo\StrategyEngine\Support\Seo;

/**
 * De hreflang-regels van een pagina, in de vorm die Core verwacht:
 * ['locale' => 'nl', 'url' => 'https://...'].
 *
 * Dezelfde afspraken als Core voor gewone pagina's hanteert, want een
 * artikel hoort in de broncode van een site niet anders te werken dan een
 * pagina:
 *
 * - de eigen taal staat er ook bij, want een hreflang-set hoort naar
 *   zichzelf te verwijzen;
 * - x-default alleen als er echt iets te kiezen valt. Bij één taal is het
 *   ruis, en Google leest het dan als een keuze die er niet is;
 * - en alleen talen die de site ook echt voert. De Engine schrijft in de
 *   talen van het project, en dat zijn er soms meer dan de site toont.
 */
final class HreflangAlternates
{
    /**
     * @param  array<int, array{locale: ?string, url: ?string}>  $candidates
     * @param  array<int, string>  $allowedLocales  Leeg betekent: niets tegenhouden.
     * @return array<int, array{locale: string, url: string}>
     */
    public static function build(array $candidates, ?string $defaultLocale = null, array $allowedLocales = []): array
    {
        $alternates = [];

        foreach ($candidates as $candidate) {
            $locale = trim((string) ($candidate['locale'] ?? ''));
            $url = trim((string) ($candidate['url'] ?? ''));

            if ($locale === '' || $url === '') {
                continue;
            }

            if ($allowedLocales !== [] && ! in_array($locale, $allowedLocales, true)) {
                continue;
            }

            /* Eén regel per taal. Komt dezelfde taal twee keer voorbij - twee
               vertalingen met hetzelfde cluster, of de eigen taal die ook in
               de lijst zit - dan wint de eerste, want dat is het artikel waar
               de bezoeker nu staat. */
            if (isset($alternates[$locale])) {
                continue;
            }

            $alternates[$locale] = ['locale' => $locale, 'url' => $url];
        }

        if (count($alternates) <= 1) {
            return array_values($alternates);
        }

        $default = $defaultLocale !== null ? trim($defaultLocale) : '';

        if ($default !== '' && isset($alternates[$default])) {
            $alternates['x-default'] = [
                'locale' => 'x-default',
                'url' => $alternates[$default]['url'],
            ];
        }

        return array_values($alternates);
    }
}
