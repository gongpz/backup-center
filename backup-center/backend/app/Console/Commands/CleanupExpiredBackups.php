<?php

namespace App\Console\Commands;

use App\Models\AgentCommand;
use App\Models\BackupLog;
use App\Models\BackupUpload;
use App\Models\Server;
use App\Models\Storage;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 按 websites.retention_days 清理过期备份。
 *
 * 两阶段：
 *   阶段一 dispatchDeletions()  —— 算出超期集合，Server.connect_type=agent 的派发给 Agent
 *                                  （cleanup_retention 指令），非 agent 的走 Center 侧 rclone 兜底；
 *                                  本阶段绝不删数据库行。
 *   阶段二 reconcileCompleted() —— 指令 success 才删对应的 backup_logs 行，failed 保留行并告警。
 *   「先删云端、后删数据库」的顺序不变：数据库删掉后 uploads 会级联消失，
 *   remote_path 就再也拿不到了。
 *
 * 数据关系（已核实）：
 * - backup_uploads.backup_log_id → backup_logs cascadeOnDelete
 *   所以删 BackupLog 会级联删掉 uploads，不需要手动删 uploads。
 * - backup_uploads.remote_path 形如 bc_14:bucket/backups/站点/20260809/file.tar.gz，
 *   已经含 rclone remote 前缀（见 agent/internal/uploader/uploader.go remoteTargetPath），
 *   所以删云端文件时直接原样传给 rclone，不重新拼接路径 —— 避免与上传端不一致删错文件。
 * - 开启存储加密后新产物的前缀是 bc_14_c:（crypt 包裹层），两者都合法，rclone 按前缀自动分流。
 */
class CleanupExpiredBackups extends Command
{
    protected $signature = 'bc:cleanup-retention
        {--dry-run : 只统计不删除，用于首次上线前预览}';

    protected $description = '按 retention_days 清理过期备份记录，并删除对应云端归档';

    /** 护栏：每网站单次运行删除上限，防首次上线一次性删太多 */
    private const MAX_PER_WEBSITE = 500;

    /** 单个 cleanup_retention 命令携带的文件数上限，避免 params 过大 */
    private const MAX_FILES_PER_COMMAND = 200;

    /** 同一 server 在这个时间窗内已有 pending/dispatched 的清理指令则不再重复派发 */
    private const DISPATCH_DEDUPE_HOURS = 24;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rcloneReady = $this->rcloneAvailable();

        $this->info($dryRun
            ? '[dry-run] 备份保留期清理：只统计，不删除任何数据'
            : '开始按 retention_days 清理过期备份');

        if (! $rcloneReady) {
            $this->warn('⚠ 后端容器内未检测到 rclone：Agent 服务器不受影响，非 Agent 服务器的云端归档需人工清理');
        }

        $summary = $this->dispatchDeletions($dryRun, $rcloneReady);

        if (! $dryRun) {
            $reconciled = $this->reconcileCompleted();
            $summary['deleted'] += $reconciled['deleted'];
            $summary['reconciled'] = $reconciled['commands'];
            $summary['failed'] = $reconciled['failed'];
        }

        $this->newLine();
        $this->info(sprintf(
            '%s 汇总：涉及网站 %d 个, 跳过(retention_days 无效) %d 个, 删除记录 %d 条, '
            . '云端删除成功 %d / 失败 %d, 派发 Agent 清理指令 %d 条, 对账指令 %d 条（其中失败 %d 条）',
            $dryRun ? '[dry-run]' : '',
            $summary['touchedSites'],
            $summary['skippedSites'],
            $summary['deleted'],
            $summary['remoteOk'],
            $summary['remoteFail'],
            $summary['dispatched'],
            $summary['reconciled'],
            $summary['failed'],
        ));

        if ($summary['manual'] !== []) {
            $this->warn('以下归档需要人工清理（Agent 本地密钥加密 + 该站无 Agent，中心无法构建 rclone conf）：');
            foreach ($summary['manual'] as $line) {
                $this->warn('  - ' . $line);
            }
        }

        if ($summary['remoteFail'] > 0) {
            $this->warn("有 {$summary['remoteFail']} 个云端归档未能删除，需人工清理");
        }

