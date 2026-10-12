<?php

namespace App\Services;

use App\Models\BackupLog;
use App\Models\NotificationSetting;
use App\Models\Server;
use Illuminate\Support\Facades\Log;

/**
 * 通知事件分发器：根据 notification_settings 判断是否发送、发送什么内容。
 * 静默失败：所有发送都不抛异常，只记 log。
 */
class NotificationDispatcher
{
    public function notifyBackupCompleted(BackupLog $log): void
    {
        $s = NotificationSetting::current();
        if (! $s->hasSendkey()) return;

        $isSuccess = $log->status === BackupLog::STATUS_SUCCESS;
        if ($isSuccess && ! $s->notify_backup_success) return;
        if (! $isSuccess && ! $s->notify_backup_failed) return;

        $log->loadMissing(['website', 'server']);
        $title = $isSuccess
            ? "✅ 备份成功 · {$log->website?->name}"
            : "❌ 备份失败 · {$log->website?->name}";

        $desp = $this->buildBackupDesp($log);
        $this->send($s, $title, $desp);
    }

    public function notifyAgentOffline(Server $server): void
    {
        $s = NotificationSetting::current();
        if (! $s->hasSendkey() || ! $s->notify_agent_offline) return;

        $title = "⚠️ 服务器离线 · {$server->name}";
        $desp = $this->buildOfflineDesp($server);
        $this->send($s, $title, $desp);
    }

    /**
     * 测试发送。使用极简纯文本避免下游渠道格式问题。
     * @return array{ok: bool, message: string, raw?: mixed}
     */
    public function testSend(string $sendkey): array
    {
        // 故意用最简单的纯文本 - 有些下游渠道（钉钉 / Bark / 企业微信）对 markdown / emoji 敏感
        return (new ServerChanNotifier($sendkey))->send(
            'Backup Center 通知测试',
            'Backup Center 测试消息, 收到即表示 Server 酱配置正常. 时间: ' . now()->toDateTimeString(),
        );
    }

    private function send(NotificationSetting $s, string $title, string $desp): void
    {
        try {
            (new ServerChanNotifier($s->serverchan_sendkey))->send($title, $desp);
        } catch (\Throwable $e) {
            Log::warning('NotificationDispatcher send failed', ['err' => $e->getMessage()]);
        }
    }

    private function buildBackupDesp(BackupLog $log): string
    {
        $lines = [
            '**网站**：' . ($log->website?->name ?: '未知'),
            '**服务器**：' . ($log->server?->name ?: '未知'),
            '**文件**：`' . $log->filename . '`',
            '**大小**：' . $this->fmtSize($log->size),
            '**状态**：' . $this->statusText($log->status),
            '**时间**：' . ($log->backup_at?->toDateTimeString() ?: 'n/a'),
        ];
        if ($log->error_message) {
            $lines[] = '**错误信息**：' . $log->error_message;
        }
        return implode("\n\n", $lines);
    }

    private function buildOfflineDesp(Server $server): string
    {
        return implode("\n\n", [
            '**服务器**：' . $server->name,
            '**IP**：' . ($server->ip ?: '未知'),
            '**最后心跳**：' . ($server->last_seen_at?->toDateTimeString() ?: '从未连接'),
            '**说明**：' . ($server->status_message ?: '超过阈值无心跳'),
            '**建议**：登录该服务器检查 `systemctl status backup-agent`',
        ]);
    }

    private function fmtSize(?int $bytes): string
    {
        if (! $bytes) return '—';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < count($units) - 1) { $v /= 1024; $i++; }
        return sprintf('%.2f %s', $v, $units[$i]);
    }

    private function statusText(string $status): string
    {
        return match ($status) {
            BackupLog::STATUS_SUCCESS => '成功',
            BackupLog::STATUS_FAILED  => '失败',
            BackupLog::STATUS_RUNNING => '执行中',
            default                   => $status,
        };
    }
}
