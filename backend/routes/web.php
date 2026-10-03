<?php

use Illuminate\Support\Facades\Route;

// 首页交给 React 单页应用（构建产物由 Vite 注入）
Route::view('/', 'app');

Route::fallback(function (\Illuminate\Http\Request $request) {
    if ($request->is('api/*') || $request->expectsJson()) {
        return response()->json(['message' => '接口不存在', 'code' => 'not_found'], 404);
    }

    return response()->view('app');
});
