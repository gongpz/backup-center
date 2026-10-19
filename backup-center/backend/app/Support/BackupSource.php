<?php

namespace App\Support;

/**
 * backup_logs.source_key 的唯一推导实现。
 *
 * 设计要点（见 .workbuddy/backup-source-migration-spec.md）：
 * - 纯函数、不查库：写入点（AgentController::report）、迁移回填、后续消费方
 *   都必须调用它，避免三处逻辑漂移。
 * - 判定顺序必须先 filename、后 full_path：自主压缩（self）的 full_path 是
 *   Agent 的临时目录（os.MkdirTemp("", "bc-self-<id>-")），永远前缀匹配不上
 *   配置的备份目录，若先做前缀匹配会全部漏成 ext:。
 */
class BackupSource
{
    /** self 网站归档：xxx-web-<ts>.tar.gz */
    public const SELF_WEB = 'self:web';

    /** self 数据库 dump：xxx-db-<ts>.sql.gz（兼容未压缩的 .sql） */
    public const SELF_DB = 'self:db';

    /** self 增量快照目录日志：xxx-snap-<ts>（无扩展名；full_path 是远端快照目录） */
    public const SELF_SNAPSHOT = 'self:snapshot';

    /** external：full_path 前缀命中 websites.backup_paths[i].path 时的前缀 */
    public const EXT_PREFIX = 'ext:';

    /**
     * 推导一条 backup_log 的来源键。
     *
     * @param string      $filename    备份文件名（不含路径）
     * @param string|null $fullPath    备份完整路径（self 备份这里是临时目录）
     * @param array       $backupPaths websites.backup_paths（每项 ['path'=>..,'pattern'=>..,'label'=>..]）
     * @return string self:web / self:db / ext:<命中的 path> / ext:（external 但匹配不到目录）
     */
    public static function derive(string $filename, ?string $fullPath, array $backupPaths): string
    {
        // ① self：文件名模式优先
        if (preg_match('/-web-.*\.tar\.gz$/i', $filename)) {
            return self::SELF_WEB;
        }
        if (preg_match('/-db-.*\.sql(\.gz)?$/i', $filename)) {
            return self::SELF_DB;
        }
        if (preg_match('/-snap-\d{8}-\d{6}$/i', $filename)) {
            return self::SELF_SNAPSHOT;
        }

        // ② external：full_path 前缀匹配配置的备份目录
        if ($fullPath !== null && $fullPath !== '') {
            foreach ($backupPaths as $bp) {
                $p = is_array($bp) ? ($bp['path'] ?? null) : null;
                if ($p !== null && $p !== '' && str_starts_with($fullPath, $p)) {
                    return self::EXT_PREFIX . $p;
                }
            }
        }

        return self::EXT_PREFIX;
    }
}
