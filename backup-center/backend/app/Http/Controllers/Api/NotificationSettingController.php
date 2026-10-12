<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationSetting;
use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationSettingController extends Controller
{
    public function show(): JsonResponse
    {
        $s = NotificationSetting::current();
        return response()->json([
            'data' => [
                'has_sendkey' => $s->hasSendkey(),
                'sendkey_hint' => $s->hasSendkey()
                    ? $this->maskSendkey($s->serverchan_sendkey)
                    : null,
                'notify_backup_success' => $s->notify_backup_success,
                'notify_backup_failed'  => $s->notify_backup_failed,
                'notify_agent_offline'  => $s->notify_agent_offline,
                'updated_at'            => $s->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'serverchan_sendkey' => ['nullable', 'string', 'max:255'],
            'notify_backup_success' => ['nullable', 'boolean'],
            'notify_backup_failed' => ['nullable', 'boolean'],
            'notify_agent_offline' => ['nullable', 'boolean'],
        ]);

        $s = NotificationSetting::current();

        // SendKey：只在明确传入时更新（避免用户没改 SendKey 但清空提示 mask 值时丢配置）
        if (array_key_exists('serverchan_sendkey', $data)) {
            $v = trim((string) $data['serverchan_sendkey']);
            // 如果传的是马赛克占位符（••••••），跳过不改
            if ($v !== '' && ! str_contains($v, '••••')) {
                $s->serverchan_sendkey = $v;
            } elseif ($v === '') {
                $s->serverchan_sendkey = null;
            }
        }

        foreach (['notify_backup_success', 'notify_backup_failed', 'notify_agent_offline'] as $key) {
            if (array_key_exists($key, $data)) {
                $s->$key = (bool) $data[$key];
            }
        }
        $s->save();

        return $this->show();
    }

    public function test(Request $request, NotificationDispatcher $dispatcher): JsonResponse
    {
        $sendkey = trim((string) $request->input('sendkey', ''));
        if (! $sendkey || str_contains($sendkey, '••••')) {
            $sendkey = NotificationSetting::current()->serverchan_sendkey ?? '';
        }
        if (! $sendkey) {
            return response()->json([
                'ok' => false,
                'message' => '请先填写 SendKey 或保存后再测试',
            ], 422);
        }

        // testSend 返回 {ok, message, raw?}
        $result = $dispatcher->testSend($sendkey);
        // 200 让前端顺利拿到 raw；用 ok 字段判断成败
        return response()->json($result);
    }

    private function maskSendkey(string $key): string
    {
        $len = strlen($key);
        if ($len < 8) return str_repeat('•', $len);
        return substr($key, 0, 4) . str_repeat('•', $len - 8) . substr($key, -4);
    }
}
