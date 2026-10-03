<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CareController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\GarmentController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PhotoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 店员身份：所有业务接口都要求携带当班店员 Token。
| 角色约束在具体路由上声明（manager / washer / cashier）。
|--------------------------------------------------------------------------
*/

Route::get('/staff', [AuthController::class, 'staff']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('staff')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    // 收件台
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::post('/orders/{id}/pause', [OrderController::class, 'pause']);
    Route::post('/orders/{id}/resume', [OrderController::class, 'resume']);
    Route::post('/orders/{id}/confirm', [OrderController::class, 'confirm']);

    // 逐件预检
    Route::post('/orders/{orderId}/garments', [GarmentController::class, 'store']);
    Route::get('/garments-board', [GarmentController::class, 'board']);
    Route::get('/garments/{id}', [GarmentController::class, 'show']);
    Route::post('/garments/{garmentId}/photos', [PhotoController::class, 'store']);
    Route::post('/garments/{garmentId}/marks', [PhotoController::class, 'storeMark']);
    Route::get('/photos/{id}/content', [PhotoController::class, 'content']);

    // 洗护（洗护人员）
    Route::middleware('role:washer')->group(function () {
        Route::post('/garments/{garmentId}/care/start', [CareController::class, 'start']);
        Route::post('/garments/{garmentId}/care/finish', [CareController::class, 'finish']);
    });

    // 完工核对 / 复检（店员或洗护人员均可登记核对结果）
    Route::post('/garments/{garmentId}/inspections', [CareController::class, 'inspect']);

    // 争议与赔偿
    Route::get('/disputes', [DisputeController::class, 'index']);
    Route::get('/disputes/{id}', [DisputeController::class, 'show']);
    Route::post('/disputes/{id}/recheck', [DisputeController::class, 'requestRecheck']);
    Route::post('/disputes/{id}/notes', [DisputeController::class, 'addNote']);
    Route::post('/disputes/{id}/compensation', [DisputeController::class, 'requestCompensation']);

    // 审批：仅店长，且不能审批自己经手的衣物（服务层再校验）
    Route::middleware('role:manager')
        ->post('/disputes/{id}/compensation/decide', [DisputeController::class, 'decide']);

    // 付款（模拟接口）：仅店长
    Route::middleware('role:manager')
        ->post('/disputes/{id}/compensation/pay', [DisputeController::class, 'pay']);
});
