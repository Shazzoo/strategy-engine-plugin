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

    'tracking' => [
        'enabled' => env('CONTENT_STUDIO_TRACKING_ENABLED', true),
        'endpoint' => env(
            'CONTENT_STUDIO_TRACKING_ENDPOINT',
            'https://engine.content-studio.com/api/tracking/event'
        ),
    ],
];
