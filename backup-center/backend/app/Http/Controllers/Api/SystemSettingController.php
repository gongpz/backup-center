<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Storage;
use App\Models\SystemSetting;
use App\Services\SystemBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => [
                'system_name'                  => SystemSetting::get('system_name', 'backup center'),
                'system_backup_enabled'        => SystemSetting::get('system_backup_enabled', '0') === '1',
                'system_backup_time'           => SystemSetting::get('system_backup_time', '03:00'),
                'agent_mirror_url'             => $this->resolveAgentMirrorUrl(),
                // 第二镜像：Cloudflare R2 免流量出站，境外机器/公开分发用
                'agent_mirror_url_r2'          => SystemSetting::get('agent_mirror_url_r2', ''),
                // 页脚配置（JSON）
                'footer_config'                => $this->getFooterConfig(),
                // 当前本地 agent-dist 版本
                'agent_dist_version'           => SystemSetting::get('agent_dist_version', ''),
            ],
        ]);
    }

    /**
     * 页脚配置：从 system_settings.footer_config 读 JSON，返回结构化数据
     */
    private function getFooterConfig(): array
    {
        $raw = SystemSetting::get('footer_config', null);
        $cfg = [];
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $cfg = $decoded;
        }
        return array_merge([
            'enabled'         => true,
            'copyright'       => '© Backup Center',
            'disclaimer'      => '',
            'custom_html'     => '',
            'show_versions'   => true,
            'show_stats'      => true,
            'icp_beian'       => '',
            'contacts'        => [],
        ], $cfg);
    }

    /**
     * 解析 Agent 镜像 URL：pool 优先，system_settings 回退（R66 兼容）
     */
    private function resolveAgentMirrorUrl(): string
    {
        try {
            $pool = \App\Models\ResourcePool::where('is_agent_mirror', true)
                ->where('status', 'enabled')
                ->first();
            if ($pool) {
                $url = $pool->publicUrlPrefix();
                if ($url !== '') return $url;
            }
        } catch (\Throwable $e) {}
        return (string) SystemSetting::get('agent_mirror_url', '');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'system_name'           => ['sometimes', 'string', 'max:64'],
            'system_backup_enabled' => ['sometimes', 'boolean'],
            'system_backup_time'    => ['sometimes', 'string', 'regex:/^([01]\d|2[0-3]):(00|10|20|30|40|50)$/'],
            'agent_mirror_url'      => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^https?:\/\//'],
            'agent_mirror_url_r2'   => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^https?:\/\//'],
            'footer_config'         => ['sometimes', 'array'],
            'footer_config.enabled'       => ['sometimes', 'boolean'],
            'footer_config.copyright'     => ['sometimes', 'nullable', 'string', 'max:200'],
            'footer_config.disclaimer'    => ['sometimes', 'nullable', 'string', 'max:5000'],
            'footer_config.custom_html'   => ['sometimes', 'nullable', 'string', 'max:2000'],
            'footer_config.show_versions' => ['sometimes', 'boolean'],
            'footer_config.show_stats'    => ['sometimes', 'boolean'],
            'footer_config.icp_beian'     => ['sometimes', 'nullable', 'string', 'max:100'],
            'footer_config.contacts'      => ['sometimes', 'array'],
            'footer_config.contacts.*.type'  => ['required_with:footer_config.contacts', 'string', 'max:32'],
            'footer_config.contacts.*.value' => ['required_with:footer_config.contacts', 'string', 'max:500'],
            'footer_config.contacts.*.label' => ['nullable', 'string', 'max:100'],
        ]);

        if (array_key_exists('system_name', $data)) {
            SystemSetting::set('system_name', $data['system_name']);
        }
        if (array_key_exists('system_backup_enabled', $data)) {
            SystemSetting::set('system_backup_enabled', $data['system_backup_enabled'] ? '1' : '0');
        }
        if (array_key_exists('system_backup_time', $data)) {
            SystemSetting::set('system_backup_time', $data['system_backup_time']);
        }
        if (array_key_exists('agent_mirror_url', $data)) {
            SystemSetting::set('agent_mirror_url', trim((string) $data['agent_mirror_url']));
        }
        if (array_key_exists('agent_mirror_url_r2', $data)) {
            SystemSetting::set('agent_mirror_url_r2', trim((string) $data['agent_mirror_url_r2']));
        }
        if (array_key_exists('footer_config', $data)) {
            SystemSetting::set('footer_config', json_encode($data['footer_config'], JSON_UNESCAPED_UNICODE));
        }

        return $this->show();
    }

    /**
     * 页脚流量统计 —— 系统级汇总数据（不是 PV/UV）
     * GET /api/system/footer-stats
     */
    public function footerStats(): JsonResponse
    {
        try {
            $servers = \App\Models\Server::count();
            $onlineServers = \App\Models\Server::where('status', 'online')->count();
            $websites = \App\Models\Website::count();
            $backupLogs = \App\Models\BackupLog::count();
            $totalSize = (int) \App\Models\BackupLog::sum('size');
            $storages = \App\Models\Storage::where('status', 'enabled')->count();
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
        return response()->json([
            'ok' => true,
            'stats' => [
                'servers'        => $servers,
                'online_servers' => $onlineServers,
                'websites'       => $websites,
                'backup_logs'    => $backupLogs,
                'total_size'     => $totalSize,
                'storages'       => $storages,
            ],
        ]);
    }

    /**
     * 一键备份 Backup Center 自身（复用 SystemBackupService，跟 CLI 定时任务同一份逻辑）
     */
    public function backupNow(SystemBackupService $svc): JsonResponse
    {
        $result = $svc->run();
        return response()->json($result, $result['ok'] ? 200 : ($result['message'] === '没有配置任何"强制备份"存储。请先在存储页把至少一家存储的"强制备份"打开' ? 422 : 500));
    }

    /**
     * @deprecated 旧的内嵌实现，已被 SystemBackupService 替代。保留至下一版删除。
     */
    public function backupNowLegacy(): JsonResponse
    {
        @set_time_limit(180);

        $mandatory = Storage::where('is_mandatory', true)
            ->where('status', Storage::STATUS_ENABLED)
            ->get();
        if ($mandatory->isEmpty()) {
            return response()->json([
                'ok'      => false,
                'message' => '没有配置任何"强制备份"存储。请先在存储页把至少一家存储的"强制备份"打开',
            ], 422);
        }

        $ts = now()->format('Y-m-d_His');
        $tmpDir = sys_get_temp_dir() . '/bc-sysbak-' . $ts;
        $dbSnap = $tmpDir . '/database.sqlite';
        $tarPath = sys_get_temp_dir() . "/bc-system-{$ts}.tar.gz";
        @mkdir($tmpDir, 0700, true);
        $tempFiles = [$dbSnap, $tarPath, $tmpDir];

        $log = [];
        $logf = function ($msg) use (&$log) { $log[] = $msg; };
        $cleanup = function () use (&$tempFiles) {
            // 先删文件，再删目录
            usort($tempFiles, fn ($a, $b) => strlen($b) - strlen($a));
            foreach ($tempFiles as $p) {
                if (is_dir($p)) @rmdir($p);
                elseif (file_exists($p)) @unlink($p);
            }
        };

        try {
            // 1) SQLite .backup（一致快照，比直接 cp 安全）
            $dbSrc = '/var/www/html/database/data/database.sqlite';
            if (! file_exists($dbSrc)) {
                $cleanup();
                return response()->json(['ok' => false, 'message' => "SQLite 文件不存在：$dbSrc"], 500);
            }
            // sqlite3 的 dot-command 必须整体作为一个参数：`.backup /path` 不能拆成两段
            exec('sqlite3 ' . escapeshellarg($dbSrc) . ' ' . escapeshellarg('.backup ' . $dbSnap) . ' 2>&1', $out, $code);
            if ($code !== 0 || ! file_exists($dbSnap)) {
                $cleanup();
                return response()->json([
                    'ok'      => false,
                    'message' => 'SQLite 快照失败：' . implode("\n", $out),
                ], 500);
            }
            $logf('✓ SQLite 快照 ' . $this->humanBytes(filesize($dbSnap)));

            // 2) 生成关键环境变量快照（bc-app 容器里没 .env 文件；APP_KEY 是从
            //    docker-compose environment 通过 ${APP_KEY} 注入的，恢复时要拿到这些值）
            $envSnapPath = $tmpDir . '/env-snapshot.txt';
            $tempFiles[] = $envSnapPath;
            $envLines = ["# Backup Center env snapshot @ " . now()->toDateTimeString()];
            $envLines[] = "# 恢复方法：把下面这些变量填回宿主机 /opt/backup-center/deploy/.env 里，然后 docker compose up -d --force-recreate app";
            $envLines[] = "# APP_KEY 是解密 storages.config 的关键 —— 丢了这个所有存储密码都废";
            foreach ([
                'APP_KEY', 'APP_URL', 'APP_NAME', 'APP_ENV', 'APP_TIMEZONE',
                'DB_CONNECTION', 'DB_DATABASE',
                'CACHE_STORE', 'SESSION_DRIVER', 'LOG_CHANNEL',
            ] as $k) {
                $v = env($k);
                if ($v !== null && $v !== '') $envLines[] = "$k=$v";
            }
            file_put_contents($envSnapPath, implode("\n", $envLines) . "\n");
            $logf('✓ 环境变量快照 ' . count($envLines) . ' 条');

            // 3) tar 打包：SQLite 快照 + env 快照（+ storage/app 如果存在且非空）
            $storageDir = '/var/www/html/storage/app';
            $hasStorage = is_dir($storageDir) && count(glob($storageDir . '/*')) > 0;
            $tarCmd = 'tar -czf ' . escapeshellarg($tarPath) .
                ' -C ' . escapeshellarg($tmpDir) . ' database.sqlite env-snapshot.txt';
            if ($hasStorage) {
                $tarCmd .= ' -C /var/www/html storage/app';
            }
            $tarCmd .= ' 2>&1';
            exec($tarCmd, $tarOut, $tarCode);
            if ($tarCode !== 0 || ! file_exists($tarPath)) {
                $cleanup();
                return response()->json([
                    'ok'      => false,
                    'message' => 'tar 打包失败：' . implode("\n", $tarOut),
                ], 500);
            }
            $tarSize = filesize($tarPath);
            $logf('✓ 打包完成 ' . $this->humanBytes($tarSize) . ' (' . basename($tarPath) . ')');

            // 3) 上传到每个强制备份存储
            $uploaded = [];
            $failed = [];
            foreach ($mandatory as $s) {
                [$ok, $msg, $remote] = $this->rcloneUploadOne($s, $tarPath, 'system-backups/' . basename($tarPath));
                if ($ok) {
                    $uploaded[] = [
                        'storage_id'   => $s->id,
                        'storage_name' => $s->name,
                        'remote_path'  => $remote,
                    ];
                    $logf("✓ 上传到 {$s->name}: $remote");
                } else {
                    $failed[] = [
                        'storage_id'   => $s->id,
                        'storage_name' => $s->name,
                        'error'        => $msg,
                    ];
                    $logf("✗ 上传到 {$s->name} 失败: $msg");
                }
            }

            $cleanup();
            return response()->json([
                'ok'       => count($uploaded) > 0,
                'filename' => basename($tarPath),
                'size'     => $tarSize,
                'uploaded' => $uploaded,
                'failed'   => $failed,
                'log'      => $log,
                'message'  => count($failed) === 0
                    ? sprintf('已备份并上传到 %d 家强制备份存储', count($uploaded))
                    : sprintf('部分成功：%d 成功 / %d 失败', count($uploaded), count($failed)),
            ]);
        } catch (\Throwable $e) {
            $cleanup();
            return response()->json([
                'ok'      => false,
                'message' => '异常：' . $e->getMessage(),
                'log'     => $log,
            ], 500);
        }
    }

    /** 用 rclone 传一个文件到某个 Storage；复用 StorageController 里的逻辑但独立实现 */
    private function rcloneUploadOne(Storage $s, string $localFile, string $remoteRelPath): array
    {
        $remote = 'sysbak_' . $s->id;
        $confPath = tempnam(sys_get_temp_dir(), 'rc_');
        $tmpFiles = [$confPath];
        try {
            $conf = $this->buildRcloneConfSection($remote, $s, $tmpFiles);
            file_put_contents($confPath, $conf);
            chmod($confPath, 0600);

            // 计算 target = remote:<base_path>/<remoteRelPath>
            // ⚠️ base_path 前导 `/` 必须保留 —— SFTP 的 `remote:/abs/path` 是绝对，`remote:rel/path` 会拼家目录导致双重路径
            $basePath = rtrim((string) $s->base_path, '/');
            $rel = ltrim($remoteRelPath, '/');
            if ($s->type === Storage::TYPE_S3) {
                // S3 的 base_path 一般不含前导 /；bucket 是必需的
                $bucket = $s->config['bucket'] ?? '';
                $prefix = trim($bucket . '/' . ltrim($basePath, '/'), '/');
                $target = $remote . ':' . $prefix . '/' . $rel;
            } else {
                // SFTP/FTP/WebDAV：保留 base_path 原样（绝对就绝对、相对就相对）
                $target = $remote . ':' . ($basePath === '' ? '' : $basePath . '/') . $rel;
            }

            $cmd = 'rclone copyto --config ' . escapeshellarg($confPath) .
                ' --contimeout 15s --timeout 120s --retries 2' .
                ' ' . escapeshellarg($localFile) .
                ' ' . escapeshellarg($target) .
                ' 2>&1';
            exec($cmd, $out, $code);
            if ($code === 0) {
                return [true, 'ok', $target];
            }
            $err = trim(implode("\n", array_slice($out, -3)));
            return [false, mb_substr($err ?: "exit=$code", 0, 400), $target];
        } finally {
            foreach ($tmpFiles as $f) {
                if ($f && file_exists($f)) @unlink($f);
            }
        }
    }

    /** 生成一段 rclone.conf；跟 StorageController 里保持一致（避免相互依赖） */
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
