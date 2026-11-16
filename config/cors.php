<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Origens do frontend (Vercel + dev local), separadas por vírgula em FRONTEND_URL.
    'allowed_origins' => array_filter(array_map('trim', explode(',', env('FRONTEND_URL', 'http://localhost:5173')))),


    // Ex.: previews da Vercel — CORS_ALLOWED_PATTERN='#^https://estoque-.*\.vercel\.app$#'
    'allowed_origins_patterns' => array_filter([env('CORS_ALLOWED_PATTERN')]),


    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,

];
