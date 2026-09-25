<?php

namespace Shazzoo\StrategyEngine\Support;

use Shazzoo\StrategyEngine\Models\ContentStudioSetting;

final class ArticleRoutes
{
    public static function indexPath(?string $locale = null, ?string $prefix = null): string
    {
        $segments = array_filter([
            self::usesLocalizedRoutes() && ! self::isHiddenDefaultLocale($locale) ? self::normalizeSegment($locale) : null,
            self::prefix($prefix),
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

    public static function prefix(?string $prefix = null): string
    {
        $prefix = $prefix ?? self::settingPrefix();

        return self::normalizeSegment($prefix) ?: 'blog';
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
