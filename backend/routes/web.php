<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 前端 SPA（React 构建产物发布到 public/ 下，与 API 共用一个端口）
|--------------------------------------------------------------------------
| /api/* 与 /up 由 API 路由和健康检查处理；其余路径一律返回 SPA 入口，
| 前端静态资源（/assets/*）由 public 目录直接作为静态文件返回。
*/

Route::get('/{any?}', function () {
    $entry = public_path('index.html');
    if (!File::exists($entry)) {
        return response()->json([
            'message' => '前端尚未构建，请先运行项目根目录的 ./start.sh（或进入 frontend 执行 npm run build 并发布）',
        ], 503);
    }
    return response()->file($entry);
})->where('any', '^(?!api(/|$)|up$|assets(/|$)).*$');
