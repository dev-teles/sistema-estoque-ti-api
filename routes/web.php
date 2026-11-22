<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'Estoque TI API', 'status' => 'ok']));

