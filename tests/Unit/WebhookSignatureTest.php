<?php

use Shazzoo\StrategyEngine\Support\Engine\WebhookSignature;

/**
 * De handtekening gaat over de tijdstempel én de body samen, zodat een
 * opgevangen aanroep niet later opnieuw bruikbaar is met een verse
 * tijdstempel.
 */
function signed(string $secret, string $timestamp, string $body): string
{
    return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
}

test('een echte aanroep wordt herkend', function () {
    $body = '{"event":"article.publish","article_id":12}';
    $now = 1_800_000_000;

    expect(WebhookSignature::matches('geheim', (string) $now, $body, signed('geheim', (string) $now, $body), $now))
        ->toBeTrue();
});

test('een andere body maakt de handtekening ongeldig', function () {
    $now = 1_800_000_000;
    $signature = signed('geheim', (string) $now, '{"article_id":12}');

    expect(WebhookSignature::matches('geheim', (string) $now, '{"article_id":99}', $signature, $now))
        ->toBeFalse();
});

test('een ander geheim wordt niet geaccepteerd', function () {
    $body = '{"article_id":12}';
    $now = 1_800_000_000;

    expect(WebhookSignature::matches('geheim', (string) $now, $body, signed('ander', (string) $now, $body), $now))
        ->toBeFalse();
});

/**
 * Dit is wat herhalen tegenhoudt: dezelfde handtekening is een halfuur
 * later niets meer waard.
 */
test('een oude aanroep wordt geweigerd', function () {
    $body = '{"article_id":12}';
    $toen = 1_800_000_000;
    $nu = $toen + 1800;

    expect(WebhookSignature::matches('geheim', (string) $toen, $body, signed('geheim', (string) $toen, $body), $nu))
        ->toBeFalse();
});

test('een beetje klokverschil mag wel', function () {
    $body = '{"article_id":12}';
    $toen = 1_800_000_000;

    expect(WebhookSignature::matches('geheim', (string) $toen, $body, signed('geheim', (string) $toen, $body), $toen + 120))
        ->toBeTrue()
        ->and(WebhookSignature::matches('geheim', (string) $toen, $body, signed('geheim', (string) $toen, $body), $toen - 120))
        ->toBeTrue();
});

test('zonder geheim, tijdstempel of handtekening gaat er niets door', function (?string $secret, ?string $timestamp, ?string $signature) {
    expect(WebhookSignature::matches((string) $secret, $timestamp, '{}', $signature, 1_800_000_000))
        ->toBeFalse();
})->with([
    'geen geheim' => ['', '1800000000', 'abc'],
    'geen tijdstempel' => ['geheim', null, 'abc'],
    'geen handtekening' => ['geheim', '1800000000', null],
    'tijdstempel is geen getal' => ['geheim', 'gisteren', 'abc'],
]);
