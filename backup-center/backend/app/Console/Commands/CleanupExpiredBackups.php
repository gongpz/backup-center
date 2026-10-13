<?php

namespace App\Console\Commands;

use App\Models\BackupLog;
use App\Models\BackupUpload;
use App\Models\Storage;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 按 websites.retention_days 清理过期备份。
 *
 * 数据关系（已核实）：
 * - backup_uploads.backup_log_id → backup_logs cascadeOnDelete
 *   所以删 BackupLog 会级联删掉 uploads，不需要手动删 uploads。
 * - backup_uploads.remote_path 形如 bc_14:bucket/backups/站点/20260809/file.tar.gz，
 *   已经含 rclone remote 前缀（见 agent/internal/uploader/uploader.go remoteTargetPath），
 *   所以删云端文件时直接原样传给 rclone，不重新拼接路径 —— 避免与上传端不一致删错文件。
 */
class CleanupExpiredBackups extends Command
{
    protected $signature = 'bc:cleanup-retention
        {--dry-run : 只统计不删除，用于首次上线前预览}';

    protected $description = '按 retention_days 清理过期备份记录，并删除对应云端归档';

    /** 护栏：每网站单次运行删除上限，防首次上线一次性删太多 */
    private const MAX_PER_WEBSITE = 500;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rcloneReady = $this->rcloneAvailable();

        $this->info($dryRun
            ? '[dry-run] 备份保留期清理：只统计，不删除任何数据'
            : '开始按 retention_days 清理过期备份');

        if (! $rcloneReady) {
            $this->warn('⚠ 后端容器内未检测到 rclone：本次只清理数据库记录，云端归档需人工清理');
        }

        $totalLogs = 0;
        $totalRemoteOk = 0;
        $totalRemoteFail = 0;
        $skippedSites = 0;
        $touchedSites = 0;

        $websites = Website::where('status', Website::STATUS_ENABLED)
            ->whereNotNull('retention_days')
            ->orderBy('id')
            ->get();

        foreach ($websites as $website) {
            // 护栏 1：retention_days 为 null / 0 / 负数 → 跳过该网站，绝不删
            $days = (int) $website->retention_days;
            if ($days <= 0) {
                $skippedSites++;
                $this->warn(sprintf('网站「%s」retention_days=%s，已跳过', $website->name, $days));
                continue;
            }

            $cutoff = now()->subDays($days);

            $expired = BackupLog::where('website_id', $website->id)
                ->where('backup_at', '<', $cutoff)
                // 护栏 3：不删 running 的日志
                ->where('status', '!=', BackupLog::STATUS_RUNNING)
                // 护栏 2：每网站单次运行删除上限
                ->orderBy('backup_at')
                ->orderBy('id')
                ->limit(self::MAX_PER_WEBSITE)
                ->get();

            if ($expired->isEmpty()) {
                continue;
            }

            // 护栏 4：每网站至少保留最近 1 条成功备份。
            // 必须在删除「之前」判定：先算出该网站最新一条成功备份，
            // 若它落在待删集合里（本站很久没成功备份、全部超期），就从集合里剔除，
            // 确保永远留有最新一条可恢复的备份。
            $keeperId = BackupLog::where('website_id', $website->id)
                ->where('status', BackupLog::STATUS_SUCCESS)
                ->orderByDesc('backup_at')
                ->orderByDesc('id')
                ->value('id');

            if ($keeperId !== null) {
                $expired = $expired->reject(fn ($log) => (int) $log->id === (int) $keeperId)->values();
            }

            if ($expired->isEmpty()) {
                continue;
            }

            $expiredIds = $expired->pluck('id')->all();

            // 云端待删清单：只取 uploaded 且有 remote_path 的
            $uploads = BackupUpload::whereIn('backup_log_id', $expiredIds)
                ->where('status', BackupUpload::STATUS_UPLOADED)
                ->whereNotNull('remote_path')
                ->where('remote_path', '!=', '')
                ->get();

            $touchedSites++;
            $remoteOk = 0;
            $remoteFail = 0;

            if ($dryRun) {
                // dry-run：只统计，不执行 rclone、不删数据库
                $this->line(sprintf(
                    '[dry-run] 网站「%s」(id=%d, 保留 %d 天, 早于 %s)：将删除 %d 条记录 / %d 个云端文件',
                    $website->name,
                    $website->id,
                    $days,
                    $cutoff->toDateTimeString(),
                    count($expiredIds),
                    $uploads->count()
                ));
                $totalLogs += count($expiredIds);
                continue;
            }

            // 先删云端、后删数据库：数据库删掉后 uploads 会级联消失，
            // remote_path 就再也拿不到了，所以顺序不能反。
            if ($rcloneReady && $uploads->isNotEmpty()) {
                $remoteOk = $this->deleteRemoteFiles($website, $uploads);
                $remoteFail = $uploads->count() - $remoteOk;
            } elseif (! $rcloneReady && $uploads->isNotEmpty()) {
                $remoteFail = $uploads->count();
            }

            $deleted = BackupLog::whereIn('id', $expiredIds)->delete();

            $totalLogs += $deleted;
            $totalRemoteOk += $remoteOk;
            $totalRemoteFail += $remoteFail;

            $this->line(sprintf(
                '网站「%s」(id=%d, 保留 %d 天): 删除 %d 条本地记录, 云端删除成功 %d / 失败 %d',
                $website->name,
                $website->id,
                $days,
                $deleted,
                $remoteOk,
                $remoteFail
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            '%s 汇总：涉及网站 %d 个, 跳过(retention_days 无效) %d 个, 删除记录 %d 条, 云端删除成功 %d / 失败 %d',
            $dryRun ? '[dry-run]' : '',
            $touchedSites,
            $skippedSites,
            $totalLogs,
            $totalRemoteOk,
            $totalRemoteFail
        ));

        if ($totalRemoteFail > 0) {
            $this->warn("有 {$totalRemoteFail} 个云端归档未能删除，需人工清理（数据库记录已清理）");
        }

        return self::SUCCESS;
    }

