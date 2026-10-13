<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        foreach (['categories', 'products', 'warehouses'] as $resource) {
            Route::get($resource, [CatalogController::class, 'index'])->defaults('resource', $resource);
            Route::get($resource.'/{id}', [CatalogController::class, 'show'])->whereNumber('id')->defaults('resource', $resource);
            Route::middleware('role:manager')->group(function () use ($resource) {
                Route::post($resource, [CatalogController::class, 'store'])->defaults('resource', $resource);
                Route::patch($resource.'/{id}', [CatalogController::class, 'update'])->whereNumber('id')->defaults('resource', $resource);
                Route::delete($resource.'/{id}', [CatalogController::class, 'destroy'])->whereNumber('id')->defaults('resource', $resource);
            });
        }
        Route::get('stocks', [InventoryController::class, 'stocks']);
        Route::get('stock-movements', [InventoryController::class, 'movements'])->middleware('role:manager,warehouse');
        Route::post('stock-movements', [InventoryController::class, 'storeMovement'])->middleware('role:manager,warehouse');
        Route::get('reports/low-stock', [InventoryController::class, 'lowStock'])->middleware('role:manager,warehouse');
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::post('orders', [OrderController::class, 'store'])->middleware('role:manager,sales');
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('role:manager,sales');
        Route::post('orders/{order}/fulfil', [OrderController::class, 'fulfil'])->middleware('role:manager,warehouse');
        Route::get('users', [UserController::class, 'index'])->middleware('role:manager');
        Route::post('users', [UserController::class, 'store'])->middleware('role:manager');
        Route::patch('users/{user}', [UserController::class, 'update'])->middleware('role:manager');
    });
});
