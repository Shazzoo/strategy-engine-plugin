<?php

namespace Shazzoo\StrategyEngine\Support\Engine;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Shazzoo\StrategyEngine\Models\ContentStudioSetting;

/**
 * Alles wat deze site de engine vraagt, op één plek.
 *
 * De sync key en de client id staan al in de instellingen; hier worden ze
 * gebruikt. Mislukt een aanroep, dan is dat een waarschuwing en geen
 * uitzondering: een site die even niet kan praten moet gewoon blijven
 * draaien, en de dagelijkse inhaalronde haalt op wat bleef liggen.
 *
 * Van de laatste mislukking blijven de status en het antwoord bewaard, want
 * dat is precies wat iemand in de instellingen wil lezen: niet "het lukte
 * niet", maar waarom niet.
 */
final class EngineClient
{
    private ?int $lastStatus = null;

    private ?string $lastMessage = null;

    public function __construct(
        private readonly ContentStudioSetting $setting,
    ) {}

    public static function fromSettings(): self
    {
        return new self(ContentStudioSetting::singleton());
    }

    public function isConfigured(): bool
    {
        return filled($this->setting->cs_api_key) && filled($this->setting->cs_project_code);
    }

    /** Of deze site al bij de engine staat ingeschreven voor V2. */
    public function isConnected(): bool
    {
        return $this->isConfigured() && filled($this->setting->cs_webhook_secret);
    }

    public function secret(): string
    {
        return (string) $this->setting->cs_webhook_secret;
    }

    public function cursor(): ?string
    {
        return $this->setting->cs_sync_cursor ?: null;
    }

    public function rememberCursor(?string $cursor): void
    {
        if ($cursor) {
            $this->setting->forceFill(['cs_sync_cursor' => $cursor])->save();
        }
    }

    public function lastStatus(): ?int
    {
        return $this->lastStatus;
    }

    public function lastMessage(): ?string
    {
        return $this->lastMessage;
    }

    /**
     * Meldt deze site aan en bewaart het geheim dat de engine teruggeeft.
     * Dat geheim komt één keer; daarna is opnieuw aanmelden de enige weg.
     *
     * Het oude geheim blijft staan als dit mislukt. De engine kan dan nog
     * met het vorige geheim bellen, en die aanroepen moeten hier herkend
     * blijven worden.
     */
    public function register(string $webhookUrl): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $response = $this->call('post', $this->v1("/projects/{$this->code()}/connector"), [
            'cms' => 'shazzoo',
            'webhook_url' => $webhookUrl,
        ]);

        if (! $response || ! filled($response['secret'] ?? null)) {
            return false;
        }

        $this->setting->forceFill(['cs_webhook_secret' => $response['secret']])->save();

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function article(int $articleId): ?array
    {
        $response = $this->call('get', $this->v2("/projects/{$this->code()}/articles/{$articleId}"));

        return $response['data'] ?? null;
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, next_cursor: ?string, has_more: bool}|null
     */
    public function changes(?string $cursor, int $limit = 50): ?array
    {
        $query = array_filter(['cursor' => $cursor, 'limit' => $limit]);

        $response = $this->call('get', $this->v2("/projects/{$this->code()}/sync/changes?".http_build_query($query)));

        if ($response === null) {
            return null;
        }

        return [
            'data' => $response['data'] ?? [],
            'next_cursor' => $response['next_cursor'] ?? null,
            'has_more' => (bool) ($response['has_more'] ?? false),
        ];
    }

    public function acknowledge(int $articleId, string $action, ?string $externalRef = null, ?string $url = null, ?string $error = null): bool
    {
        $response = $this->call('post', $this->v2("/projects/{$this->code()}/sync/acknowledgements"), array_filter([
            'article_id' => $articleId,
            'action' => $action,
            'external_ref' => $externalRef,
            'url' => $url,
            'error' => $error,
        ], fn ($value) => $value !== null));

        return $response !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function call(string $method, string $url, array $payload = []): ?array
    {
        $this->lastStatus = null;
        $this->lastMessage = null;

        try {
            $request = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->setting->cs_api_key,
                'Accept' => 'application/json',
            ])->timeout(20);

            $response = $method === 'post'
                ? $request->post($url, $payload)
                : $request->get($url);
        } catch (\Throwable $e) {
            $this->lastMessage = $e->getMessage();

            Log::warning('[StrategyEngine] Engine niet bereikbaar: '.$e->getMessage(), ['url' => $url]);

            return null;
        }

        if (! $response->successful()) {
            $this->lastStatus = $response->status();
            $this->lastMessage = (string) ($response->json('message') ?: mb_substr($response->body(), 0, 300));

            Log::warning('[StrategyEngine] Engine antwoordde met een fout.', [
                'url' => $url,
                'status' => $this->lastStatus,
                'body' => $this->lastMessage,
            ]);

            return null;
        }

        return $response->json() ?? [];
    }

    private function code(): string
    {
        return (string) $this->setting->cs_project_code;
    }

    private function v1(string $path): string
    {
        return rtrim((string) config('content-studio.engine.api_url'), '/').$path;
    }

    private function v2(string $path): string
    {
        return rtrim((string) config('content-studio.engine.api_v2_url'), '/').$path;
    }
}
