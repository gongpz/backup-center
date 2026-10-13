<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentCommand;
use App\Models\BackupLog;
use App\Models\BackupUpload;
use App\Models\Website;
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
                'last_backup_at' => $w->last_backup_at?->toIso8601String(),
                'last_backup_status' => $w->last_backup_status,
            ]);

        return response()->json(['data' => $websites]);
    }

    /**
     * 按网站 + 日期分组的备份套件。
     *
     * 同一网站同一天的多条 backup_log（网站目录文件 + 数据库文件等）即为一套，
     * 前端可按组一键整套恢复。
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

        // 按日期（Y-m-d）聚组：组间日期倒序，组内保持备份时间倒序
        $groups = [];
        foreach ($logs as $log) {
            $date = $log->backup_at?->format('Y-m-d') ?? '';
            $latest = $log->uploads->first();

            $groups[$date][] = [
                'backup_log_id' => $log->id,
                'label' => $this->fileLabel($log, $website),
                'type' => $this->fileType((string) $log->filename),
                'filename' => $log->filename,
                'full_path' => $log->full_path,
                'size' => $log->size,
                'backup_at' => $log->backup_at?->toIso8601String(),
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

        krsort($groups);

        $out = [];
        foreach ($groups as $date => $files) {
            $out[] = [
                'date' => $date,
                'total_count' => count($files),
                'restorable_count' => count(array_filter($files, fn ($f) => $f['restorable'])),
                'files' => $files,
            ];
        }

        return response()->json(['data' => $out]);
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

        $logs = BackupLog::with('uploads')->whereIn('id', $data['backup_log_ids'])->get();

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

            $localDir = $log->full_path ? dirname($log->full_path) : '';
            if ($localDir === '' || $localDir === '.') {
                $skipped[] = ['backup_log_id' => $log->id, 'reason' => '备份记录缺少本地路径，无法定位恢复目录'];
                continue;
            }

            // 同一 upload 已有进行中的 restore 命令 → 跳过该文件（替代原服务器级 409）
            $conflict = AgentCommand::where('server_id', $server->id)
                ->where('type', AgentCommand::TYPE_RESTORE)
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->contains(fn ($c) => ($c->params['upload_id'] ?? null) == $upload->id
                    && in_array($c->status, [AgentCommand::STATUS_PENDING, AgentCommand::STATUS_DISPATCHED], true));

            if ($conflict) {
                $skipped[] = ['backup_log_id' => $log->id, 'reason' => '该文件已有恢复任务在进行中'];
                continue;
            }

            $cmd = AgentCommand::create([
                'server_id' => $server->id,
                'type' => AgentCommand::TYPE_RESTORE,
                'status' => AgentCommand::STATUS_PENDING,
                'params' => [
                    'backup_log_id' => $log->id,
                    'upload_id' => $upload->id,
                    'remote_path' => $upload->remote_path,
                    'local_dir' => $localDir,
                    'filename' => basename($upload->remote_path),
                ],
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
