<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => '账号或密码错误', 'code' => 'bad_credentials'], 401);
        }

        if (! $user->api_token) {
            $token = User::newApiToken();
            $user->update(['api_token' => hash('sha256', $token)]);
        } else {
            // 每次登录颁发新令牌
            $token = User::newApiToken();
            $user->update(['api_token' => hash('sha256', $token)]);
        }

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => match ($user->role) {
                User::ROLE_CLERK => '收件店员',
                User::ROLE_WASHER => '洗护人员',
                User::ROLE_MANAGER => '门店经理',
                default => $user->role,
            },
        ];
    }
}
