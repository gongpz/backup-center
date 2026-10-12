<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server酱推送客户端。
 * 使用方式：new ServerChanNotifier($sendkey)->send('标题', '正文 Markdown')
 * SendKey 从 Server酱后台复制，形如 SCTxxxxxxxxxxxxxxxxxxxxxxxxxx
 */
class ServerChanNotifier
{
    private string $sendkey;

    public function __construct(string $sendkey)
    {
        $this->sendkey = $sendkey;
    }

    /**
     * @return array{ok: bool, message: string, raw?: array|string}
     */
    public function send(string $title, string $desp = ''): array
    {
        $url = 'https://sctapi.ftqq.com/' . $this->sendkey . '.send';

        try {
            // 用 JSON body（比 form 更稳，下游渠道不会因为 URL 编码问题拒收）
            $resp = Http::timeout(10)
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'title' => mb_substr($title, 0, 200),
                    'desp'  => mb_substr($desp, 0, 32000),
                ]);

            $rawBody = $resp->body();
            $data = $resp->json() ?: [];
            $code = $data['code'] ?? -1;

            if ($code === 0) {
                // 顺便看 push channel 有没有真的推成功
                $pushed = $data['data']['errno'] ?? 0;
                $pushMsg = $data['data']['error'] ?? '';

                // 检查下游渠道推送结果
                $pushResults = $data['data']['pushresult'] ?? [];
                if (! empty($pushResults) && is_array($pushResults)) {
                    $ok = true;
                    $errs = [];
                    foreach ($pushResults as $r) {
                        if (($r['errno'] ?? 0) !== 0 || ! empty($r['error'])) {
                            $ok = false;
                            $errs[] = ($r['channel'] ?? '未知渠道') . ': ' . ($r['error'] ?? '未知错误');
                        }
                    }
                    if (! $ok) {
                        return [
                            'ok' => false,
                            'message' => 'Server酱 API 已接收，但下游渠道推送失败：' . implode('; ', $errs),
                            'raw' => $data,
                        ];
                    }
                }

                return ['ok' => true, 'message' => 'Server酱 已接收并推送', 'raw' => $data];
            }

            $msg = $data['message'] ?? ($data['error'] ?? "HTTP {$resp->status()}");
            Log::warning('Server酱推送失败', ['code' => $code, 'msg' => $msg, 'raw' => $rawBody]);
            return [
                'ok' => false,
                'message' => "Server酱返回 code={$code}: {$msg}",
                'raw' => $data ?: $rawBody,
            ];
        } catch (\Throwable $e) {
            Log::warning('Server酱推送异常', ['err' => $e->getMessage()]);
            return ['ok' => false, 'message' => '发送异常: ' . $e->getMessage()];
        }
    }
}
