<?php

namespace App\Services;

use App\Models\Storage;
use Illuminate\Support\Carbon;

/**
 * Backup Center 自身备份逻辑（SQLite 快照 + env 快照 + tar → rclone 上传到强制备份存储）
 * 抽出成 Service 供 Controller（一键手动）和 Console Command（定时自动）共用。
 */
class SystemBackupService
{
    /**
     * 运行一次系统备份。返回结构：
     *   ['ok' => bool, 'filename' => string, 'size' => int,
     *    'uploaded' => [...], 'failed' => [...], 'log' => [...], 'message' => string]
     */
    public function run(): array
    {
        @set_time_limit(180);

        $mandatory = Storage::where('is_mandatory', true)
            ->where('status', Storage::STATUS_ENABLED)
            ->get();
        if ($mandatory->isEmpty()) {
            return [
                'ok'      => false,
                'message' => '没有配置任何"强制备份"存储。请先在存储页把至少一家存储的"强制备份"打开',
                'log'     => [],
                'uploaded'=> [],
                'failed'  => [],
            ];
        }

        $ts = Carbon::now()->format('Y-m-d_His');
        $tmpDir  = sys_get_temp_dir() . '/bc-sysbak-' . $ts;
        $dbSnap  = $tmpDir . '/database.sqlite';
        $envSnap = $tmpDir . '/env-snapshot.txt';
        $tarPath = sys_get_temp_dir() . "/bc-system-{$ts}.tar.gz";
        @mkdir($tmpDir, 0700, true);
        $tempFiles = [$dbSnap, $envSnap, $tarPath, $tmpDir];

        $log = [];
        $logf = function ($msg) use (&$log) { $log[] = $msg; };
        $cleanup = function () use (&$tempFiles) {
            usort($tempFiles, fn ($a, $b) => strlen($b) - strlen($a));
            foreach ($tempFiles as $p) {
                if (is_dir($p)) @rmdir($p);
                elseif (file_exists($p)) @unlink($p);
            }
        };

        try {
            // 1) SQLite 一致快照
            $dbSrc = '/var/www/html/database/data/database.sqlite';
            if (! file_exists($dbSrc)) {
                $cleanup();
                return ['ok' => false, 'message' => "SQLite 不存在：$dbSrc", 'log' => $log, 'uploaded'=>[], 'failed'=>[]];
            }
            exec('sqlite3 ' . escapeshellarg($dbSrc) . ' ' . escapeshellarg('.backup ' . $dbSnap) . ' 2>&1', $out, $code);
            if ($code !== 0 || ! file_exists($dbSnap)) {
                $cleanup();
                return ['ok' => false, 'message' => 'SQLite 快照失败：' . implode("\n", $out), 'log' => $log, 'uploaded'=>[], 'failed'=>[]];
            }
            $logf('✓ SQLite 快照 ' . $this->humanBytes(filesize($dbSnap)));

            // 2) env snapshot（容器里 .env 没有文件，走 env() 拿关键变量）
            $envLines = ["# Backup Center env snapshot @ " . Carbon::now()->toDateTimeString()];
            $envLines[] = "# 恢复方法：把下面这些变量填回宿主机 /opt/backup-center/deploy/.env 里，然后 docker compose up -d --force-recreate app";
            $envLines[] = "# APP_KEY 是解密 storages.config 的关键 —— 丢了这个所有存储密码都废";
            $envKeys = ['APP_KEY', 'APP_URL', 'APP_NAME', 'APP_ENV', 'APP_TIMEZONE',
                        'DB_CONNECTION', 'DB_DATABASE', 'CACHE_STORE', 'SESSION_DRIVER', 'LOG_CHANNEL'];
            foreach ($envKeys as $k) {
                $v = env($k);
                if ($v !== null && $v !== '') $envLines[] = "$k=$v";
            }
            file_put_contents($envSnap, implode("\n", $envLines) . "\n");
            $logf('✓ 环境变量快照 ' . count($envLines) . ' 条');

            // 3) tar 打包
            $storageDir = '/var/www/html/storage/app';
            $hasStorage = is_dir($storageDir) && count(glob($storageDir . '/*')) > 0;
            $tarCmd = 'tar -czf ' . escapeshellarg($tarPath) .
                ' -C ' . escapeshellarg($tmpDir) . ' database.sqlite env-snapshot.txt';
            if ($hasStorage) $tarCmd .= ' -C /var/www/html storage/app';
            $tarCmd .= ' 2>&1';
            exec($tarCmd, $tarOut, $tarCode);
            if ($tarCode !== 0 || ! file_exists($tarPath)) {
                $cleanup();
                return ['ok' => false, 'message' => 'tar 打包失败：' . implode("\n", $tarOut), 'log' => $log, 'uploaded'=>[], 'failed'=>[]];
            }
            $tarSize = filesize($tarPath);
            $logf('✓ 打包完成 ' . $this->humanBytes($tarSize) . ' (' . basename($tarPath) . ')');

            // 4) 上传到每个强制备份存储
            $uploaded = [];
            $failed = [];
            foreach ($mandatory as $s) {
                [$ok, $msg, $remote] = $this->uploadOne($s, $tarPath, 'system-backups/' . basename($tarPath));
                if ($ok) {
                    $uploaded[] = ['storage_id' => $s->id, 'storage_name' => $s->name, 'remote_path' => $remote];
                    $logf("✓ 上传到 {$s->name}: $remote");
                } else {
                    $failed[] = ['storage_id' => $s->id, 'storage_name' => $s->name, 'error' => $msg];
                    $logf("✗ 上传到 {$s->name} 失败: $msg");
                }
            }

            $cleanup();
            return [
                'ok'       => count($uploaded) > 0,
                'filename' => basename($tarPath),
                'size'     => $tarSize,
                'uploaded' => $uploaded,
                'failed'   => $failed,
                'log'      => $log,
                'message'  => count($failed) === 0
                    ? sprintf('已备份并上传到 %d 家强制备份存储', count($uploaded))
                    : sprintf('部分成功：%d 成功 / %d 失败', count($uploaded), count($failed)),
            ];
        } catch (\Throwable $e) {
            $cleanup();
            return ['ok' => false, 'message' => '异常：' . $e->getMessage(), 'log' => $log, 'uploaded'=>[], 'failed'=>[]];
        }
    }

