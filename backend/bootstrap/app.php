<?php

use App\Exceptions\DomainRuleException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'staff' => \App\Http\Middleware\AuthenticateStaff::class,
            'role' => \App\Http\Middleware\AuthenticateStaff::class,
        ]);
        // 本地单页应用（Vite dev server / 构建产物）跨域访问
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (DomainRuleException $e, Request $request) {
            if (!$request->is('api/*') && !$request->expectsJson()) {
                return null;
            }
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
                'context' => (object) $e->context,
            ], $e->status);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'validation_failed',
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }
            return response()->json(['message' => '记录不存在', 'error_code' => 'not_found'], 404);
        });

        // api/* 下的其余 HTTP 异常（含 ModelNotFound 被框架包装后的 404）一律 JSON
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }
            return response()->json(['message' => '接口或记录不存在', 'error_code' => 'not_found'], 404);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (!$request->is('api/*') || $e instanceof NotFoundHttpException) {
                return null;
            }
            return response()->json(['message' => $e->getMessage() ?: '请求异常', 'error_code' => 'http_error'], $e->getStatusCode());
        });
    })->create();
