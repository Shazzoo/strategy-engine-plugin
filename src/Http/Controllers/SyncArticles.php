<?php

namespace Shazzoo\StrategyEngine\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Shazzoo\StrategyEngine\Models\ContentStudioArticle;
use Shazzoo\StrategyEngine\Models\ContentStudioSetting;
use Shazzoo\StrategyEngine\Support\ArticleImagePlaceholders;
use Shazzoo\StrategyEngine\Support\Engine\ConfirmPublished;
use Shazzoo\StrategyEngine\Support\Engine\ProjectInfo;

class SyncArticles
{
    public const SYNCABLE_STATUSES = ['approved', 'published'];

    public function __invoke(Request $request)
    {
        $setting = ContentStudioSetting::singleton();

        $project_code = $setting->cs_project_code ?? null;
        $api_key = $setting->cs_api_key ?? null;

        if (! $api_key || ! $project_code) {
            $message = 'Missing Content Studio credentials (cs_api_key and/or cs_project_code).';
            Log::error('[StrategyEngine] '.$message);

            return [
                'ok' => false,
                'message' => $message,
                'synced' => 0,
                'skipped' => 0,
                'pages' => 0,
                'errors' => [],
            ];
        }

        ProjectInfo::refresh();

        // REQUEST FROM CONTENT STUDIO ENGINE.
        $api_url = rtrim((string) config('content-studio.engine.api_url'), '/');
        // Only content the engine has not seen confirmed as published, i.e. not
        // yet on this site. ConfirmPublished flips the status afterwards, so a
        // synced article drops out of this list. Pagination carries the filter
        // forward via links.next. Met status=all vervalt het filter en komen
        // ook al bevestigde artikelen weer mee, handig om lokaal de rendering
        // van bestaande content te controleren.
        $status_filter = trim((string) $request->query('status', 'approved'));
        $url_next = "{$api_url}/projects/{$project_code}/contents";

        if ($status_filter !== '' && $status_filter !== 'all') {
            $url_next .= '?status='.urlencode($status_filter);
        }

        $stats = [
            'ok' => true,
            'message' => 'Artikelen gesynchroniseerd met Content Studio Engine.',
            'synced' => 0,
            'skipped' => 0,
            'pages' => 0,
            'errors' => [],
        ];

        $this->processResults($api_key, $url_next, $api_url, $stats);

        $stats['expected'] = ProjectInfo::syncableTotal();

        if (! empty($stats['errors'])) {
            $stats['ok'] = false;
            $stats['message'] = 'Synchronisatie afgerond met fouten.';
        }

        $processed = $stats['synced'] + $stats['skipped'];

        if ($stats['expected'] !== null && $processed < $stats['expected']) {
            $stats['ok'] = false;
            $stats['message'] = 'Synchronisatie afgerond, maar '.($stats['expected'] - $processed).' van de '.$stats['expected'].' artikelen zijn niet verwerkt.';

            Log::warning('[StrategyEngine] Minder artikelen verwerkt dan de Engine aanbiedt', [
                'expected' => $stats['expected'],
                'synced' => $stats['synced'],
                'skipped' => $stats['skipped'],
            ]);
        }

        return $stats;
    }

    private function processResults($api_key, $url_next, string $api_url, array &$stats)
    {
        $stats['pages']++;
        $requestUrl = $this->normalizeEngineUrl($url_next, $api_url);

        Log::info('[StrategyEngine] Start synchroniseren met Content Studio Engine', [
            'project_code' => $requestUrl,
        ]);
        // PERFORM THE REQUEST TO CONTENT STUDIO ENGINE.
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$api_key,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->timeout(20)->get($requestUrl);
        } catch (\Throwable $e) {
            $error = [
                'status' => 'connection_error',
                'response' => $e->getMessage(),
                'url' => $requestUrl,
            ];

            Log::error('[StrategyEngine] Fout bij synchroniseren met Content Studio Engine', $error);
            $stats['errors'][] = $error;

            return;
        }

        $data = $response->json('data');        // <-- jouw array met items
        $url_next = $response->json('links.next');  // <-- volgende pagina URL (of null)
        $total = $response->json('meta.total');  // <-- totaal aantal items

        if ($response->failed()) {
            $error = [
                'status' => $response->status(),
                'response' => $response->body(),
                'url' => $requestUrl,
            ];

            Log::error('[StrategyEngine] Fout bij synchroniseren met Content Studio Engine', [
                'status' => $error['status'],
                'response' => $error['response'],
                'url' => $error['url'],
            ]);

            $stats['errors'][] = $error;

            return;
        }

        // PROCESS DATA RESULTS.
        foreach ($data as $item) {
            if (! $this->shouldSync($item)) {
                $stats['skipped']++;

                continue;
            }

            if ($this->processArticle($item)) {
                $stats['synced']++;
            }
        }