    private function uploadOne(Storage $s, string $localFile, string $remoteRelPath): array
    {
        $remote = 'sysbak_' . $s->id;
        $confPath = tempnam(sys_get_temp_dir(), 'rc_');
        $tmpFiles = [$confPath];
        try {
            $conf = $this->buildRcloneConfSection($remote, $s, $tmpFiles);
            file_put_contents($confPath, $conf);
            chmod($confPath, 0600);

            $basePath = rtrim((string) $s->base_path, '/');
            $rel = ltrim($remoteRelPath, '/');
            if ($s->type === Storage::TYPE_S3) {
                $bucket = $s->config['bucket'] ?? '';
                $prefix = trim($bucket . '/' . ltrim($basePath, '/'), '/');
                $target = $remote . ':' . $prefix . '/' . $rel;
            } else {
                $target = $remote . ':' . ($basePath === '' ? '' : $basePath . '/') . $rel;
            }

            $cmd = 'rclone copyto --config ' . escapeshellarg($confPath) .
                ' --contimeout 15s --timeout 120s --retries 2' .
                ' ' . escapeshellarg($localFile) .
                ' ' . escapeshellarg($target) . ' 2>&1';
            exec($cmd, $out, $code);
            if ($code === 0) return [true, 'ok', $target];
            $err = trim(implode("\n", array_slice($out, -3)));
            return [false, mb_substr($err ?: "exit=$code", 0, 400), $target];
        } finally {
            foreach ($tmpFiles as $f) {
                if ($f && file_exists($f)) @unlink($f);
            }
        }
    }

    private function buildRcloneConfSection(string $remote, Storage $s, array &$tempFiles): string
    {
        $c = $s->config;
        $lines = ["[{$remote}]"];
        switch ($s->type) {
            case Storage::TYPE_SFTP:
                $lines[] = 'type = sftp';
                $lines[] = 'host = ' . ($c['host'] ?? '');
                if (! empty($c['port'])) $lines[] = 'port = ' . $c['port'];
                $lines[] = 'user = ' . ($c['user'] ?? '');
                if (! empty($c['password'])) $lines[] = 'pass = ' . $this->rcloneObscure($c['password']);
                if (! empty($c['private_key'])) {
                    $keyPath = tempnam(sys_get_temp_dir(), 'sshkey_');
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
                $lines[] = 'region = ' . ($c['region'] ?? 'us-east-1');
                $lines[] = 'access_key_id = ' . ($c['access_key_id'] ?? '');
                $lines[] = 'secret_access_key = ' . ($c['secret_access_key'] ?? '');
                if (! empty($c['path_style'])) $lines[] = 'force_path_style = true';
                // 关键：绕过 CreateBucket 权限探测（R2/COS/OSS 的 IAM 一般不给）
                $lines[] = 'no_check_bucket = true';
                break;
        }
        return implode("\n", $lines) . "\n";
    }

    private function rcloneObscure(string $plain): string
    {
        $out = trim((string) shell_exec('rclone obscure ' . escapeshellarg($plain) . ' 2>/dev/null'));
        return $out !== '' ? $out : $plain;
    }

    private function humanBytes(int $n): string
    {
        $u = ['B', 'KB', 'MB', 'GB'];
        $i = 0; $v = $n;
        while ($v >= 1024 && $i < count($u) - 1) { $v /= 1024; $i++; }
        return sprintf('%.1f %s', $v, $u[$i]);
    }
}
