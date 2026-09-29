<?php

namespace Shazzoo\StrategyEngine\Support;

use Shazzoo\StrategyEngine\Models\ContentStudioSetting;

final class ArticleRoutes
{
    public static function indexPath(?string $locale = null, ?string $prefix = null): string
    {
        $segments = array_filter([
            self::usesLocalizedRoutes() && ! self::isHiddenDefaultLocale($locale) ? self::normalizeSegment($locale) : null,
            self::prefix($prefix, $locale ?? app()->getLocale()),
        ]);

        return '/'.implode('/', $segments);
    }

    public static function articlePath(string $slug, ?string $locale = null, ?string $prefix = null): string
    {
        return self::indexPath($locale, $prefix).'/'.self::normalizeSegment($slug);
    }

    public static function indexUrl(?string $locale = null, ?string $prefix = null): string
    {
        return url(self::indexPath($locale, $prefix));
    }

    public static function articleUrl(string $slug, ?string $locale = null, ?string $prefix = null): string
    {
        return url(self::articlePath($slug, $locale, $prefix));
    }

    public static function usesLocalizedRoutes(): bool
    {
        return function_exists('cms_is_multilang') && cms_is_multilang();
    }

    /**
     * Core kan de standaardtaal zonder taalcode in de URL laten staan; dan
     * krijgen de artikelen in die taal ook geen taalcode.
     */
    private static function isHiddenDefaultLocale(?string $locale): bool
    {
        $runtime = function_exists('cms_runtime') ? cms_runtime() : [];

        return (bool) ($runtime['hide_default_locale'] ?? false)
            && ($locale ?? app()->getLocale()) === ($runtime['default_lang'] ?? null);
    }

    /**
     * Het voorvoegsel van de artikelen. Een taal met een eigen voorvoegsel in
     * content-studio.route_prefixes (bijvoorbeeld 'en' => 'knowledge-base')
     * krijgt dat; anders het voorvoegsel uit de instellingen.
     */
    public static function prefix(?string $prefix = null, ?string $locale = null): string
    {
        $localePrefix = $locale !== null ? self::localePrefixes()[$locale] ?? null : null;

        if (filled($localePrefix)) {
            return self::normalizeSegment($localePrefix);
        }

        $prefix = $prefix ?? self::settingPrefix();

        return self::normalizeSegment($prefix) ?: 'blog';
    }

    /**
     * Elk voorvoegsel waaronder artikelen kunnen staan: dat uit de
     * instellingen en die per taal.
     *
     * @return array<int, string>
     */
    public static function prefixes(): array
    {
        return array_values(array_unique(array_filter([
            self::prefix(),
            ...array_map(self::normalizeSegment(...), self::localePrefixes()),
        ])));
    }

    /**
     * @return array<string, string>
     */
    public static function localePrefixes(): array
    {
        return array_filter((array) config('content-studio.route_prefixes', []), 'filled');
    }

    private static function settingPrefix(): string
    {
        return trim((string) (ContentStudioSetting::singleton()->route_prefix ?: 'blog'), '/');
    }

    private static function normalizeSegment(?string $segment): string
    {
        return trim((string) $segment, '/');
    }
}