        if ($url_next) {
            // Recursief de volgende pagina ophalen
            $this->processResults($api_key, $url_next, $api_url, $stats);
        }
    }

    private function normalizeEngineUrl(string $url, string $api_url): string
    {
        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return rtrim($api_url, '/').'/'.ltrim($url, '/');
    }

    private function shouldSync($item): bool
    {
        $status = strtolower(trim((string) ($item['status'] ?? '')));

        if ($status === '') {
            Log::warning('[StrategyEngine] Artikel zonder status overgeslagen', [
                'content_studio_article_id' => $item['id'] ?? null,
            ]);

            return false;
        }

        return in_array($status, self::SYNCABLE_STATUSES, true);
    }

    private function processArticle($item)
    {
        DB::beginTransaction();

        try {
            // Hier kun je elk item verwerken, bijvoorbeeld opslaan in de database
            $title = $item['title'] ?? 'No title';
            $id = $item['id'] ?? null;
            $excerpt = $item['excerpt'] ?? null;
            $body_html = $item['body_html'] ?? null;
            if (is_string($body_html)) {
                $body_html = ArticleImagePlaceholders::replace(
                    $body_html,
                    $item,
                    fn (string $imageUrl, ?string $placeholderId, array $entry): string => $this->storePlaceholderImage(
                        $imageUrl,
                        $id,
                        $placeholderId,
                        is_string($entry['type'] ?? null) ? $entry['type'] : 'image'
                    )
                );
            }
            $featured_image_url = $item['featured_image_url'] ?? null;
            $featured_image_alt = $item['featured_image_alt'] ?? null;
            $image_extension = pathinfo($featured_image_url, PATHINFO_EXTENSION) ?? 'jpg';
            $image_filename = pathinfo($featured_image_url, PATHINFO_FILENAME) ?? $id;
            $meta_description = $item['meta_description'] ?? null;
            $seo_title = $item['seo_title'] ?? null;
            $og_title = $item['og_title'] ?? null;
            $og_description = $item['og_description'] ?? null;
            $twitter_title = $item['twitter_title'] ?? null;
            $twitter_description = $item['twitter_description'] ?? null;
            $slug = $item['slug'] ?? null;
            $locale = str_replace('_', '-', strtolower(trim((string) ($item['locale'] ?? ''))));
            if ($locale === '') {
                $locale = config('app.locale', 'nl');
            }
            $locale = substr($locale, 0, 10);

            if (! filled($slug)) {
                $baseSlug = Str::slug((string) $title);
                if ($baseSlug === '') {
                    $baseSlug = 'article';
                }

                $slug = $baseSlug.'-'.$id;
            }
            $cta = $item['cta'] ?? null;
            $primary_keyword = $item['primary_keyword'] ?? null;
            $clusters = $item['clusters'] ?? null;
            $funnel_stage = $item['funnel_stage'] ?? null;
            $intent = $item['intent'] ?? null;
            $angle = $item['angle'] ?? null;
            $source_month = $item['source_month'] ?? null;
            $generated_at = $item['generated_at'] ?? null;
            $planned_at = $item['planned_at'] ?? null;
            $content_type = $item['content_type'] ?? null;
            $cluster_key = $item['cluster_key'] ?? null;
            $hub_content_id = $item['hub_content_id'] ?? null;

            $author_name = $item['author_name'] ?? null;
            $author_role_title = $item['author_role_title'] ?? null;
            $author_experience_label = $item['author_experience_label'] ?? null;
            $author_experience_summary = $item['author_experience_summary'] ?? null;
            $author_article_relevance = $item['author_article_relevance'] ?? null;
            $author_boundary_note = $item['author_boundary_note'] ?? null;

            // UPLOAD PATH.
            $upload_path = "content_studio_images/{$id}/{$image_filename}.{$image_extension}";

            // STORE THE IMAGE.
            // CHECK IF IMAGE PATH IS CORRECT.
            if ($featured_image_url && filter_var($featured_image_url, FILTER_VALIDATE_URL)) {
                Log::info('[StrategyEngine] Valid featured image URL: '.$featured_image_url);
            } else {
                Log::warning('[StrategyEngine] Invalid featured image URL: '.$featured_image_url);
                $featured_image_url = null; // Set to null if invalid
            }
            // Het pad komt uit de bestandsnaam van de bron-URL, dus een
            // bestaand bestand betekent dat deze afbeelding er al staat. Een
            // vervangen afbeelding krijgt een andere naam en wordt wel gehaald.
            if ($featured_image_url && Storage::disk('public')->missing($upload_path)) {
                Storage::disk('public')->put($upload_path, file_get_contents($featured_image_url));
            }

            $article = ContentStudioArticle::updateOrCreate(
                [
                    'content_studio_article_id' => $id,
                    'locale' => $locale,
                ],
                [
                    'locale' => $locale,
                    'title' => $title,
                    'excerpt' => $excerpt,
                    'body_html' => $body_html,
                    'featured_image_url' => $upload_path,
                    'featured_image_alt' => $featured_image_alt,
                    'meta_description' => $meta_description,
                    'seo_title' => $seo_title,
                    'og_title' => $og_title,
                    'og_description' => $og_description,
                    'twitter_title' => $twitter_title,
                    'twitter_description' => $twitter_description,
                    'slug' => $slug,
                    'cta' => $cta,
                    'primary_keyword' => $primary_keyword,
                    'cluster' => $clusters,
                    'funnel_stage' => $funnel_stage,
                    'intent' => $intent,
                    'angle' => $angle,
                    'source_month' => $source_month,
                    'generated_at' => $generated_at,
                    'planned_at' => $planned_at,
                    'content_type' => $content_type,
                    'cluster_key' => $cluster_key,
                    'hub_content_id' => $hub_content_id,
                    'author_name' => $author_name,
                    'author_role_title' => $author_role_title,
                    'author_experience_label' => $author_experience_label,
                    'author_experience_summary' => $author_experience_summary,
                    'author_article_relevance' => $author_article_relevance,
                    'author_boundary_note' => $author_boundary_note,
                ]
            );

            DB::commit();

            if (app()->environment('production')) {
                app(ConfirmPublished::class)($article);
            }

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[StrategyEngine] Fout bij verwerken artikel: '.$e->getLine().' ('.$e->getMessage().')', [
                'item' => $item,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function storePlaceholderImage(string $imageUrl, mixed $contentId, ?string $placeholderId, string $type = 'image'): string
    {
        if (! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            Log::warning('[StrategyEngine] Invalid placeholder image URL: '.$imageUrl);

            return $imageUrl;
        }

        $directory = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($contentId ?: 'unknown'));
        // De bron-URL zit in de bestandsnaam, zodat een vervangen afbeelding op
        // dezelfde placeholder een nieuw pad krijgt en de controle hieronder de
        // oude versie niet laat staan.
        $urlHash = substr(sha1($imageUrl), 0, 12);
        $filename = $placeholderId
            ? preg_replace('/[^A-Za-z0-9_-]/', '-', $placeholderId).'-'.$urlHash
            : $urlHash;
        $folder = preg_replace('/[^a-z0-9-]/', '', strtolower($type)) ?: 'image';
        $extension = $this->extensionFromUrl($imageUrl);

        // Staat het bestand er al, dan hoeft een volgende sync het niet opnieuw
        // op te halen. Zonder bruikbare extensie in de URL is het Content-Type
        // uit de response nodig en valt er nog niets te controleren.
        if ($extension !== null) {
            $path = "content_studio_images/{$directory}/{$folder}s/{$filename}.{$extension}";

            if (Storage::disk('public')->exists($path)) {
                return $this->publicStorageUrl($path);
            }
        }

        try {
            $response = Http::timeout(20)->get($imageUrl);
        } catch (\Throwable $e) {
            Log::warning('[StrategyEngine] Placeholder image download failed', [
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);

            return $imageUrl;
        }

        if ($response->failed()) {
            Log::warning('[StrategyEngine] Placeholder image download returned an error', [
                'url' => $imageUrl,
                'status' => $response->status(),
            ]);

            return $imageUrl;
        }

        $extension ??= $this->extensionFromContentType($response->header('Content-Type'));
        $path = "content_studio_images/{$directory}/{$folder}s/{$filename}.{$extension}";

        Storage::disk('public')->put($path, $response->body());

        return $this->publicStorageUrl($path);
    }

    private function publicStorageUrl(string $path): string
    {
        return preg_replace('#(?<!:)//+#', '/', Storage::disk('public')->url($path)) ?? Storage::disk('public')->url($path);
    }

    private function extensionFromUrl(string $imageUrl): ?string
    {
        $extension = strtolower((string) pathinfo((string) parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION));

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
            return $extension === 'jpeg' ? 'jpg' : $extension;
        }

        return null;
    }

    private function extensionFromContentType(?string $contentType): string
    {
        $contentType = strtolower((string) $contentType);

        if (str_contains($contentType, 'image/jpeg')) {
            return 'jpg';
        }

        if (str_contains($contentType, 'image/png')) {
            return 'png';
        }

        if (str_contains($contentType, 'image/gif')) {
            return 'gif';
        }

        if (str_contains($contentType, 'image/webp')) {
            return 'webp';
        }

        return 'svg';
    }
}
