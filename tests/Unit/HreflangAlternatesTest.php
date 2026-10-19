<?php

use Shazzoo\StrategyEngine\Support\Seo\HreflangAlternates;

test('één taal levert geen x-default op', function () {
    $alternates = HreflangAlternates::build([
        ['locale' => 'nl', 'url' => 'https://shazzoo.nl/kennisbank/artikel'],
    ], 'nl');

    expect($alternates)->toBe([
        ['locale' => 'nl', 'url' => 'https://shazzoo.nl/kennisbank/artikel'],
    ]);
});

test('meerdere talen krijgen x-default op de standaardtaal', function () {
    $alternates = HreflangAlternates::build([
        ['locale' => 'en', 'url' => 'https://shazzoo.nl/en/knowledge-base/article'],
        ['locale' => 'nl', 'url' => 'https://shazzoo.nl/kennisbank/artikel'],
    ], 'nl');

    expect($alternates)->toBe([
        ['locale' => 'en', 'url' => 'https://shazzoo.nl/en/knowledge-base/article'],
        ['locale' => 'nl', 'url' => 'https://shazzoo.nl/kennisbank/artikel'],
        ['locale' => 'x-default', 'url' => 'https://shazzoo.nl/kennisbank/artikel'],
    ]);
});

/**
 * Zonder deze regel zou een site waarvan de standaardtaal geen vertaling
 * van dit artikel heeft, x-default naar een willekeurige taal wijzen.
 */
test('zonder de standaardtaal in de lijst komt er geen x-default', function () {
    $alternates = HreflangAlternates::build([
        ['locale' => 'en', 'url' => 'https://shazzoo.nl/en/knowledge-base/article'],
        ['locale' => 'de', 'url' => 'https://shazzoo.nl/de/wissen/artikel'],
    ], 'nl');

    expect($alternates)->toBe([
        ['locale' => 'en', 'url' => 'https://shazzoo.nl/en/knowledge-base/article'],
        ['locale' => 'de', 'url' => 'https://shazzoo.nl/de/wissen/artikel'],
    ]);
});

test('dezelfde taal twee keer houdt de eerste', function () {
    $alternates = HreflangAlternates::build([
        ['locale' => 'nl', 'url' => 'https://shazzoo.nl/kennisbank/dit-artikel'],
        ['locale' => 'nl', 'url' => 'https://shazzoo.nl/kennisbank/ouder-artikel'],
        ['locale' => 'en', 'url' => 'https://shazzoo.nl/en/knowledge-base/article'],
    ], 'nl');

    expect($alternates)->toBe([
        ['locale' => 'nl', 'url' => 'https://shazzoo.nl/kennisbank/dit-artikel'],
        ['locale' => 'en', 'url' => 'https://shazzoo.nl/en/knowledge-base/article'],
        ['locale' => 'x-default', 'url' => 'https://shazzoo.nl/kennisbank/dit-artikel'],
    ]);
});

test('een regel zonder taal of zonder url telt niet mee', function (?string $locale, ?string $url) {
    $alternates = HreflangAlternates::build([
        ['locale' => 'nl', 'url' => 'https://shazzoo.nl/kennisbank/artikel'],
        ['locale' => $locale, 'url' => $url],
    ], 'nl');

    expect($alternates)->toBe([
        ['locale' => 'nl', 'url' => 'https://shazzoo.nl/kennisbank/artikel'],
    ]);
})->with([
    'geen taal' => [null, 'https://shazzoo.nl/en/knowledge-base/article'],
    'lege taal' => ['', 'https://shazzoo.nl/en/knowledge-base/article'],
    'geen url' => ['en', null],
    'lege url' => ['en', ''],
]);

test('zonder bekende standaardtaal blijven de talen gewoon staan', function () {
    $alternates = HreflangAlternates::build([
        ['locale' => 'nl', 'url' => 'https://shazzoo.nl/kennisbank/artikel'],
        ['locale' => 'en', 'url' => 'https://shazzoo.nl/en/knowledge-base/article'],
    ], null);

    expect($alternates)->toHaveCount(2)
        ->and(array_column($alternates, 'locale'))->toBe(['nl', 'en']);
});

/**
 * De Engine schrijft in de talen van het project; een site toont er soms
 * minder. shazzoo.nl staat op Nederlands en Engels terwijl er ook Duits
 * geschreven wordt - en dan hoort die Duitse regel hier niet te staan.
 */
test('a language the site does not publish is left out', function () {
    $alternates = HreflangAlternates::build([
        ['locale' => 'nl', 'url' => 'https://shazzoo.nl/kennisbank/artikel'],
        ['locale' => 'en', 'url' => 'https://shazzoo.nl/en/knowledge-base/article'],
        ['locale' => 'de', 'url' => 'https://shazzoo.nl/de/kennisbank/artikel'],
    ], 'nl', ['nl', 'en']);

    expect(array_column($alternates, 'locale'))->toBe(['nl', 'en', 'x-default']);
});

test('without a known list of languages nothing is held back', function () {
    $alternates = HreflangAlternates::build([
        ['locale' => 'nl', 'url' => 'https://shazzoo.nl/kennisbank/artikel'],
        ['locale' => 'de', 'url' => 'https://shazzoo.nl/de/kennisbank/artikel'],
    ], 'nl', []);

    expect(array_column($alternates, 'locale'))->toBe(['nl', 'de', 'x-default']);
});

/**
 * Houdt het filter één taal over, dan is er niets te kiezen en hoort er
 * ook geen x-default te staan.
 */
test('filtering down to one language drops the x-default too', function () {
    $alternates = HreflangAlternates::build([
        ['locale' => 'nl', 'url' => 'https://shazzoo.nl/kennisbank/artikel'],
        ['locale' => 'de', 'url' => 'https://shazzoo.nl/de/kennisbank/artikel'],
    ], 'nl', ['nl']);

    expect($alternates)->toBe([
        ['locale' => 'nl', 'url' => 'https://shazzoo.nl/kennisbank/artikel'],
    ]);
});
