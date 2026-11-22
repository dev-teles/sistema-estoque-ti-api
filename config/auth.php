<?php

use App\Models\User;

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'jwt'),
    ],

    'guards' => [
        'jwt' => [
            'driver' => 'jwt',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],
    ],

];
