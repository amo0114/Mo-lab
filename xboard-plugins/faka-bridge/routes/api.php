<?php

use Illuminate\Support\Facades\Route;
use Plugin\FakaBridge\Controllers\WebhookController;

Route::prefix('plugin/faka-bridge')->group(function () {
    Route::post('order-paid', [WebhookController::class, 'orderPaid']);
    Route::get('order-status', [WebhookController::class, 'orderStatus']);
});
