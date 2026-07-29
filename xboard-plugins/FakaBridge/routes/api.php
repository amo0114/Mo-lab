<?php

use Illuminate\Support\Facades\Route;
use Plugin\FakaBridge\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| FakaBridge API routes
|--------------------------------------------------------------------------
|
| PluginManager only applies middleware('api') — it does NOT add /api/v1.
| Production (v.uuwu.de / cedar2025 xboard:new) is verified WITHOUT /api/v1:
|   POST /plugin/faka-bridge/order-paid
|   GET  /plugin/faka-bridge/order-status
|   GET  /plugin/faka-bridge/plan-capacity
|   POST /plugin/faka-bridge/plan-capacity
|   GET  /plugin/faka-bridge/plan-catalog
|
| All routes require HMAC secret (MoNexus platform only). End users never call these.
| Paths with /api/v1/plugin/... return 404 on production — do not re-add.
|
*/
Route::prefix('plugin/faka-bridge')->group(function () {
    Route::post('order-paid', [WebhookController::class, 'orderPaid']);
    Route::get('order-status', [WebhookController::class, 'orderStatus']);
    Route::get('plan-capacity', [WebhookController::class, 'planCapacity']);
    Route::post('plan-capacity', [WebhookController::class, 'setPlanCapacity']);
    Route::get('plan-catalog', [WebhookController::class, 'planCatalog']);
});
