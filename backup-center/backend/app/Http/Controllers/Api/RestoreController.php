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

        // 合并窗口：backup_at 相差 ≤ 30 分钟视为同一次备份。
        // logs 已按 backup_at 倒序（新→旧），每个文件尝试并入时间相邻的当前恢复项；
        // 与组内最早的文件比较，保证组跨度不超过窗口（避免链式合并把不同批次串起来）。
        $windowSeconds = 1800;

        $suites = [];
        foreach ($logs as $log) {
            $at = $log->backup_at;
            $latest = $log->uploads->first();

            $file = [
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

            $merged = false;
            $n = count($suites);
            if ($n > 0 && $at) {
                $prev = $suites[$n - 1];
                $prevFiles = $prev['files'];
                $earliestAt = $prevFiles[count($prevFiles) - 1]['backup_at'];
                if ($earliestAt && Carbon::parse($earliestAt)->diffInSeconds($at) <= $windowSeconds) {
                    $prev['files'][] = $file;
                    $suites[$n - 1] = $prev;
                    $merged = true;
                }
            }
            if (! $merged) {
                // 新恢复项：backup_at 取组内最新文件的时间（倒序第一个加入的即最新）
                $suites[] = [
                    'suite_id' => 'suite-' . $log->id,
                    'backup_at' => $at?->toIso8601String(),
                    'files' => [$file],
                ];
            }
        }

        // 每个 backup_log 取最新一条 restore 命令（含终态，用于"已恢复"徽标）
        $latestByLog = $this->latestRestoreCommandByLog($website);

        $dayGroups = [];
        foreach ($suites as $suite) {
            $files = $suite['files'];
            $times = array_unique(array_filter(array_column($files, 'backup_at')));

            $states = [];
            foreach ($files as $f) {
                $cmd = $latestByLog[$f['backup_log_id']] ?? null;
                if ($cmd) {
                    $states[] = $cmd->status;
                }
            }

            $suite['total_count'] = count($files);
            $suite['restorable_count'] = count(array_filter($files, fn ($f) => $f['restorable']));
            $suite['total_size'] = array_sum(array_column($files, 'size'));
            $suite['restore_state'] = $this->aggregateRestoreState($states);
            // match：组内 backup_at 是否完全一致（self 模式精确合并 / external 窗口合并）
            $suite['match'] = count($times) <= 1 ? 'exact' : 'window';

            $date = $suite['backup_at'] ? Carbon::parse($suite['backup_at'])->format('Y-m-d') : '';
            $dayGroups[$date][] = $suite;
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
