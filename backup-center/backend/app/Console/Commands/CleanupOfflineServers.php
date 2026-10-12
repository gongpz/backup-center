<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

class CleanupOfflineServers extends Command
{
    protected $signature = 'servers:cleanup';
    protected $description = '把心跳超时的 Agent 服务器标记为离线';

    public function handle(NotificationDispatcher $notifier): int
    {
        $timeout = Server::HEARTBEAT_TIMEOUT;
        $threshold = now()->subSeconds($timeout);

        // 先查出即将被标离线的服务器，再逐一更新 + 通知（不用批量 update 因为要发通知）
        $servers = Server::query()
            ->where('connect_type', Server::CONNECT_AGENT)
            ->where('status', Server::STATUS_ONLINE)
            ->where(function ($q) use ($threshold) {
                $q->whereNull('last_seen_at')
                  ->orWhere('last_seen_at', '<', $threshold);
            })
            ->get();

        foreach ($servers as $server) {
            $server->update([
                'status' => Server::STATUS_OFFLINE,
                'status_message' => "超过 {$timeout} 秒无心跳",
            ]);
            $notifier->notifyAgentOffline($server->fresh());
        }

        $count = $servers->count();
        $this->info("已标记 {$count} 台服务器为离线");
        return self::SUCCESS;
    }
}
