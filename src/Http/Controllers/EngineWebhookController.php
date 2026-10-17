<?php

namespace Shazzoo\StrategyEngine\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Shazzoo\StrategyEngine\Support\Engine\EngineClient;
use Shazzoo\StrategyEngine\Support\Engine\SyncFromEngine;
use Shazzoo\StrategyEngine\Support\Engine\WebhookSignature;

/**
 * Waar de engine aanklopt.
 *
 * De aanroep draagt alleen een id: wat er in het artikel staat haalt deze
 * site zelf op, met zijn eigen sleutel. Wie de webhook onderschept, weet
 * dus niets dat hij niet toch al kon opvragen.
 *
 * Het werk gebeurt hier meteen en niet via een queue. Dat is één ophaal,
 * één opslag en één terugmelding - ruim binnen een seconde - en het
 * scheelt deze site de aanname dat er een queue-worker draait.
 */
class EngineWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $engine = EngineClient::fromSettings();

        if (! $engine->isConnected()) {
            return response()->json(['ok' => false, 'message' => 'Deze site is niet aangemeld.'], 409);
        }

        $valid = WebhookSignature::matches(
            $engine->secret(),
            $request->header('X-Content-Studio-Timestamp'),
            $request->getContent(),
            $request->header('X-Content-Studio-Signature'),
        );

        if (! $valid) {
            Log::warning('[StrategyEngine] Webhook met een ongeldige handtekening geweigerd.', [
                'event' => $request->header('X-Content-Studio-Event'),
            ]);

            return response()->json(['ok' => false], 401);
        }

        $event = (string) ($request->input('event') ?: $request->header('X-Content-Studio-Event'));
        $articleId = (int) $request->input('article_id');

        $sync = SyncFromEngine::make();

        match ($event) {
            'article.publish' => $sync->one($articleId, 'published'),
            'article.update' => $sync->one($articleId, 'updated'),
            /* Een duwtje zonder inhoud: de site gaat zelf kijken wat hij
               sinds zijn cursor heeft gemist. */
            'sync.requested' => $sync->catchUp(),
            default => Log::info('[StrategyEngine] Onbekend webhook-event genegeerd.', ['event' => $event]),
        };

        return response()->json(['ok' => true]);
    }
}
