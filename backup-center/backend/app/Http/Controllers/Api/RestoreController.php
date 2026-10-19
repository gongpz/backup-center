<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentCommand;
use App\Models\BackupLog;
use App\Models\BackupUpload;
use App\Models\Website;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 数据恢复（P2）。
 *
 * 独立栏目：网站筛选 → 时间范围 → 按「网站 + 日期」分组的配套备份套件 → 一键整套恢复。
 * 轻便模式：rclone 把云端归档 copy 回服务器原备份目录（dirname(full_path)），
 * 不自动解包、不自动导入数据库 —— 由管理员手动处理。
 *
 * AgentCommand 的 params 结构与 BackupLogController::restore 完全一致，Agent 侧无需改动。
 */
class RestoreController extends Controller
{
    /**
     * 网站筛选列表（恢复栏目左侧）。
     */
    public function websites(): JsonResponse
    {
        $websites = Website::query()
            ->with('server:id,name')
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'domain' => $w->domain,
                'server_name' => $w->server?->name,
                'restore_mode' => $w->restore_mode ?? 'lite',
                'web_root' => $w->web_root,
                'last_backup_at' => $w->last_backup_at?->toIso8601String(),
                'last_backup_status' => $w->last_backup_status,
            ]);

        return response()->json(['data' => $websites]);
    }

    /**
     * 按「恢复项」分组的备份列表。
     *
     * 恢复项 = 同一次备份产生的文件集合（网站目录归档 + 数据库转储等），
     * 按 backup_at 合并：相差 ≤ 合并窗口的多个文件算同一次备份。
     *   - self 自主备份：web/db 两个产物共用同一 backupAt（秒级相同）→ 必然同组；
     *   - external 扫描：文件 mtime 取决于外部脚本生成顺序 → 靠窗口兜底合并。
     * 同一天允许有多个恢复项（一天备份多次），前端按天二级分组展示；
     * 点击恢复项看详情、勾选要恢复的文件——不再"默认一套全恢复"。
     *
     * 同时返回 active_commands（本网站进行中的恢复命令），让前端刷新后
     * 仍能看到恢复进度，并对"恢复中"的文件禁用重复下发。
     */
    public function backups(Request $request): JsonResponse
    {
        $data = $request->validate([
            'website_id' => ['required', 'integer', 'exists:websites,id'],
            'range' => ['nullable', 'string', 'in:month,week,3day,1day'],
        ]);

        $website = Website::findOrFail($data['website_id']);

        $days = match ($data['range'] ?? 'month') {
            'week'  => 7,
            '3day'  => 3,
            '1day'  => 1,
            default => 30, // month
        };
        $since = now()->subDays($days);

        $logs = BackupLog::where('website_id', $website->id)
            ->where('backup_at', '>=', $since)
            ->with([
                'uploads' => fn ($q) => $q->where('status', BackupUpload::STATUS_UPLOADED)
                    ->whereNotNull('remote_path')
                    ->where('remote_path', '!=', '')
                    ->orderByDesc('id'),
                'uploads.storage:id,name,type',
            ])
            ->orderByDesc('backup_at')
            ->orderByDesc('id')
            ->get();

        // 合并窗口：5 分钟。
        // 取值依据（用户生产库 12 条真实 backup_logs 实测）：
        //   同一场次内 web/db 间隔 ≤45s（宝塔两个计划任务先后执行；自主备份两文件
        //   backup_at 甚至完全相同——Agent 一次运行只取一个时间戳）；
        //   不同场次间隔 ≥600s（用户 00:55 与 01:05 两次手动自主备份）。
        // 300s 距实测同场次上限 6.7 倍余量、距不同场次下限 2 倍余量。
        // 旧值 1800s（30 分钟）会把间隔 10 分钟的两次备份糊成一条——用户实测踩过。
        $windowSeconds = 300;

        // 先收集全部文件，再走「类型配对 + 窗口兜底」合并（见 buildSuites）。
        // 旧实现是纯时间窗口一把糊：短了糊不住同场次、长了糊进别的场次，
        // 且曾因 Carbon 3 diffInSeconds 有符号值导致窗口恒真（4265f44 修的是那个 bug，
        // 这里换掉策略本身）。
        $files = [];
        foreach ($logs as $log) {
            $at = $log->backup_at;
            $latest = $log->uploads->first();

            $files[] = [
                'backup_log_id' => $log->id,
                'label' => $this->fileLabel($log, $website),
                'type' => $this->fileType((string) $log->filename),
                'filename' => $log->filename,
                'full_path' => $log->full_path,
                'size' => $log->size,
                'backup_at' => $at?->toIso8601String(),
                'restorable' => (bool) $latest,
                'latest_upload' => $latest ? [
                    'id' => $latest->id,
                    'storage_name' => $latest->storage?->name,
                    'storage_type' => $latest->storage?->type,
                    'remote_path' => $latest->remote_path,
                    'uploaded_at' => $latest->finished_at?->toIso8601String() ?? $latest->updated_at?->toIso8601String(),
                ] : null,
            ];
        }

        $suites = $this->buildSuites($files, $windowSeconds);

        // 每个 backup_log 取最新一条 restore 命令（含终态，用于"已恢复"徽标）
        $latestByLog = $this->latestRestoreCommandByLog($website);

        // 日期分组：按**文件级 backup_at** 归属。
        // 旧逻辑按 suite.backup_at（组内最新时间）分日期，套件中混入的更早日期文件会随组
        // 显示在最新日期下——用户实测「10月19日」组里出现 18/17 日的备份（external 模式
        // 下跨多天的文件被 30 分钟窗口串成一个套件）。
        // 现在：suite 内每个文件按自身 backup_at 的日期进组；同一 suite 跨天时在多个日期组
        // 都呈现，各组只带当天文件，统计与 restore_state 按天重算。
        $dayGroups = [];
        foreach ($suites as $suite) {
            $byDate = [];
            foreach ($suite['files'] as $f) {
                $d = $f['backup_at'] ? Carbon::parse($f['backup_at'])->format('Y-m-d') : '';
                $byDate[$d][] = $f;
            }
            foreach ($byDate as $date => $dayFiles) {
                // dayFiles 保持倒序（新→旧），第一个即当天最新
                $states = [];
                foreach ($dayFiles as $f) {
                    $cmd = $latestByLog[$f['backup_log_id']] ?? null;
                    if ($cmd) {
                        $states[] = $cmd->status;
                    }
                }
                $times = array_unique(array_filter(array_column($dayFiles, 'backup_at')));

                $dayGroups[$date][] = [
                    'suite_id' => $suite['suite_id'],
                    'backup_at' => $dayFiles[0]['backup_at'],
                    // match：当天文件 backup_at 是否完全一致（self 精确合并 / external 窗口合并）
                    'match' => count($times) <= 1 ? 'exact' : 'window',
                    'total_count' => count($dayFiles),
                    'restorable_count' => count(array_filter($dayFiles, fn ($f) => $f['restorable'])),
                    'total_size' => array_sum(array_column($dayFiles, 'size')),
                    'restore_state' => $this->aggregateRestoreState($states),
                    'files' => $dayFiles,
                ];
            }
        }

        krsort($dayGroups);

        $out = [];
        foreach ($dayGroups as $date => $items) {
            $out[] = ['date' => $date, 'suites' => $items];
        }

        // 进行中的恢复命令（pending/dispatched）：前端用于刷新后恢复进度展示与重复下发防护
        $logIds = $logs->pluck('id')->all();
        $active = [];
        foreach ($latestByLog as $logId => $cmd) {
            if (! in_array((int) $logId, $logIds, true)) {
                continue;
            }
            if (! in_array($cmd->status, [AgentCommand::STATUS_PENDING, AgentCommand::STATUS_DISPATCHED], true)) {
                continue;
            }
            $active[] = [
                'backup_log_id' => (int) $logId,
                'command_id' => $cmd->id,
                'status' => $cmd->status,
                'filename' => basename((string) ($cmd->params['remote_path'] ?? '')),
                'local_dir' => $cmd->params['local_dir'] ?? '',
            ];
        }

        return response()->json(['data' => $out, 'active_commands' => $active]);
    }

    /**
     * 把文件列表合并成「备份套件」：类型配对 + 窗口兜底。
     *
     * 一次备份的语义 = 1 个网站目录包 + 1 个数据库包。合并策略：
     *   ① 配对：每个 website 文件找窗口内时间最近的未配对 database 文件成组。
     *      自主备份两文件 backup_at 相同（Agent 一次运行只取一个时间戳，0s）；
     *      宝塔 external 的 web/db 两个计划任务先后执行，间隔 8–45s。
     *      按类型一对一配对，两场备份间隔再近也不会串味——旧纯时间窗口
     *      会把 4 分钟外的两场糊成一组（用户明确担心的风险）。
     *   ② 兜底：没配上对的文件（仅数据库的网站、单个网站包、其他类型文件）
     *      按窗口归并；与组内最早文件比较，防链式合并。
     *   ③ 组内文件按 backup_at 倒序；suite_id 取组内最新文件（与旧约定一致，
     *      前端 Vue key 依赖其稳定性）；套件之间也按最新文件倒序。
     *
     * @param array $files         file 数组（含 type/backup_at/backup_log_id），顺序不限
     * @param int   $windowSeconds 合并窗口（秒）
     */
    private function buildSuites(array $files, int $windowSeconds): array
    {
        $ts = fn (?string $iso): int => $iso ? Carbon::parse($iso)->getTimestamp() : 0;

        $webs = array_values(array_filter($files, fn ($f) => $f['type'] === 'website'));
        $dbs = array_values(array_filter($files, fn ($f) => $f['type'] === 'database'));

        // ① website × database 就近配对（窗口内；并列取较新者，$dbs 保持倒序）
        $groups = [];
        $usedDb = [];
        foreach ($webs as $w) {
            $best = null;
            $bestDiff = null;
            foreach ($dbs as $i => $d) {
                if (isset($usedDb[$i])) {
                    continue;
                }
                $diff = abs($ts($w['backup_at']) - $ts($d['backup_at']));
                if ($diff <= $windowSeconds && ($bestDiff === null || $diff < $bestDiff)) {
                    $best = $i;
                    $bestDiff = $diff;
                }
            }
            if ($best !== null) {
                $usedDb[$best] = true;
                $groups[] = [$w, $dbs[$best]];
            } else {
                $groups[] = [$w];
            }
        }

        // ② 兜底：未配对 db + 非 website/database 文件，按窗口归并（倒序 + 防链式）
        $leftovers = [];
        foreach ($dbs as $i => $d) {
            if (! isset($usedDb[$i])) {
                $leftovers[] = $d;
            }
        }
        foreach ($files as $f) {
            if (! in_array($f['type'], ['website', 'database'], true)) {
                $leftovers[] = $f;
            }
        }
        usort($leftovers, fn ($a, $b) => $ts($b['backup_at']) <=> $ts($a['backup_at']));

        $leftoverGroups = [];
        foreach ($leftovers as $f) {
            $merged = false;
            $n = count($leftoverGroups);
            if ($n > 0) {
                $prev = $leftoverGroups[$n - 1];
                $earliest = $prev[count($prev) - 1]['backup_at'];
                if ($earliest && abs($ts($earliest) - $ts($f['backup_at'])) <= $windowSeconds) {
                    $leftoverGroups[$n - 1][] = $f;
                    $merged = true;
                }
            }
            if (! $merged) {
                $leftoverGroups[] = [$f];
            }
        }
        $groups = array_merge($groups, $leftoverGroups);

        // ③ 组内倒序 + 套件倒序（新→旧）
        $suites = [];
        foreach ($groups as $group) {
            usort($group, fn ($a, $b) => $ts($b['backup_at']) <=> $ts($a['backup_at']));
            $suites[] = [
                'suite_id' => 'suite-' . $group[0]['backup_log_id'],
                'backup_at' => $group[0]['backup_at'],
                'files' => $group,
            ];
        }
        usort($suites, fn ($a, $b) => $ts($b['backup_at']) <=> $ts($a['backup_at']));

        return $suites;
    }

    /**
     * 整套批量下发恢复（一次可下发多个 backup_log）。
     *
     * 去重粒度是「同一 upload 已有进行中的 restore 命令则跳过该文件」——
     * Agent 每次心跳只处理一条 pending 命令，多条 pending 会自然排队，无需服务器级互斥。
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'backup_log_ids' => ['required', 'array', 'min:1', 'max:100'],
            'backup_log_ids.*' => ['integer'],
        ]);

        $logs = BackupLog::with(['uploads', 'website'])->whereIn('id', $data['backup_log_ids'])->get();

        $results = [];
        $skipped = [];

        foreach ($logs as $log) {
            // 最新一次成功上传（已带 remote_path）
            $upload = $log->uploads
                ->where('status', BackupUpload::STATUS_UPLOADED)
                ->whereNotNull('remote_path')
                ->where('remote_path', '!=', '')
                ->sortByDesc('id')
                ->first();

            if (! $upload) {
                $skipped[] = ['backup_log_id' => $log->id, 'reason' => '没有成功上传的云端副本，无法恢复'];
                continue;
            }

            $server = $log->server;
            if (! $server) {
                $skipped[] = ['backup_log_id' => $log->id, 'reason' => '找不到所属服务器，无法下发指令'];
                continue;
            }

            // restore_mode：advanced 用临时中转目录，lite 保持原 dirname(full_path) 行为
            $website = $log->website;
            $mode = $website ? ($website->restore_mode ?? 'lite') : 'lite';

            if ($mode === 'advanced') {
                // 不直接覆盖线上 web_root；Agent 把归档 rclone 到此中转目录，再解压到 web_root 的父级
                $localDir = sys_get_temp_dir() . '/bc-restore-' . ($website->id ?? $log->id) . '-' . time();
            } else {
                $localDir = $log->full_path ? dirname($log->full_path) : '';
                if ($localDir === '' || $localDir === '.') {
                    $skipped[] = ['backup_log_id' => $log->id, 'reason' => '备份记录缺少本地路径，无法定位恢复目录'];
                    continue;
                }
            }

            // 同一 upload 已有进行中的 restore 命令 → 跳过该文件（替代原服务器级 409）。
            // 只扫 pending/dispatched 两种状态（量小），不再 orderByDesc('id')->limit(100)
            // 内存过滤——命令堆积时 limit 边界会漏检，造成同一文件被重复下发。
            $conflict = AgentCommand::where('server_id', $server->id)
                ->where('type', AgentCommand::TYPE_RESTORE)
                ->whereIn('status', [AgentCommand::STATUS_PENDING, AgentCommand::STATUS_DISPATCHED])
                ->get()
                ->contains(fn ($c) => ($c->params['upload_id'] ?? null) == $upload->id
                    && ($c->params['restore_mode'] ?? 'lite') === $mode);

            if ($conflict) {
                $skipped[] = ['backup_log_id' => $log->id, 'reason' => '该文件已有恢复任务在进行中'];
                continue;
            }

            $params = [
                'backup_log_id' => $log->id,
                'upload_id' => $upload->id,
                'remote_path' => $upload->remote_path,
                'local_dir' => $localDir,
                'filename' => basename($upload->remote_path),
            ];
            // advanced 模式：携带 restore_mode + website_id（不含任何 db_* 凭据），由 Agent 自行解析 web_root/db
            if ($mode === 'advanced' && $website) {
                $params['restore_mode'] = 'advanced';
                $params['website_id'] = $website->id;
            }

            $cmd = AgentCommand::create([
                'server_id' => $server->id,
                'type' => AgentCommand::TYPE_RESTORE,
                'status' => AgentCommand::STATUS_PENDING,
                'params' => $params,
            ]);

            $results[(string) $log->id] = [
                'command_id' => $cmd->id,
                'status' => $cmd->status,
                'filename' => basename($upload->remote_path),
                'local_dir' => $localDir,
            ];
        }

        if (empty($results)) {
            return response()->json([
                'message' => '没有可下发的恢复任务',
                'skipped' => $skipped,
            ], 422);
        }

        return response()->json([
            'message' => sprintf('已下发 %d 个恢复指令，Agent 将在下次心跳（≤60s）起陆续执行', count($results)),
            'data' => $results,
            'skipped' => $skipped,
        ]);
    }

    /**
     * 多文件恢复状态轮询：按 backup_log_id 维度返回最新一条 restore 命令。
     */
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'backup_log_ids' => ['required', 'array', 'min:1', 'max:100'],
            'backup_log_ids.*' => ['integer'],
        ]);

        $ids = array_map('intval', $data['backup_log_ids']);

        $serverIds = BackupLog::whereIn('id', $ids)
            ->distinct()
            ->pluck('server_id')
            ->filter()
            ->all();

        $out = [];
        if (empty($serverIds)) {
            return response()->json(['data' => $out]);
        }

        $commands = AgentCommand::whereIn('server_id', $serverIds)
            ->where('type', AgentCommand::TYPE_RESTORE)
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        foreach ($commands as $cmd) {
            $p = $cmd->params ?? [];
            $logId = $p['backup_log_id'] ?? null;
            if ($logId === null || ! in_array((int) $logId, $ids, true)) {
                continue;
            }
            // 每个 backup_log_id 只保留最新一条命令
            if (isset($out[(string) $logId])) {
                continue;
            }
            $out[(string) $logId] = [
                'status' => $cmd->status,
                'output' => $cmd->output,
                'error' => $cmd->error,
                'command_id' => $cmd->id,
                'upload_id' => $p['upload_id'] ?? null,
                'completed_at' => $cmd->completed_at?->toIso8601String(),
            ];
        }

        return response()->json(['data' => $out]);
    }

    /**
     * 每个 backup_log 取最新一条 restore 命令（含终态，用于"已恢复"徽标与进度恢复）。
     *
     * @return array<string, AgentCommand> key = backup_log_id（字符串）
     */
    private function latestRestoreCommandByLog(Website $website): array
    {
        $commands = AgentCommand::where('server_id', $website->server_id)
            ->where('type', AgentCommand::TYPE_RESTORE)
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $byLog = [];
        foreach ($commands as $cmd) {
            $logId = $cmd->params['backup_log_id'] ?? null;
            if ($logId === null) {
                continue;
            }
            $logId = (string) $logId;
            if (! isset($byLog[$logId])) {
                $byLog[$logId] = $cmd;
            }
        }

        return $byLog;
    }

    /**
     * 恢复项的状态聚合（最坏优先）。无命令返回 null。
     */
    private function aggregateRestoreState(array $states): ?string
    {
        if (empty($states)) {
            return null;
        }
        if (in_array(AgentCommand::STATUS_FAILED, $states, true)) {
            return AgentCommand::STATUS_FAILED;
        }
        if (in_array(AgentCommand::STATUS_DISPATCHED, $states, true)) {
            return AgentCommand::STATUS_DISPATCHED;
        }
        if (in_array(AgentCommand::STATUS_PENDING, $states, true)) {
            return AgentCommand::STATUS_PENDING;
        }
        return AgentCommand::STATUS_SUCCESS;
    }

    /**
     * 文件类型：数据库转储按 database，其余（网站目录归档）按 website。
     */
    private function fileType(string $filename): string
    {
        return preg_match('/\.sql(\.|$)/i', $filename) ? 'database' : 'website';
    }

    /**
     * 文件标签：优先命中 backup_paths 里的自定义 label，否则按类型兜底。
     */
    private function fileLabel(BackupLog $log, Website $website): string
    {
        $type = $this->fileType((string) $log->filename);
        $fullPath = (string) ($log->full_path ?? '');

        foreach ($website->backup_paths ?? [] as $bp) {
            $path = is_array($bp) ? ($bp['path'] ?? '') : '';
            if ($path === '' || $fullPath === '') {
                continue;
            }
            if (str_starts_with($fullPath, $path)) {
                $label = is_array($bp) ? ($bp['label'] ?? '') : '';
                return $label !== '' ? $label : ($type === 'database' ? '数据库' : '网站目录');
            }
        }

        return $type === 'database' ? '数据库' : '网站目录';
    }
}
