<?php

namespace App\Console\Commands;

use App\Models\AgentCommand;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 每分钟运行一次。找到所有 sync_mode=scheduled 且 schedule=当前 HH:MM 的启用网站，
 * 给该网站的 server 派发一条 sync_now 命令。
 */
class DispatchScheduledSync extends Command
{
    protected $signature = 'websites:dispatch-scheduled-sync';
    protected $description = '按每个网站的 schedule（HH:MM）派发 sync_now 命令';

    public function handle(): int
    {
        $now = now();
        $currentHM = $now->format('H:i');

        $websites = Website::where('sync_mode', 'scheduled')
            ->where('status', 'enabled')
            ->where('schedule', $currentHM)
            ->with('server')
            ->get();

        if ($websites->isEmpty()) {
            return self::SUCCESS;
        }

        // 同一个 server 只派一条 sync_now（Agent 会扫全部网站），去重
        $serverIds = $websites->pluck('server_id')->unique();
        $dispatched = 0;

        foreach ($serverIds as $sid) {
            DB::transaction(function () use ($sid, &$dispatched) {
                // 60 秒内已派过 sync_now 的服务器跳过（防止 minute cron 双触发）
                $recent = AgentCommand::where('server_id', $sid)
                    ->where('type', 'sync_now')
                    ->where('created_at', '>', now()->subSeconds(60))
                    ->exists();
                if ($recent) return;

                AgentCommand::create([
                    'server_id' => $sid,
                    'type'      => 'sync_now',
                    'status'    => AgentCommand::STATUS_PENDING,
                ]);
                $dispatched++;
            });
        }

        if ($dispatched > 0) {
            $this->info("已派发 {$dispatched} 条 sync_now（涉及 " . $websites->count() . " 个网站，触发时刻 {$currentHM}）");
        }

        return self::SUCCESS;
    }
}
