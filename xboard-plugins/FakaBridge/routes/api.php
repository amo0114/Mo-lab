<?php

use Illuminate\Support\Facades\Route;
use Plugin\FakaBridge\Controllers\WebhookController;
use Plugin\FakaBridge\Controllers\AccountV2Controller;
use Plugin\FakaBridge\Controllers\TrafficResetV2Controller;
use Plugin\FakaBridge\Controllers\PaidResetV2Controller;

/*
|--------------------------------------------------------------------------
| FakaBridge API routes
|--------------------------------------------------------------------------
|
| PluginManager only applies middleware('api') — it does NOT add /api/v1.
| Production (v.uuwu.de / cedar2025 xboard:new) is verified WITHOUT /api/v1:
|   POST /plugin/faka-bridge/order-paid
|   GET  /plugin/faka-bridge/order-status
|   POST /plugin/faka-bridge/order-revoke
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
    Route::post('order-revoke', [WebhookController::class, 'orderRevoke']);
    Route::get('plan-capacity', [WebhookController::class, 'planCapacity']);
    Route::post('plan-capacity', [WebhookController::class, 'setPlanCapacity']);
    Route::get('plan-catalog', [WebhookController::class, 'planCatalog']);
    Route::post('v2/traffic-reset-preview', [TrafficResetV2Controller::class, 'preview']);
    Route::post('v2/traffic-reset', [TrafficResetV2Controller::class, 'execute']);
    Route::post('v2/traffic-reset-status', [TrafficResetV2Controller::class, 'status']);
    Route::post('v2/traffic-reset-cancel', [TrafficResetV2Controller::class, 'cancel']);
    Route::post('v2/paid-reset-preview', [PaidResetV2Controller::class, 'preview']);
    Route::post('v2/paid-reset', [PaidResetV2Controller::class, 'execute']);
    Route::post('v2/paid-reset-status', [PaidResetV2Controller::class, 'status']);
    Route::post('v2/paid-reset-revoke', [PaidResetV2Controller::class, 'revoke']);
    Route::post('v2/capabilities', [AccountV2Controller::class, 'capabilities']);
    Route::post('v2/user-resolve', [AccountV2Controller::class, 'resolve']);
    Route::post('v2/user-subscription', [AccountV2Controller::class, 'subscription']);
});
