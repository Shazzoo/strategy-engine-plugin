<?php

return [
    /*
     * Wat er in het schema en de deelkaarten van de artikelen komt te
     * staan. Leeg laten mag: de naam van de applicatie en de vertaalde
     * omschrijving vullen de gaten, en een logo dat we niet weten laten we
     * liever weg dan dat we er een verkeerd neerzetten.
     */
    'seo' => [
        'publisher_logo' => env('CONTENT_STUDIO_PUBLISHER_LOGO'),
        'index_description' => env('CONTENT_STUDIO_INDEX_DESCRIPTION'),
        'index_image' => env('CONTENT_STUDIO_INDEX_IMAGE'),
    ],

    'engine' => [
        'api_url' => env('CONTENT_STUDIO_ENGINE_API_URL', 'https://engine.content-studio.com/api/v1'),

        /*
         * V2: de engine belt de site zodra een artikel live mag, en de site
         * haalt het hier op. Standaard hetzelfde adres als hierboven, met v2
         * in plaats van v1.
         */
        'api_v2_url' => env('CONTENT_STUDIO_ENGINE_API_V2_URL', 'https://engine.content-studio.com/api/v2'),
    ],

    /*
     * Een eigen voorvoegsel per taal, in plaats van dat uit de instellingen.
     * Bijvoorbeeld ['en' => 'knowledge-base'] zet de Engelse artikelen onder
     * /en/knowledge-base/... terwijl de andere talen het ingestelde gebruiken.
     */
    'route_prefixes' => [],

    /*
     * Herkomst van artikelen ("Over dit artikel") en de controle na
     * publicatie. Zie de README, "Over dit artikel".
     */
    'provenance' => [
        // author, top of bottom. Leeg: wat de Engine voorstelt, anders onderaan.
        'position' => env('CONTENT_STUDIO_PROVENANCE_POSITION'),

        // native (de component) of body (altijd in de tekst). Leeg: native,
        // met body alleen als de verificatie aantoont dat native niet werkt.
        'rendering' => env('CONTENT_STUDIO_PROVENANCE_RENDERING'),

        'allow_body_fallback' => true,

        // Na publicatie de publieke pagina ophalen en controleren.
        'verify' => env('CONTENT_STUDIO_VERIFY_PUBLICATION', true),

        // Leeg: alleen in productie het certificaat controleren.
        'verify_ssl' => env('CONTENT_STUDIO_VERIFY_SSL'),

        // warn of block: wat een afwijkend Article-schema op de pagina betekent.
        'schema_conflict_policy' => env('CONTENT_STUDIO_SCHEMA_CONFLICT_POLICY', 'warn'),

        // Pogingen en pauzes (seconden) bij het ophalen.
        'verifier' => [
            'attempts' => 3,
            'backoff' => [1, 3],
        ],

        'supported_versions' => [1],
    ],

    'tracking' => [
        'enabled' => env('CONTENT_STUDIO_TRACKING_ENABLED', true),
        'endpoint' => env(
            'CONTENT_STUDIO_TRACKING_ENDPOINT',
            'https://engine.content-studio.com/api/tracking/event'
        ),
    ],
];
