<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('servers:cleanup')->everyMinute();
Schedule::command('agent-commands:sweep-stale')->everyMinute();
Schedule::command('websites:dispatch-scheduled-sync')->everyMinute();
// 备份保留期清理：删除超期 backup_logs 并清理对应云端归档
// 定在 03:30，避开 02:00 左右的 scheduled-sync 与 03:00 的 system:backup
Schedule::command('bc:cleanup-retention')->dailyAt('03:30');

// Backup Center 自身定时备份（读 system_settings 里的动态时间）
// system_backup_enabled=1 才启用；system_backup_time 是 HH:MM
// try/catch：在 docker build 或迁移未跑时数据库表可能不存在，此时静默跳过（entrypoint 会 migrate）
try {
    $sysBackupEnabled = \App\Models\SystemSetting::get('system_backup_enabled', '0') === '1';
    $sysBackupTime = \App\Models\SystemSetting::get('system_backup_time', '03:00');
    if ($sysBackupEnabled && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $sysBackupTime)) {
        Schedule::command('system:backup')->dailyAt($sysBackupTime);
    }
} catch (\Throwable $e) {
    // 静默：build 阶段或首次迁移前，system_settings 表不存在，不注册 schedule
}
