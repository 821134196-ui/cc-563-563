<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    /**
     * 列出可登录的店员（门店内选择当班人员）。
     */
    public function staff(Request $request)
    {
        $users = User::query()->orderBy('id')->get(['id', 'name', 'role', 'email']);
        return response()->json(['data' => $users]);
    }

    /**
     * 用初始密码换取 API Token（演示环境初始密码统一 password）。
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user || !\Illuminate\Support\Facades\Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => '账号或密码错误'], 401);
        }

        $plain = 'tok_' . bin2hex(random_bytes(24));
        $user->update(['api_token' => hash('sha256', $plain)]);

        return response()->json([
            'token' => $plain,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'email' => $user->email,
            ],
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['data' => $request->user()->only(['id', 'name', 'role', 'email'])]);
    }
}
