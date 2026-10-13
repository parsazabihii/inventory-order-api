<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => 'Inventory & Order API', 'version' => '1.0.0', 'documentation' => 'docs/openapi.json']));
Route::get('/docs/openapi.json', fn () => response()->file(base_path('docs/openapi.json'), ['Content-Type' => 'application/json']));