    /**
     * 批量删云端文件。单个失败只记 warning 并计数，不中断整批，也不阻断数据库删除。
     *
     * @return int 成功删除的个数
     */
    private function deleteRemoteFiles(Website $website, $uploads): int
    {
        $ok = 0;
        $confCache = [];   // storage_id => rclone.conf 路径（同 storage 复用一份）
        $tempFiles = [];   // 用完统一清理（含 SSH 私钥临时文件）

        try {
            foreach ($uploads as $upload) {
                $storage = Storage::find($upload->storage_id);
                if (! $storage) {
                    $this->warnCloud($website, $upload, '对应的 Storage 记录已不存在');
                    continue;
                }

                $remotePath = (string) $upload->remote_path;

                // 前缀守卫：remote_path 必须以 bc_<storage_id>: 开头。
                // 不匹配说明数据异常，贸然执行可能删错远端文件，直接跳过。
                $expected = 'bc_' . $storage->id . ':';
                if (! str_starts_with($remotePath, $expected)) {
                    $this->warnCloud($website, $upload, "remote_path 前缀与 storage 不匹配（期望 {$expected}）");
                    continue;
                }

                if (! isset($confCache[$storage->id])) {
                    $confPath = tempnam(sys_get_temp_dir(), 'cleanup_rcloneconf_');
                    $tempFiles[] = $confPath;
                    file_put_contents($confPath, $this->buildRcloneConf($storage, $tempFiles));
                    chmod($confPath, 0600);
                    $confCache[$storage->id] = $confPath;
                }

                [$done, $message] = $this->rcloneDeleteFile($confCache[$storage->id], $remotePath);
                if ($done) {
                    $ok++;
                } else {
                    $this->warnCloud($website, $upload, $message);
                }
            }
        } finally {
            foreach ($tempFiles as $f) {
                if ($f && file_exists($f)) {
                    @unlink($f);
                }
            }
        }

        return $ok;
    }

    /**
     * rclone deletefile 删除单个远端文件。
     * remote_path 原样传入（已含 bc_<id>: 前缀），不做任何路径再拼接。
     *
     * @return array{0: bool, 1: string}
     */
    private function rcloneDeleteFile(string $confPath, string $remotePath): array
    {
        $cmd = 'rclone deletefile --config ' . escapeshellarg($confPath)
            . ' --contimeout 10s --timeout 60s --retries 1 --low-level-retries 1'
            . ' ' . escapeshellarg($remotePath)
            . ' 2>&1';

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode === 0) {
            return [true, ''];
        }

        $joined = trim(implode("\n", $output));
        $joined = preg_replace('/^\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2}\s+/m', '', $joined) ?? $joined;
        $joined = preg_replace('/^.*Failed to (deletefile|delete)[\w ]*:\s*/i', '', $joined) ?? $joined;

