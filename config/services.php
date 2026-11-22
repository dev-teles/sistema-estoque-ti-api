<?php

return [

    'auvo' => [
        'url' => env('AUVO_API_URL', 'https://api.auvo.com.br/v2'),
        'key' => env('AUVO_API_KEY'),
        'token' => env('AUVO_API_TOKEN'),
        'webhook_secret' => env('AUVO_WEBHOOK_SECRET'),
    ],

];
