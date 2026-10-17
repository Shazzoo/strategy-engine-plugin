<?php

namespace Shazzoo\StrategyEngine\Support\Engine;

/**
 * Of een binnenkomende webhook echt van de engine komt.
 *
 * De handtekening gaat over de tijdstempel én de body samen. Daardoor is
 * een opgevangen aanroep niet later opnieuw te gebruiken met een verse
 * tijdstempel: de handtekening hoort bij precies dat ene moment.
 *
 * De vergelijking gebruikt hash_equals en niet ===, zodat de tijd die het
 * kost niet verraadt hoe ver iemand zat.
 */
final class WebhookSignature
{
    /** Hoeveel klokverschil tussen engine en site we accepteren. */
    public const TOLERANCE_SECONDS = 300;

    public static function matches(
        string $secret,
        ?string $timestamp,
        string $body,
        ?string $signature,
        ?int $now = null,
    ): bool {
        if ($secret === '' || ! $timestamp || ! $signature) {
            return false;
        }

        if (! ctype_digit($timestamp)) {
            return false;
        }

        $age = abs(($now ?? time()) - (int) $timestamp);

        if ($age > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature);
    }
}
