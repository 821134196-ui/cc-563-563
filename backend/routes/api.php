<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\GarmentController;
use App\Http\Controllers\GatewayCallbackController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\WashController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// 模拟支付网关回执（独立签名鉴权，不走登录令牌）
Route::post('/mock-gateway/callback', [GatewayCallbackController::class, 'callback']);

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth.token')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);

    // 收件台统计
    Route::get('/dashboard/intake-stats', [DashboardController::class, 'intakeStats']);
    Route::get('/dashboard/paused', [DashboardController::class, 'pausedOrders']);

    // 订单
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store'])->middleware('role:'.User::ROLE_CLERK.','.User::ROLE_MANAGER);
    Route::get('/orders/{id}', [OrderController::class, 'show'])->whereNumber('id');
    Route::get('/orders/{id}/events', [OrderController::class, 'events'])->whereNumber('id');
    Route::post('/orders/{id}/submit-confirmation', [OrderController::class, 'submitConfirmation'])->whereNumber('id');
    Route::post('/orders/{id}/confirm', [OrderController::class, 'confirm'])->whereNumber('id');
    Route::post('/orders/{id}/refuse', [OrderController::class, 'refuse'])->whereNumber('id');
    Route::post('/orders/{id}/pause', [OrderController::class, 'pause'])->whereNumber('id');
    Route::post('/orders/{id}/resume', [OrderController::class, 'resume'])->whereNumber('id');

    // 衣物 / 预检
    Route::post('/orders/{orderId}/garments', [GarmentController::class, 'store'])->whereNumber('orderId');
    Route::get('/garments/{id}', [GarmentController::class, 'show'])->whereNumber('id');
    Route::post('/garments/{id}/marks', [GarmentController::class, 'addMark'])->whereNumber('id');
    Route::post('/garments/{id}/pocket-items', [GarmentController::class, 'reportPocketItem'])->whereNumber('id');
    Route::post('/garments/{id}/pocket-items/{itemId}/return', [GarmentController::class, 'returnPocketItem'])
        ->whereNumber('id')->whereNumber('itemId');

    // 证据照片
    Route::post('/garments/{garmentId}/photos', [PhotoController::class, 'upload'])->whereNumber('garmentId');
    Route::get('/photos/{id}', [PhotoController::class, 'show'])->whereNumber('id');

    // 洗护流转
    Route::get('/wash/queue', [WashController::class, 'queue']);
    Route::get('/garments/{id}/comparison', [WashController::class, 'comparison'])->whereNumber('id');
    Route::post('/garments/{id}/wash/start', [WashController::class, 'start'])->whereNumber('id');
    Route::post('/garments/{id}/wash/finish', [WashController::class, 'finish'])->whereNumber('id');
    Route::post('/garments/{id}/inspection/pass', [WashController::class, 'passInspection'])->whereNumber('id');
    Route::post('/garments/{id}/handover', [WashController::class, 'handover'])->whereNumber('id');

    // 争议 / 复检 / 赔付
    Route::get('/disputes', [DisputeController::class, 'index']);
    Route::get('/disputes/{id}', [DisputeController::class, 'show'])->whereNumber('id');
    Route::post('/garments/{garmentId}/disputes', [DisputeController::class, 'open'])->whereNumber('garmentId');
    Route::post('/disputes/{id}/recheck', [DisputeController::class, 'startRecheck'])->whereNumber('id');
    Route::post('/disputes/{id}/reviews', [DisputeController::class, 'submitReview'])->whereNumber('id');
    Route::post('/disputes/{id}/compensation', [DisputeController::class, 'propose'])->whereNumber('id');
    Route::post('/disputes/{id}/close', [DisputeController::class, 'close'])->whereNumber('id');

    // 赔付审批（仅经理；服务层再拦截自审）
    Route::post('/compensations/{id}/approve', [DisputeController::class, 'approve'])
        ->whereNumber('id')->middleware('role:'.User::ROLE_MANAGER);
    Route::post('/compensations/{id}/pay', [DisputeController::class, 'pay'])->whereNumber('id');
    Route::post('/compensations/{id}/repropose', [DisputeController::class, 'repropose'])->whereNumber('id');
});
