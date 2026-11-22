<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $spaIndex = public_path('index.html');
    if (is_file($spaIndex)) {
        return response()->file($spaIndex, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    return response()->json(['service' => 'Estoque TI API', 'status' => 'ok']);
});


