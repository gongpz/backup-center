<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoginLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('username', $data['username'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            LoginLog::create([
                'user_id' => $user?->id,
                'username' => $data['username'],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'success' => false,
                'message' => '用户名或密码错误',
                'created_at' => now(),
            ]);

            throw ValidationException::withMessages([
                'username' => ['用户名或密码错误'],
            ]);
        }

        $token = $user->createToken('web', ['*'], now()->addDays(30))->plainTextToken;

        LoginLog::create([
            'user_id' => $user->id,
            'username' => $user->username,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'success' => true,
            'message' => '登录成功',
            'created_at' => now(),
        ]);

        return response()->json([
            'token' => $token,
            'user' => $this->userView($user),
        ]);
    }

    /**
     * 系统初始化状态：没有任何用户时，前端需要显示"创建管理员"表单
     * GET /api/auth/bootstrap-status
     */
    public function bootstrapStatus(): JsonResponse
    {
        return response()->json([
            'need_bootstrap' => User::count() === 0,
            'system_name'    => SystemSetting::get('system_name', 'backup center'),
        ]);
    }

    /**
     * 创建第一个管理员 —— 只在 User 表为空时可用
     * POST /api/auth/bootstrap
     */
    public function bootstrap(Request $request): JsonResponse
    {
        if (User::count() > 0) {
            return response()->json(['message' => '管理员已存在，请使用登录'], 403);
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_.-]+$/'],
            'password' => ['required', 'string', 'min:8', 'max:64', 'confirmed'],
            'name'     => ['nullable', 'string', 'max:64'],
        ]);

        $user = User::create([
            'username' => $data['username'],
            'name'     => $data['name'] ?? $data['username'],
            'password' => $data['password'],
        ]);

        $token = $user->createToken('web', ['*'], now()->addDays(30))->plainTextToken;

        LoginLog::create([
            'user_id'    => $user->id,
            'username'   => $user->username,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'success'    => true,
            'message'    => '初始化创建管理员',
            'created_at' => now(),
        ]);

        return response()->json([
            'token' => $token,
            'user'  => $this->userView($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userView($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => '已退出登录']);
    }

    /**
     * 更新用户名 / 显示名
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'username' => ['required', 'string', 'max:32',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'name' => ['nullable', 'string', 'max:64'],
        ]);

        $user->update($data);

        return response()->json([
            'message' => '资料已更新',
            'user' => $this->userView($user->fresh()),
        ]);
    }

    /**
     * 修改密码：必须提供当前密码
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'max:64', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['当前密码不正确'],
            ]);
        }

        // password cast 会自动 hash
        $user->update(['password' => $data['new_password']]);

        // 撤销除当前 token 外的所有其他 token（可选，出于安全）
        $current = $request->user()->currentAccessToken();
        $user->tokens()->where('id', '!=', $current->id)->delete();

        return response()->json(['message' => '密码已更新，其他登录会话已注销']);
    }

    /**
     * 当前用户的登录记录（成功+失败，最新 30 条）
     */
    public function loginLogs(Request $request): JsonResponse
    {
        $user = $request->user();
        $logs = LoginLog::where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'ip_address' => $l->ip_address,
                'user_agent' => $l->user_agent,
                'success' => (bool) $l->success,
                'message' => $l->message,
                'created_at' => $l->created_at?->toIso8601String(),
            ]);
        return response()->json(['data' => $logs]);
    }

    private function userView(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
