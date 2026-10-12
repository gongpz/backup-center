<?php

namespace App\Console\Commands;

use App\Models\AgentCommand;
use Illuminate\Console\Command;

class SweepStaleAgentCommands extends Command
{
    protected $signature = 'agent-commands:sweep-stale
        {--minutes=10 : dispatched 超过此分钟数即视为 stale}';

    protected $description = '清理长时间挂在 dispatched 的 Agent 命令（Agent 升级失败又未上报的场景），标为 failed';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $cutoff = now()->subMinutes($minutes);

        $affected = AgentCommand::where('status', AgentCommand::STATUS_DISPATCHED)
            ->where('dispatched_at', '<=', $cutoff)
            ->update([
                'status'       => AgentCommand::STATUS_FAILED,
                'error'        => "超过 {$minutes} 分钟未收到 Agent 结果上报，自动标记为 failed（sweeper）",
                'completed_at' => now(),
                'updated_at'   => now(),
            ]);

        if ($affected > 0) {
            $this->info("已 sweep {$affected} 条 stale dispatched 命令");
        }
        return self::SUCCESS;
    }
}
