<?php

namespace App\Services;

use App\Models\Storage;

/**
 * 共享的 rclone.conf 构建服务。
 *
 * 收敛此前散落两处的 conf 构建（CleanupExpiredBackups 兜底删除 / StorageController 探测）：
 * remote 命名沿用 bc_<id>（与 Agent 端 remoteName() 对齐，存下来的 remote_path
 * 才能被原样解析）；crypt 包裹层的 remote 行对 S3 必须拼 bucket；
 * obscure 失败绝不写明文 —— 记 warning 让调用方拒绝执行。
 */
class RcloneConfBuilder
{
    /**
     * 按 Storage 生成 rclone.conf 内容。
     *
     * @return array{conf: string, tempFiles: list<string>, warnings: list<string>}
     *   - tempFiles：SSH 私钥临时文件（0600），caller 负责 finally 清理
     *   - warnings：obscure 失败等原因；非空时 caller 必须拒绝执行（禁止明文降级）
     */
    public static function build(Storage $storage, ?string $remoteName = null): array
    {
        $c = $storage->config ?: [];
        $remote = $remoteName ?? ('bc_' . $storage->id);
        $tempFiles = [];
        $warnings = [];

        $lines = ['[' . $remote . ']'];

        switch ($storage->type) {
            case Storage::TYPE_SFTP:
                $lines[] = 'type = sftp';
                $lines[] = 'host = ' . ($c['host'] ?? '');
                if (! empty($c['port'])) $lines[] = 'port = ' . $c['port'];
                $lines[] = 'user = ' . ($c['user'] ?? '');
                if (! empty($c['password'])) {
                    self::appendObscured($lines, 'pass', $c['password'], '存储密码', $warnings);
                }
                if (! empty($c['private_key'])) {
                    $keyPath = tempnam(sys_get_temp_dir(), 'bc_sshkey_');
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
                if (! empty($c['password'])) {
                    self::appendObscured($lines, 'pass', $c['password'], '存储密码', $warnings);
                }
                if (! empty($c['tls'])) $lines[] = 'tls = true';
                if (! empty($c['explicit_tls'])) $lines[] = 'explicit_tls = true';
                if (! empty($c['no_check_certificate'])) $lines[] = 'no_check_certificate = true';
                break;
            case Storage::TYPE_WEBDAV:
                $lines[] = 'type = webdav';
                $lines[] = 'url = ' . ($c['url'] ?? '');
                if (! empty($c['vendor'])) $lines[] = 'vendor = ' . $c['vendor'];
                $lines[] = 'user = ' . ($c['user'] ?? '');
                if (! empty($c['password'])) {
                    self::appendObscured($lines, 'pass', $c['password'], '存储密码', $warnings);
                }
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

        // 加密包裹层（crypt）：仅在中心持密码（key_mode=center）时渲染，
        // key_mode=agent_local 时中心无密钥，调用方已把这些存储挡在门外。
        $enc = $c['encryption'] ?? [];
        if (! empty($enc['enabled']) && ($enc['key_mode'] ?? 'center') === 'center') {
            $lines[] = '';
            $lines[] = '[' . $remote . '_c]';
            $lines[] = 'type = crypt';
            // remote 指向底层 remote 名，必须带冒号（否则 rclone 把 remote 名当成
            // 相对本地目录，拼到 cwd 下 mkdir → permission denied）。
            // S3 还要把 bucket 拼进来（remote = bc_<id>:bucket）：crypt 只能加密
            // bucket 之后的路径，bucket 名本身不能被加密 —— 否则底层 S3 remote 会把
            // 加密后的字符串当成 bucket 名去访问 → object not found。
            $cryptRemote = $remote . ':';
            if ($storage->type === Storage::TYPE_S3) {
                $bucket = trim((string) ($c['bucket'] ?? ''), '/');
                if ($bucket !== '') {
                    $cryptRemote = $remote . ':' . $bucket;
                }
            }
            $lines[] = 'remote = ' . $cryptRemote;
            self::appendObscured($lines, 'password', (string) ($enc['password'] ?? ''), '加密密码', $warnings);
            $lines[] = 'filename_encryption = ' . (($enc['filename_encryption'] ?? 'standard') === 'off' ? 'off' : 'standard');
            $lines[] = 'directory_name_encryption = ' . (! empty($enc['directory_name_encryption']) ? 'true' : 'false');
        }

        return [
            'conf'      => implode("\n", $lines) . "\n",
            'tempFiles' => $tempFiles,
            'warnings'  => $warnings,
        ];
    }

    /** Center 容器内 rclone 是否可用 */
    public static function available(): bool
    {
        return is_executable('/usr/bin/rclone')
            || trim((string) shell_exec('command -v rclone 2>/dev/null')) !== '';
    }

    /**
     * 写一行需要 obscure 的凭证。obscure 失败时不写这一行（绝不退回明文），
     * 而是记 warning 让调用方跳过该存储。
     */
    private static function appendObscured(array &$lines, string $key, string $plain, string $label, array &$warnings): void
    {
        $obscured = self::rcloneObscure($plain);
        if ($obscured === null) {
            $warnings[] = "{$label} obscure 失败，已跳过该存储（禁止明文降级）";
            return;
        }
        $lines[] = $key . ' = ' . $obscured;
    }

    /**
     * rclone 密码需要 obscure。返回 null 表示 obscure 失败 —— 调用方必须跳过并告警，
     * 禁止退回明文写 conf（与 Go Agent 端行为对齐）。
     */
    private static function rcloneObscure(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        $cmd = 'rclone obscure ' . escapeshellarg($plain) . ' 2>/dev/null';
        $out = trim((string) shell_exec($cmd));
        return $out !== '' ? $out : null;
    }
}
