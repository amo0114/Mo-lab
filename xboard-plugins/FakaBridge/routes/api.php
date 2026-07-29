<?php

use Illuminate\Support\Facades\Route;
use Plugin\FakaBridge\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| FakaBridge API routes
|--------------------------------------------------------------------------
|
| PluginManager only applies middleware('api') — it does NOT prefix /api/v1.
| Routes must include the full public path, matching production reverse proxy:
|   POST /api/v1/plugin/faka-bridge/order-paid
|   GET  /api/v1/plugin/faka-bridge/order-status
|
*/
Route::prefix('api/v1/plugin/faka-bridge')->group(function () {
    Route::post('order-paid', [WebhookController::class, 'orderPaid']);
    Route::get('order-status', [WebhookController::class, 'orderStatus']);
});
