<?php

namespace Shazzoo\StrategyEngine\Support\Engine;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Shazzoo\StrategyEngine\Models\ContentStudioArticle;
use Shazzoo\StrategyEngine\Models\ContentStudioSetting;
use Shazzoo\StrategyEngine\Support\ArticleRoutes;
use Shazzoo\StrategyEngine\Support\Publication\Publication;

class ConfirmPublished
{
    public static function apiUrl(): string
    {
        return rtrim((string) config('content-studio.engine.api_url'), '/');
    }

    public function __invoke(ContentStudioArticle $article): bool
    {
        try {
            return $this->confirm($article);
        } catch (\Throwable $e) {
            Log::warning('[StrategyEngine] Kon publicatie niet bevestigen bij Content Studio Engine: '.$e->getMessage(), [
                'article_id' => $article->id,
                'content_studio_article_id' => $article->content_studio_article_id,
            ]);

            return false;
        }
    }

    private function confirm(ContentStudioArticle $article): bool
    {
        $content_id = $article->content_studio_article_id;

        if (! $content_id) {
            return false;
        }

        /* Niet als live melden wat tegengehouden is, offline is gehaald, of
           waarvan de verificatie zag dat de verantwoording niet klopt. */
        if (! Publication::mayConfirm($article)) {
            return false;
        }

        $setting = ContentStudioSetting::singleton();

        $api_key = $setting->cs_api_key ?? null;
        $project_code = $setting->cs_project_code ?? null;

        if (! $api_key || ! $project_code) {
            return false;
        }

        $published_url = $this->publishedUrl($article);

        if ($article->published_confirmed_at && $article->published_url === $published_url) {
            return false;
        }

        $url = self::apiUrl()."/projects/{$project_code}/contents/{$content_id}/confirm-published";

        $payload = [];
        if ($published_url !== null) {
            $payload['published_url'] = $published_url;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$api_key,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->timeout(10)->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('[StrategyEngine] Fout bij bevestigen publicatie bij Content Studio Engine', [
                'status' => 'connection_error',
                'response' => $e->getMessage(),
                'url' => $url,
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('[StrategyEngine] Fout bij bevestigen publicatie bij Content Studio Engine', [
                'status' => $response->status(),
                'response' => $response->body(),
                'url' => $url,
            ]);

            return false;
        }

        $article->forceFill([
            'published_url' => $published_url,
            'published_confirmed_at' => now(),
        ])->save();

        Log::info('[StrategyEngine] Publicatie bevestigd bij Content Studio Engine', [
            'content_studio_article_id' => $content_id,
            'published_url' => $published_url,
        ]);

        return true;
    }

    private function publishedUrl(ContentStudioArticle $article): ?string
    {
        if (! filled($article->slug)) {
            return null;
        }

        $published_url = ArticleRoutes::articleUrl(
            slug: $article->slug,
            locale: $article->locale ?: config('app.locale', 'nl'),
        );

        // De Engine slaat published_url op als string(255).
        return strlen($published_url) > 255 ? null : $published_url;
    }
}