        return [false, mb_substr($joined !== '' ? $joined : "exit={$exitCode}", 0, 480)];
    }

    /**
     * 按 Storage 类型生成 rclone.conf 内容。
     * section 名固定为 bc_<id>，与 agent/internal/uploader/rcloneconf.go 的 remoteName() 一致，
     * 这样存下来的 remote_path（bc_<id>:...）才能被原样解析。
     * &$tempFiles 收集 SSH 私钥临时文件，由 caller 统一清理。
     */
    private function buildRcloneConf(Storage $storage, array &$tempFiles): string
    {
        $c = $storage->config ?: [];
        $lines = ['[bc_' . $storage->id . ']'];

        switch ($storage->type) {
            case Storage::TYPE_SFTP:
                $lines[] = 'type = sftp';
                $lines[] = 'host = ' . ($c['host'] ?? '');
                if (! empty($c['port'])) $lines[] = 'port = ' . $c['port'];
                $lines[] = 'user = ' . ($c['user'] ?? '');
                if (! empty($c['password'])) $lines[] = 'pass = ' . $this->rcloneObscure($c['password']);
                if (! empty($c['private_key'])) {
                    $keyPath = tempnam(sys_get_temp_dir(), 'cleanup_sshkey_');
                    $tempFiles[] = $keyPath;
                    file_put_contents($keyPath, $c['private_key']);
                    chmod($keyPath, 0600);
                    $lines[] = 'key_file = ' . $keyPath;
                }
                break;
            case Storage::TYPE_FTP:
                $lines[] = 'type = ftp';
                $lines[] = 'host = ' . ($c['host'] ?? '');
                if (! empty($c['port'])) $lines[] = 'port = ' . $c['port'];
                $lines[] = 'user = ' . ($c['user'] ?? '');
                if (! empty($c['password'])) $lines[] = 'pass = ' . $this->rcloneObscure($c['password']);
                if (! empty($c['tls'])) $lines[] = 'tls = true';
                if (! empty($c['explicit_tls'])) $lines[] = 'explicit_tls = true';
                if (! empty($c['no_check_certificate'])) $lines[] = 'no_check_certificate = true';
                break;
            case Storage::TYPE_WEBDAV:
                $lines[] = 'type = webdav';
                $lines[] = 'url = ' . ($c['url'] ?? '');
                if (! empty($c['vendor'])) $lines[] = 'vendor = ' . $c['vendor'];
                $lines[] = 'user = ' . ($c['user'] ?? '');
                if (! empty($c['password'])) $lines[] = 'pass = ' . $this->rcloneObscure($c['password']);
                break;
            case Storage::TYPE_S3:
                $lines[] = 'type = s3';
                $lines[] = 'provider = Other';
                $lines[] = 'endpoint = ' . ($c['endpoint'] ?? '');
                if (! empty($c['region'])) $lines[] = 'region = ' . $c['region'];
                $lines[] = 'access_key_id = ' . ($c['access_key_id'] ?? '');
                $lines[] = 'secret_access_key = ' . ($c['secret_access_key'] ?? '');
                if (! empty($c['path_style'])) $lines[] = 'force_path_style = true';
                // 与 Agent 端一致：绕过 CreateBucket 权限探测（R2/COS/OSS 的 IAM 一般不给）
                $lines[] = 'no_check_bucket = true';
                break;
            default:
                // 不支持的类型也要生成一个可解析的 section，否则 rclone 找不到 remote
                $lines[] = 'type = ' . $storage->type;
                break;
        }

        return implode("\n", $lines) . "\n";
    }

    /** rclone 密码需要 obscure；失败时退回明文（与 StorageController 保持一致） */
    private function rcloneObscure(string $plain): string
    {
        $cmd = 'rclone obscure ' . escapeshellarg($plain) . ' 2>/dev/null';
        $out = trim((string) shell_exec($cmd));
        return $out !== '' ? $out : $plain;
    }

    private function rcloneAvailable(): bool
    {
        return is_executable('/usr/bin/rclone')
            || trim((string) shell_exec('command -v rclone 2>/dev/null')) !== '';
    }

    /** 云端删除失败：记 warning 日志 + 控制台提示，但不中断整批 */
    private function warnCloud(Website $website, BackupUpload $upload, string $reason): void
    {
        Log::warning('bc:cleanup-retention 云端归档删除失败', [
            'website_id'   => $website->id,
            'website_name' => $website->name,
            'backup_log_id' => $upload->backup_log_id,
            'storage_id'   => $upload->storage_id,
            'remote_path'  => $upload->remote_path,
            'reason'       => $reason,
        ]);

        $this->warn(sprintf(
            '  ! 云端删除失败 storage=%s remote_path=%s : %s',
            $upload->storage_id,
            $upload->remote_path,
            $reason
        ));
    }
}