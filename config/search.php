<?php

return [
    'driver' => env('OFFER_SEARCH_DRIVER', 'database'),
    'fallback_to_database' => env('OFFER_SEARCH_FALLBACK_TO_DATABASE', true),

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index' => env('MEILISEARCH_OFFERS_INDEX', 'offers'),
        'timeout' => env('MEILISEARCH_TIMEOUT', 2),
    ],
];