        return self::SUCCESS;
    }

    /* ==================================================================
     * 阶段一：算超期集合 → 派发/删除云端 →（非 agent）删数据库行
     * ================================================================== */

    /**
     * @return array{touchedSites:int, skippedSites:int, deleted:int, remoteOk:int, remoteFail:int, dispatched:int, reconciled:int, failed:int, manual:list<string>}
     */
    private function dispatchDeletions(bool $dryRun, bool $rcloneReady): array
    {
        $out = [
            'touchedSites' => 0, 'skippedSites' => 0, 'deleted' => 0,
            'remoteOk' => 0, 'remoteFail' => 0, 'dispatched' => 0,
            'reconciled' => 0, 'failed' => 0, 'manual' => [],
        ];

        $websites = Website::where('status', Website::STATUS_ENABLED)
            ->whereNotNull('retention_days')
            ->orderBy('id')
            ->get();

        // 最近已经派发给 Agent 的日志集合（跨网站算一次就够，见 recentLogIds()）
        $recentLogIds = $this->recentLogIds();

        foreach ($websites as $website) {
            // 护栏 1：retention_days 为 null / 0 / 负数 → 跳过该网站，绝不删
            $days = (int) $website->retention_days;
            if ($days <= 0) {
                $out['skippedSites']++;
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

            // 护栏 4：每个 source_key 分组各保留最近 1 条成功备份。
            // 必须在删除「之前」判定。
            //
            // 为什么按 source_key 而不是按网站：websites.backup_paths 支持多个备份目录，
            // 只按网站保 1 条时，某个目录（如 P2）的全部备份可能都比 P1 的最新一条旧，
            // 于是 P2 的备份会被整组删光 —— 该目录再无可恢复的备份。按来源分组保 1 条
            // 可以杜绝这种情况。
            //
            // source_key 非空且默认 ''，解析不出来的老数据全部落在同一个组里，
            // 至少保住其中最新 1 条 → 保留量只增不减，不会比改动前更危险。
            //
            // 实现说明：分两级聚合（先取组内 MAX(backup_at)，再在该时刻的行里取 MAX(id)），
            // 这样同一秒多条记录的并列也能稳定取到 id 最大的那条。
            $keeperIds = DB::table('backup_logs as bl')
                ->joinSub(
                    DB::table('backup_logs')
                        ->select('source_key')
                        ->selectRaw('MAX(backup_at) AS max_backup_at')
                        ->where('website_id', $website->id)
                        ->where('status', BackupLog::STATUS_SUCCESS)
                        ->groupBy('source_key'),
                    'latest',
                    function ($join) {
                        $join->on('bl.source_key', '=', 'latest.source_key')
                            ->on('bl.backup_at', '=', 'latest.max_backup_at');
                    }
                )
                ->where('bl.website_id', $website->id)
                ->where('bl.status', BackupLog::STATUS_SUCCESS)
                ->groupBy('bl.source_key')
                ->selectRaw('MAX(bl.id) AS keeper_id')
                ->pluck('keeper_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($keeperIds !== []) {
                $keeperLookup = array_fill_keys($keeperIds, true);
                $expired = $expired
                    ->reject(fn ($log) => isset($keeperLookup[(int) $log->id]))
                    ->values();
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
                ->get()
                ->groupBy('backup_log_id');

            $out['touchedSites']++;

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] 网站「%s」(id=%d, 保留 %d 天, 早于 %s)：将删除 %d 条记录 / %d 个云端文件',
                    $website->name,
                    $website->id,
                    $days,
                    $cutoff->toDateTimeString(),
                    count($expiredIds),
                    $uploads->flatten()->count()
                ));
                continue;
            }

            $site = [
                'remoteOk' => 0, 'remoteFail' => 0,
                'agentFiles' => [], 'agentCommands' => 0,   // server_id => files[]
                'centerFiles' => [],                        // BackupUpload[]
                'rows' => [],                               // 本轮可删行的 BackupLog id
            ];

            foreach ($expired as $log) {
                $logId = (int) $log->id;
                $files = $uploads[$logId] ?? collect();

                // 没有任何云端归档：只剩中心侧记录，直接删
                if ($files->isEmpty()) {
                    $site['rows'][] = $logId;
                    continue;
                }

                // 已派发/已成功未对账：等下一次调度时由阶段二对账，这里不重复造指令
                if (isset($recentLogIds[$logId])) {
                    continue;
                }

                $server = $this->resolveServer($log, $website);

                if ($server !== null && $server->connect_type === Server::CONNECT_AGENT) {
                    foreach ($files as $upload) {
                        $storage = Storage::find($upload->storage_id);
                        if ($storage === null) {
                            $this->warnCloud($website, $upload, '对应的 Storage 记录已不存在');
                            continue;
                        }
                        // 前缀守卫同样适用于下发链路：不匹配的 remote_path 绝不下发给 Agent 去删
                        if (! $this->remotePathMatches((string) $upload->remote_path, $storage)) {
                            $this->warnCloud($website, $upload, 'remote_path 前缀与 storage 不匹配，已跳过');
                            continue;
                        }
                        $site['agentFiles'][$server->id][] = [
                            'log_id'      => $logId,
                            'remote_path' => (string) $upload->remote_path,
                        ];
                    }
                    continue;
                }

                // 非 Agent 服务器：Center 只有在"能凭自身配置构建出 rclone conf"时才出手。
                // encryption + key_mode=agent_local 的存储中心没有密钥，删不了也不能删错，
                // 记进人工清单，并且这条日志本轮不删行（避免留下"记录没了、云端还在"的孤儿）。
                $rowBlocked = false;
                foreach ($files as $upload) {
                    $storage = Storage::find($upload->storage_id);
                    if ($storage === null) {
                        $this->warnCloud($website, $upload, '对应的 Storage 记录已不存在');
                        continue;
                    }
                    if (! $this->remotePathMatches((string) $upload->remote_path, $storage)) {
                        $this->warnCloud($website, $upload, 'remote_path 前缀与 storage 不匹配，已跳过');
                        continue;
                    }
                    if (! $this->centerCanBuildConf($storage)) {
                        $reason = '存储「' . $storage->name . '」为 Agent 本地密钥加密，该站无 Agent，中心无法删除';
                        $this->warnCloud($website, $upload, $reason);
                        $out['manual'][] = sprintf(
                            '网站「%s」log_id=%d storage=%d remote_path=%s',
                            $website->name, $logId, $storage->id, $upload->remote_path
                        );
                        $rowBlocked = true;
                        continue;
                    }
                    $site['centerFiles'][] = $upload;
                }

                if (! $rowBlocked) {
                    $site['rows'][] = $logId;
                }
            }

            // 1) Agent 服务器：只派发指令，不删任何数据库行（等 Agent 上报成功才删）
            foreach ($site['agentFiles'] as $serverId => $files) {
                $site['agentCommands'] += $this->dispatchAgentCleanup($website, (int) $serverId, $files);
            }

            // 2) 非 Agent 服务器：沿用 Center 侧 rclone 兜底（受 rclone 可用性降级约束）
            if ($site['centerFiles'] !== []) {
                if ($rcloneReady) {
                    $site['remoteOk'] = $this->deleteRemoteFiles($website, $site['centerFiles']);
                    $site['remoteFail'] = count($site['centerFiles']) - $site['remoteOk'];
                } else {
                    $site['remoteFail'] = count($site['centerFiles']);
                }
            }

            // 3) 只有"所有云端文件都已处理完（且没进人工清单）"的日志才在这一阶段删行
            if ($site['rows'] !== []) {
                $out['deleted'] += BackupLog::whereIn('id', $site['rows'])->delete();
            }

            $out['remoteOk']    += $site['remoteOk'];
            $out['remoteFail']  += $site['remoteFail'];
            $out['dispatched']  += $site['agentCommands'];

            $this->line(sprintf(
                '网站「%s」(id=%d, 保留 %d 天): 超期 %d 条, 删除记录 %d 条, '
                . '云端删除成功 %d / 失败 %d, 派发 Agent 清理指令 %d 条',
                $website->name,
                $website->id,
                $days,
                count($expiredIds),
                count($site['rows']),
                $site['remoteOk'],
                $site['remoteFail'],
                $site['agentCommands']
            ));
        }

        return $out;
    }

    /**
     * 最近 DISPATCH_DEDUPE_HOURS 小时内已被交给 Agent 处理的 log_id 集合
     * （pending/dispatched = 还在办，success = 已删云端但还没对账）。
     * failed 不计入 —— 失败的下一轮要重新派发重算。
     *
     * @return array<int, true>
     */
    private function recentLogIds(): array
    {
        $ids = [];
        $commands = AgentCommand::where('type', AgentCommand::TYPE_CLEANUP_RETENTION)
            ->where('created_at', '>', now()->subHours(self::DISPATCH_DEDUPE_HOURS))
            ->whereIn('status', [
                AgentCommand::STATUS_PENDING,
                AgentCommand::STATUS_DISPATCHED,
                AgentCommand::STATUS_SUCCESS,
            ])
            ->get(['params']);

        foreach ($commands as $cmd) {
            foreach ($cmd->params['files'] ?? [] as $file) {
                $ids[(int) ($file['log_id'] ?? 0)] = true;
            }
        }
        unset($ids[0]);

        return $ids;
    }

    /**
     * 给 Agent 服务器派发 cleanup_retention 指令（≤200 文件/条）。
     * 同一 server DISPATCH_DEDUPE_HOURS 小时内已有 pending/dispatched 的同类型指令则整站跳过，
     * 防止 Agent 掉线期间每天堆积重复指令。
     *
     * @param  list<array{log_id:int, remote_path:string}> $files
     * @return int 创建的指令数
     */
    private function dispatchAgentCleanup(Website $website, int $serverId, array $files): int
    {
        $busy = AgentCommand::where('server_id', $serverId)
            ->where('type', AgentCommand::TYPE_CLEANUP_RETENTION)
            ->whereIn('status', [AgentCommand::STATUS_PENDING, AgentCommand::STATUS_DISPATCHED])
            ->where('created_at', '>', now()->subHours(self::DISPATCH_DEDUPE_HOURS))
            ->exists();
        if ($busy) {
            $this->line(sprintf(
                '  网站「%s」server=%d 已有未完成的清理指令（%d 小时内），本次跳过派发',
                $website->name, $serverId, self::DISPATCH_DEDUPE_HOURS
            ));
            return 0;
        }

        $created = 0;
        foreach (array_chunk($files, self::MAX_FILES_PER_COMMAND) as $chunk) {
            AgentCommand::create([
                'server_id' => $serverId,
                'type'      => AgentCommand::TYPE_CLEANUP_RETENTION,
                // 红线：只给 log_id + remote_path，绝不带任何凭据 / 加密密钥
                'params'    => ['files' => array_values($chunk)],
                'status'    => AgentCommand::STATUS_PENDING,
            ]);
            $created++;
        }

        return $created;
    }

    /* ==================================================================
     * 阶段二：对账 —— 只有 Agent 上报 success 才删数据库行
     * ================================================================== */

    /**
     * @return array{deleted:int, commands:int, failed:int}
     */
    private function reconcileCompleted(): array
    {
        $commands = AgentCommand::where('type', AgentCommand::TYPE_CLEANUP_RETENTION)
            ->whereNull('reconciled_at')
            ->whereIn('status', [AgentCommand::STATUS_SUCCESS, AgentCommand::STATUS_FAILED])
            ->orderBy('id')
            ->get();

        if ($commands->isEmpty()) {
            return ['deleted' => 0, 'commands' => 0, 'failed' => 0];
        }

        $deleted = 0;
        $failed  = 0;

        foreach ($commands as $cmd) {
            $logIds = collect($cmd->params['files'] ?? [])
                ->pluck('log_id')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($cmd->status === AgentCommand::STATUS_FAILED) {
                // 行保留：下次运行会重新算出来并重新派发（Agent 侧对 not-found 视为已删，故幂等）
                $failed++;
                $summary = mb_substr((string) ($cmd->error ?: $cmd->output ?: ''), 0, 300);
                $this->warn(sprintf(
                    '  清理指令 #%d 失败（server=%d, %d 个归档），数据库行保留待下轮重派：%s',
                    $cmd->id, $cmd->server_id, count($logIds), $summary
                ));
                Log::warning('bc:cleanup-retention Agent 清理失败，行保留', [
                    'command_id' => $cmd->id,
                    'server_id'  => $cmd->server_id,
                    'log_ids'    => $logIds,
                    'output'     => $summary,
                ]);
                $cmd->update(['reconciled_at' => now()]);
                continue;
            }

            // success：只有当这条日志的"所有"清理指令都成功时才删行
            // （同一日志可能因分块落在多条指令里）
            $ready = array_values(array_filter(
                $logIds,
                fn ($logId) => ! $this->hasPendingSibling($cmd->id, $logId)
            ));

            if ($ready !== []) {
                $deleted += BackupLog::whereIn('id', $ready)->delete();
            }

            $cmd->update(['reconciled_at' => now()]);
        }

        return ['deleted' => $deleted, 'commands' => $commands->count(), 'failed' => $failed];
    }

    /**
     * 该日志是否还有别的 cleanup_retention 指令没走到 success —— 有则暂缓删行，
     * 避免"第一条成功就把行删了，第二条还在删同一个备份的另一份云端副本"。
     */
    private function hasPendingSibling(int $exceptCommandId, int $logId): bool
    {
        $siblings = AgentCommand::where('type', AgentCommand::TYPE_CLEANUP_RETENTION)
            ->where('id', '!=', $exceptCommandId)
            ->where('created_at', '>', now()->subDays(7))
            ->get(['id', 'status', 'params']);

        foreach ($siblings as $s) {
            $ids = collect($s->params['files'] ?? [])->pluck('log_id')->map(fn ($i) => (int) $i)->all();
            if (in_array($logId, $ids, true) && $s->status !== AgentCommand::STATUS_SUCCESS) {
                return true;
            }
        }

        return false;
    }

    /* ==================================================================
     * Center 侧 rclone 兜底（非 Agent 服务器）
     * ================================================================== */

    /** 该日志应该由哪台 Server 负责（优先日志自己记的 server，回落网站的 server） */
    private function resolveServer(BackupLog $log, Website $website): ?Server
    {
        if (! empty($log->server_id)) {
            return Server::find($log->server_id);
        }
        return $website->server_id ? Server::find($website->server_id) : null;
    }

    /**
     * Center 能否凭自身配置构建 rclone conf 删掉该存储上的文件。
     * Agent 本地密钥（key_mode=agent_local）加密时中心没有密钥 ⇒ 不能。
     */
    private function centerCanBuildConf(Storage $storage): bool
    {
        $enc = $storage->config['encryption'] ?? [];
        if (empty($enc['enabled'])) {
            return true;
        }
        return ($enc['key_mode'] ?? 'center') === 'center';
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

                // 前缀守卫：remote_path 必须以 bc_<storage_id>: 或 bc_<storage_id>_c:（加密）开头。
                // 不匹配说明数据异常，贸然执行可能删错远端文件，直接跳过。
                if (! $this->remotePathMatches($remotePath, $storage)) {
                    $expected = 'bc_' . $storage->id . ':';
                    $this->warnCloud($website, $upload, "remote_path 前缀与 storage 不匹配（期望 {$expected} 或 bc_{$storage->id}_c:）");
                    continue;
                }

                if (! isset($confCache[$storage->id])) {
                    $warnings = [];
                    $confPath = tempnam(sys_get_temp_dir(), 'cleanup_rcloneconf_');
                    $tempFiles[] = $confPath;
                    file_put_contents($confPath, $this->buildRcloneConf($storage, $tempFiles, $warnings));
                    chmod($confPath, 0600);
                    if ($warnings !== []) {
                        // obscure 失败：conf 里缺凭证，继续删会失败或误删 —— 跳过该存储全部文件
                        $this->warnCloud($website, $upload, implode('；', $warnings));
                        $confCache[$storage->id] = null;
                        continue;
                    }
                    $confCache[$storage->id] = $confPath;
                }
                if ($confCache[$storage->id] === null) {
                    $this->warnCloud($website, $upload, '该存储的 rclone 配置无法安全生成（obscure 失败），已跳过');
                    continue;
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

    /** remote_path 前缀守卫：兼容未加密的 bc_<id>: 与加密的 bc_<id>_c: */
    private function remotePathMatches(string $remotePath, Storage $storage): bool
    {
        return str_starts_with($remotePath, 'bc_' . $storage->id . ':')
            || str_starts_with($remotePath, 'bc_' . $storage->id . '_c:');
    }

    /**
     * rclone deletefile 删除单个远端文件。
     * remote_path 原样传入（已含 bc_<id>: 或 bc_<id>_c: 前缀），不做任何路径再拼接。
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
     * 开启加密且中心持密码时额外渲染 bc_<id>_c（crypt 包裹层），remote_path 带 _c 前缀时自动走它。
     * &$tempFiles 收集 SSH 私钥临时文件，由 caller 统一清理；
     * &$warnings 收集"无法安全生成配置"的原因（obscure 失败）——有 warning 时 caller 必须跳过该存储。
     */
    private function buildRcloneConf(Storage $storage, array &$tempFiles, array &$warnings = []): string
    {
        $c = $storage->config ?: [];
        $remote = 'bc_' . $storage->id;
        $lines = ['[' . $remote . ']'];

        switch ($storage->type) {
            case Storage::TYPE_SFTP:
                $lines[] = 'type = sftp';
                $lines[] = 'host = ' . ($c['host'] ?? '');
                if (! empty($c['port'])) $lines[] = 'port = ' . $c['port'];
                $lines[] = 'user = ' . ($c['user'] ?? '');
                if (! empty($c['password'])) $this->appendObscured($lines, 'pass', $c['password'], '存储密码', $warnings);
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
                if (! empty($c['password'])) $this->appendObscured($lines, 'pass', $c['password'], '存储密码', $warnings);
                if (! empty($c['tls'])) $lines[] = 'tls = true';
                if (! empty($c['explicit_tls'])) $lines[] = 'explicit_tls = true';
                if (! empty($c['no_check_certificate'])) $lines[] = 'no_check_certificate = true';
                break;
            case Storage::TYPE_WEBDAV:
                $lines[] = 'type = webdav';
                $lines[] = 'url = ' . ($c['url'] ?? '');
                if (! empty($c['vendor'])) $lines[] = 'vendor = ' . $c['vendor'];
                $lines[] = 'user = ' . ($c['user'] ?? '');
                if (! empty($c['password'])) $this->appendObscured($lines, 'pass', $c['password'], '存储密码', $warnings);
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
        // key_mode=agent_local 时中心无密钥，走不到这里（调用方已把这些存储挡在人工清单）。
        $enc = $c['encryption'] ?? [];
        if (! empty($enc['enabled']) && ($enc['key_mode'] ?? 'center') === 'center') {
            $lines[] = '';
            $lines[] = '[' . $remote . '_c]';
            $lines[] = 'type = crypt';
            $lines[] = 'remote = ' . $remote;
            $this->appendObscured($lines, 'password', (string) ($enc['password'] ?? ''), '加密密码', $warnings);
            $lines[] = 'filename_encryption = ' . (($enc['filename_encryption'] ?? 'standard') === 'off' ? 'off' : 'standard');
            $lines[] = 'directory_name_encryption = ' . (! empty($enc['directory_name_encryption']) ? 'true' : 'false');
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * 写一行需要 obscure 的凭证。obscure 失败时不写这一行（绝不退回明文），
     * 而是记 warning 让调用方跳过该存储。
     */
    private function appendObscured(array &$lines, string $key, string $plain, string $label, array &$warnings): void
    {
        $obscured = $this->rcloneObscure($plain);
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
    private function rcloneObscure(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        $cmd = 'rclone obscure ' . escapeshellarg($plain) . ' 2>/dev/null';
        $out = trim((string) shell_exec($cmd));
        return $out !== '' ? $out : null;
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
