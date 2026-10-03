<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 门店内系统的轻量认证：前端选择当班店员，携带其 API Token。
 * Header: Authorization: Bearer <token> 或 X-Staff-Token: <token>
 */
class AuthenticateStaff
{
    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        $token = $request->bearerToken()
            ?? $request->header('X-Staff-Token')
            // <img>/<a> 标签无法自定义请求头，允许照片内容接口通过查询参数携带令牌
            ?? $request->query('token');
        if (!$token) {
            return response()->json(['message' => '未提供店员凭证', 'error_code' => 'unauthenticated'], 401);
        }

        $user = User::where('api_token', hash('sha256', $token))->first();
        if (!$user) {
            return response()->json(['message' => '店员凭证无效', 'error_code' => 'unauthenticated'], 401);
        }

        if ($role !== null && $user->role !== $role) {
            return response()->json([
                'message' => '当前店员角色无权执行该操作',
                'error_code' => 'forbidden_role',
                'required_role' => $role,
                'actual_role' => $user->role,
            ], 403);
        }

        $request->setUserResolver(fn () => $user);
        return $next($request);
    }
}
