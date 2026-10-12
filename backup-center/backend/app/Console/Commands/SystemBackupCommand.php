<?php

namespace App\Console\Commands;

use App\Services\SystemBackupService;
use Illuminate\Console\Command;

class SystemBackupCommand extends Command
{
    protected $signature = 'system:backup';
    protected $description = '备份 Backup Center 自身（SQLite + env 快照 + storage）到所有强制备份存储';

    public function handle(SystemBackupService $svc): int
    {
        $this->info('开始系统备份...');
        $result = $svc->run();

        foreach ($result['log'] ?? [] as $line) {
            $this->line($line);
        }

        if (! $result['ok']) {
            $this->error($result['message'] ?? '备份失败');
            return self::FAILURE;
        }

        $this->info($result['message']);
        return self::SUCCESS;
    }
}
