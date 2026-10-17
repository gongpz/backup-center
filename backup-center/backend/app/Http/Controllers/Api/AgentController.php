<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentCommand;
use App\Models\BackupLog;
use App\Models\BackupUpload;
use App\Models\Server;
use App\Models\Storage;
use App\Models\Website;
use App\Services\NotificationDispatcher;
use App\Support\BackupSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentController extends Controller
{
    /**
     * 首次注册 / 重新绑定。Agent 启动时调用。幂等。
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent_version' => ['required', 'string', 'max:32'],
            'hostname' => ['nullable', 'string', 'max:128'],
            'os_type' => ['nullable', 'string', 'max:32'],
            'kernel' => ['nullable', 'string', 'max:64'],
            'arch' => ['nullable', 'string', 'max:16'],
            'ip' => ['nullable', 'string', 'max:64'],
        ]);

        /** @var Server $server */
        $server = $request->attributes->get('server');

        $server->fill(array_filter($data, fn ($v) => $v !== null && $v !== ''));

        // Agent 端不主动上报 IP（sysinfo.Basic 里 IP=""）；这里用连接来源 IP 兜底。
        // 每次 register 都覆盖 —— 支持 Agent 换 IP 场景（家宽动态、VPS 换 IP）
        $sourceIp = $request->ip();
        if ($sourceIp && ! in_array($sourceIp, ['127.0.0.1', '::1'], true)) {
            $server->ip = $sourceIp;
        }

        if (! $server->registered_at) {
            $server->registered_at = now();
        }
        $server->last_seen_at = now();
        $server->status = Server::STATUS_ONLINE;
        $server->status_message = null;
        $server->save();

        return response()->json([
            'ok' => true,
            'server_id' => $server->id,
            'server_name' => $server->name,
            'config' => [
                'heartbeat_interval' => 60,
                'website_refresh_interval' => 300, // 5 分钟拉一次网站列表
                'scan_interval' => 120,             // 2 分钟扫一次备份目录
            ],
        ]);
    }

    /**
     * 心跳 + 系统指标。
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent_version' => ['nullable', 'string', 'max:32'],
            'cpu_usage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'mem_usage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'disk_usage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'uptime' => ['nullable', 'integer', 'min:0'],
            'disks' => ['nullable', 'array', 'max:32'],
            'disks.*.mount' => ['required_with:disks', 'string', 'max:255'],
            'disks.*.fs' => ['nullable', 'string', 'max:32'],
            'disks.*.total' => ['required_with:disks', 'integer', 'min:0'],
            'disks.*.used' => ['required_with:disks', 'integer', 'min:0'],
            'disks.*.available' => ['required_with:disks', 'integer', 'min:0'],
        ]);

        /** @var Server $server */
        $server = $request->attributes->get('server');

        $fill = array_filter($data, fn ($v) => $v !== null && $v !== '');
        // array_filter 会保留空数组，这里显式处理让 disks 能被更新为 []（比如所有分区都被过滤）
        if (array_key_exists('disks', $data)) {
            $fill['disks'] = $data['disks'];
        }
        $server->fill($fill);

        // 同步刷新 IP（Agent 换 IP 时也能及时反映）
        $sourceIp = $request->ip();
        if ($sourceIp && ! in_array($sourceIp, ['127.0.0.1', '::1'], true)) {
            $server->ip = $sourceIp;
        }

        $server->last_seen_at = now();

        if (in_array($server->status, [Server::STATUS_OFFLINE, Server::STATUS_ERROR, Server::STATUS_PENDING], true)) {
            $server->status = Server::STATUS_ONLINE;
            $server->status_message = null;
        }
        $server->save();

        // ---------- 自动成功判定：dispatched 中的 update 命令，若 Agent 上报的版本已达到 to_version 目标，标 success ----------
        $dispatched = $server->agentCommands()
            ->where('type', AgentCommand::TYPE_UPDATE)
            ->where('status', AgentCommand::STATUS_DISPATCHED)
            ->orderByDesc('dispatched_at')
            ->first();
        if ($dispatched && $server->agent_version) {
            // 严格：必须精确等于 to_version 才算成功；防止运维装错版本被误判成功
            if ($dispatched->to_version && $server->agent_version === $dispatched->to_version) {
                $dispatched->update([
                    'status'       => AgentCommand::STATUS_SUCCESS,
                    'output'       => "版本已从 v{$dispatched->from_version} 升级到 v{$server->agent_version}",
                    'completed_at' => now(),
                ]);
            }
        }

        // ---------- 原子挑一条 pending 命令下发（避免并发心跳双派发） ----------
        // 先查 id，再 UPDATE ... WHERE status='pending'，只有确认 affected=1 才算拿到
        $pendingCmd = null;
        $candidate = $server->agentCommands()
            ->where('status', AgentCommand::STATUS_PENDING)
            ->orderBy('id')
            ->first();
        if ($candidate) {
            $affected = AgentCommand::where('id', $candidate->id)
                ->where('status', AgentCommand::STATUS_PENDING)
                ->update([
                    'status'        => AgentCommand::STATUS_DISPATCHED,
                    'dispatched_at' => now(),
                    // from_version 若空，用当前 agent_version 补上
                    'from_version'  => $candidate->from_version ?: $server->agent_version,
                ]);
            if ($affected === 1) {
                $pendingCmd = $candidate->fresh();
            }
        }

        $response = [
            'ok'                => true,
            'server_time'       => now()->toIso8601String(),
            'next_heartbeat_in' => 60,
            'agent_mirror_url'  => $this->resolveAgentMirrorUrl(),
        ];
        if ($pendingCmd) {
            // 强制 params 为 JSON object 而非 array，防止 Go client 的 map[string]interface{} 解析失败
            $params = $pendingCmd->params;
            if (! is_array($params) || array_is_list($params)) {
                $params = (object) [];
            } else {
                $params = (object) $params;
            }
            $response['pending_command'] = [
                'id'     => $pendingCmd->id,
                'type'   => $pendingCmd->type,
                'params' => $params,
            ];
        }
        return response()->json($response);
    }

    /**
     * Agent 主动上报命令执行结果（成功场景可省略：update 后 systemctl restart 会让 version 变化，heartbeat 自动判定；
     * 失败场景必须调用此接口标 failed，否则 command 会一直挂在 dispatched）
     */
    public function commandResult(Request $request): JsonResponse
    {
        $data = $request->validate([
            'command_id' => ['required', 'integer'],
            'status'     => ['required', Rule::in([AgentCommand::STATUS_SUCCESS, AgentCommand::STATUS_FAILED])],
            'output'     => ['nullable', 'string', 'max:4000'],
            'error'      => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var Server $server */
        $server = $request->attributes->get('server');

        // 状态机校验：只有 pending/dispatched 状态的命令允许被上报结果；已终态的忽略
        $cmd = AgentCommand::where('id', $data['command_id'])
            ->where('server_id', $server->id)
            ->whereIn('status', [AgentCommand::STATUS_PENDING, AgentCommand::STATUS_DISPATCHED])
            ->first();
        if (! $cmd) {
            return response()->json(['ok' => false, 'message' => 'command not found or already finalized'], 404);
        }

        $cmd->update([
            'status'       => $data['status'],
            'output'       => $data['output'] ?? null,
            'error'        => $data['error'] ?? null,
            'completed_at' => now(),
        ]);

        // bind_root 成功：把 Agent 回传的 web_root 写进对应网站
        if ($cmd->type === AgentCommand::TYPE_BIND_ROOT && $data['status'] === AgentCommand::STATUS_SUCCESS) {
            $this->applyBindResult($cmd, $data['output'] ?? '');
        }

        return response()->json(['ok' => true]);
    }

    /**
     * 解析 bind_root 命令的 output（JSON {web_root: "..."}）并回写到网站的 web_root / root_bound_at。
     */
    private function applyBindResult(AgentCommand $cmd, string $output): void
    {
        $p = $cmd->params ?? [];
        $websiteId = $p['website_id'] ?? null;
        if (! $websiteId) {
            return;
        }
        $decoded = json_decode($output, true);
        $webRoot = is_array($decoded) ? ($decoded['web_root'] ?? null) : null;
        if (! $webRoot) {
            return;
        }
        $website = Website::where('id', $websiteId)
            ->where('server_id', $cmd->server_id)
            ->first();
        if (! $website) {
            return;
        }
        $website->web_root = $webRoot;
        $website->root_bound_at = now();
        $website->save();
    }

    /**
     * 长任务进度回报（不改状态 / 不改 completed_at）：Agent 在执行耗时命令（如 restore）期间
     * 周期性上报最新进度文本，仅更新 agent_commands.output 供前端实时展示。
     * 终态仍走 commandResult。
     */
    public function commandProgress(Request $request): JsonResponse
    {
        $data = $request->validate([
            'command_id' => ['required', 'integer'],
            'output'     => ['nullable', 'string', 'max:4000'],
        ]);

        /** @var Server $server */
        $server = $request->attributes->get('server');

        $cmd = AgentCommand::where('id', $data['command_id'])
            ->where('server_id', $server->id)
            ->whereIn('status', [AgentCommand::STATUS_PENDING, AgentCommand::STATUS_DISPATCHED])
            ->first();
        if (! $cmd) {
            return response()->json(['ok' => false, 'message' => 'command not found or already finalized'], 404);
        }

        $cmd->update(['output' => $data['output'] ?? null]);

        return response()->json(['ok' => true]);
    }

    /**
     * Agent 拉取本服务器要监控的网站列表 + 关联的存储（含解密 config）。
     * Agent 用这些数据生成 rclone.conf 并对每个新备份文件调 rclone copy。
     */
    public function websites(Request $request): JsonResponse
    {
        /** @var Server $server */
        $server = $request->attributes->get('server');

        $sites = $server->websites()
            ->with(['storages' => function ($q) {
                $q->where('status', Storage::STATUS_ENABLED);
            }])
            ->where('status', Website::STATUS_ENABLED)
            ->get();

        // 所有强制备份的存储（跨所有网站生效）
        $mandatory = Storage::mandatoryEnabled()->get();

        // 收集所有会用到的 storage（去重）
        $allStorages = collect();
        foreach ($sites as $w) {
            foreach ($w->storages as $s) $allStorages->put($s->id, $s);
        }
        foreach ($mandatory as $s) $allStorages->put($s->id, $s);

        // 拉平返回 - 顶层给一份完整 storages 清单（含解密 config），网站里只放 ID 列表
        $storagesOut = $allStorages->values()->map(function ($s) {
            $config = $s->config; // Attribute cast 自动解密
            // T2（agent_local）：加密密钥由 Agent 本地管理（/etc/backup-agent/backup.key），
            // 中心不保存也不下发密码 —— 下发前剥掉，Agent 改用本地密钥渲染 crypt section。
            // center 模式原样下发（含密码），与现状"存储密码明文下发"同一信任域。
            $enc = $config['encryption'] ?? [];
            if (! empty($enc['enabled']) && ($enc['key_mode'] ?? 'center') === 'agent_local') {
                unset($config['encryption']['password']);
            }
            return [
                'id' => $s->id,
                'name' => $s->name,
                'type' => $s->type,
                'is_mandatory' => (bool) $s->is_mandatory,
                'base_path' => $s->base_path,
                'config' => $config,
            ];
        })->values();

        $mandatoryIds = $mandatory->pluck('id')->all();

        return response()->json([
            'ok' => true,
            'storages' => $storagesOut,
            'websites' => $sites->map(function ($w) use ($mandatoryIds) {
                $userStorageIds = $w->storages->pluck('id')->all();
                // 网站的有效存储 = 用户选中 ∪ 强制备份
                $effectiveIds = array_values(array_unique(array_merge($userStorageIds, $mandatoryIds)));

                // 规范化 backup_paths：过滤空 path、pattern 默认 '*'（label 不下发给 Agent，仅 UI 使用）
                $paths = collect($w->backup_paths ?? [])
                    ->map(fn ($p) => [
                        'path'    => trim($p['path'] ?? ''),
                        'pattern' => ($p['pattern'] ?? '') !== '' ? $p['pattern'] : '*',
                    ])
                    ->filter(fn ($p) => $p['path'] !== '')
                    ->values()
                    ->all();

                // 兼容 Agent v0.3.x：同时下发 backup_path / file_pattern（取第一条）
                $first = $paths[0] ?? null;

                return [
                    'id' => $w->id,
                    'name' => $w->name,
                    'backup_paths' => $paths,
                    'backup_path' => $first['path'] ?? '',
                    'file_pattern' => $first['pattern'] ?? '*',
                    'retention_days' => $w->retention_days,
                    'sync_mode' => $w->sync_mode,
                    'storage_ids' => $effectiveIds,
                    // 自主压缩（P3）所需：Agent 据此 tar 网站根目录 + 导出数据库。
                    // db_password 经 Website::dbPassword Attribute 解密后以明文下发（同 Storage.config 通道，
                    // 仅驻留 Agent 内存，绝不通过 AgentCommand.params 持久化）。
                    'backup_source' => $w->backup_source,
                    'web_root' => $w->web_root,
                    'db_type' => $w->db_type,
                    'db_host' => $w->db_host,
                    'db_port' => $w->db_port,
                    'db_name' => $w->db_name,
                    'db_user' => $w->db_user,
                    'db_password' => $w->db_password,
                ];
            }),
        ]);
    }

    /**
     * Agent 上报单次上传结果（每次 rclone copy 完成后调）。
     * 幂等：同一 (backup_log_id, storage_id) 只有一条记录，重复上报会更新。
     */
    public function uploadReport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'backup_log_id' => ['required', 'integer'],
            'storage_id' => ['required', 'integer'],
            'status' => ['required', Rule::in([
                BackupUpload::STATUS_PENDING,
                BackupUpload::STATUS_UPLOADING,
                BackupUpload::STATUS_UPLOADED,
                BackupUpload::STATUS_FAILED,
            ])],
            'remote_path' => ['nullable', 'string', 'max:1024'],
            'uploaded_bytes' => ['nullable', 'integer', 'min:0'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'error_message' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var Server $server */
        $server = $request->attributes->get('server');

        // 校验：backup_log 必须属于当前 Agent 的服务器
        $log = BackupLog::where('id', $data['backup_log_id'])
            ->where('server_id', $server->id)
            ->first();
        if (! $log) {
            return response()->json([
                'ok' => false,
                'code' => 'BACKUP_LOG_NOT_FOUND',
                'message' => '备份记录不存在或不属于本服务器',
            ], 404);
        }

        $upload = BackupUpload::firstOrNew([
            'backup_log_id' => $log->id,
            'storage_id' => $data['storage_id'],
        ]);
        $created = ! $upload->exists;

        // 时间戳跟踪
        if ($data['status'] === BackupUpload::STATUS_UPLOADING && ! $upload->started_at) {
            $upload->started_at = now();
        }
        if (in_array($data['status'], [BackupUpload::STATUS_UPLOADED, BackupUpload::STATUS_FAILED], true)) {
            $upload->finished_at = now();
            if ($data['status'] === BackupUpload::STATUS_FAILED) {
                $upload->attempts = ($upload->attempts ?? 0) + 1;
            }
        }

        $upload->status = $data['status'];
        foreach (['remote_path', 'uploaded_bytes', 'duration_seconds', 'error_message'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] !== null) {
                $upload->$k = $data[$k];
            }
        }
        $upload->save();

        // 更新 backup_log 的总体 upload_status：全成功=uploaded，任一失败=upload_failed，否则 uploading
        $this->refreshLogUploadStatus($log);

        // 失败时触发通知（in-app，后续补入 notifications 表；当前先记 log）
        if ($data['status'] === BackupUpload::STATUS_FAILED) {
            // TODO(⑤c-8): create in-app notification record
            \Log::info('BackupUpload failed', [
                'log_id' => $log->id,
                'storage_id' => $data['storage_id'],
                'error' => $data['error_message'] ?? '',
            ]);
        }

        return response()->json([
            'ok' => true,
            'upload_id' => $upload->id,
            'created' => $created,
        ]);
    }

    /** 根据所有 backup_uploads 状态刷新 backup_log 的 upload_status */
    private function refreshLogUploadStatus(BackupLog $log): void
    {
        $uploads = $log->uploads()->get();
        if ($uploads->isEmpty()) return;

        $allUploaded = $uploads->every(fn ($u) => $u->status === BackupUpload::STATUS_UPLOADED);
        $anyFailed   = $uploads->contains(fn ($u) => $u->status === BackupUpload::STATUS_FAILED);
        $anyUploading = $uploads->contains(fn ($u) => $u->status === BackupUpload::STATUS_UPLOADING);

        $status = 'pending';
        if ($allUploaded) $status = BackupLog::UPLOAD_UPLOADED;
        elseif ($anyFailed) $status = BackupLog::UPLOAD_FAILED;
        elseif ($anyUploading) $status = BackupLog::UPLOAD_UPLOADING;

        if ($log->upload_status !== $status) {
            $log->upload_status = $status;
            if ($status === BackupLog::UPLOAD_UPLOADED) {
                $log->uploaded_at = now();
            }
            $log->save();
        }
    }

    /**
     * Agent 发现新备份文件时上报。
     * 幂等：同一 (website_id, full_path) 只创建一条记录，重复上报会被忽略（或更新 size/backup_at）。
     * 注意：不能按 (website_id, filename) 去重 —— 一个网站配了多个备份目录时，
     * 不同目录下的同名备份文件会被误判为重复而丢失（恢复界面就只能看到一个）。
     */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'website_id'  => ['required', 'integer'],
            'filename'    => ['required', 'string', 'max:255'],
            'full_path'   => ['nullable', 'string', 'max:1024'],
            'size'        => ['nullable', 'integer', 'min:0'],
            'backup_at'   => ['required', 'date'],
            'status'      => ['nullable', 'string'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        /** @var Server $server */
        $server = $request->attributes->get('server');

        // 校验：该 website 必须属于当前 Agent 的服务器
        $website = Website::where('id', $data['website_id'])
            ->where('server_id', $server->id)
            ->first();

        if (! $website) {
            return response()->json([
                'ok' => false,
                'code' => 'WEBSITE_NOT_FOUND_OR_UNAUTHORIZED',
                'message' => '网站不存在或不属于本服务器',
            ], 404);
        }

        $status = $data['status'] ?? BackupLog::STATUS_SUCCESS;

        // 幂等键用「网站 + 完整路径」而不是文件名：
        // 一个网站可以配置多个备份目录，不同目录下完全可能存在同名文件
        //（比如两个目录里都叫 backup.zip）。若按 (website_id, filename) 去重，
        // 第二个目录的文件会被误判为重复而丢弃，表现为
        // 「两个目录都备份成功了，但恢复界面只显示一个可恢复」。
        $fullPath = (($data['full_path'] ?? '') !== '')
            ? $data['full_path']
            : $data['filename'];

        // 幂等：先查是否已存在
        $log = BackupLog::firstOrNew([
            'website_id' => $website->id,
            'full_path'  => $fullPath,
        ]);

        $created = ! $log->exists;

        $log->fill([
            'server_id'        => $server->id,
            // filename 必须显式写入：幂等键改成 (website_id, full_path) 后，
            // firstOrNew 不再带 filename，而该列 NOT NULL 且无默认值，
            // 新建记录若缺这个字段，MySQL 严格模式会直接插入失败
            //（Field 'filename' doesn't have a default value → Agent 上报 500 → 同步 0 文件）。
            'filename'         => $data['filename'],
            'full_path'        => $fullPath,
            // 来源键：与迁移回填共用同一个纯函数，避免双份逻辑漂移。
            // 只放 fill()，绝不能进 firstOrNew() 的幂等键（幂等键是 website_id+full_path）。
            'source_key'       => BackupSource::derive(
                $data['filename'],
                $fullPath,
                $website->backup_paths ?: []
            ),
            'size'             => $data['size']       ?? $log->size,
            'status'           => $status,
            'backup_at'        => $data['backup_at'],
            'duration_seconds' => $data['duration_seconds'] ?? $log->duration_seconds,
        ]);
        $log->save();

        // 同步 Website 的最新备份信息（如果这个备份更新）
        if (! $website->last_backup_at || $log->backup_at->gt($website->last_backup_at)) {
            $website->update([
                'last_backup_at'       => $log->backup_at,
                'last_backup_status'   => $status,
                'last_backup_size'     => $log->size,
                'last_backup_filename' => $log->filename,
            ]);
        }

        // 仅在新创建（首次上报）时触发通知，避免重复推送
        if ($created) {
            app(NotificationDispatcher::class)->notifyBackupCompleted($log->fresh());
        }

        return response()->json([
            'ok' => true,
            'log_id' => $log->id,
            'created' => $created,
        ]);
    }

    /**
     * 解析 Agent 二进制的 CDN 镜像 URL。
     * 优先：标记为 is_agent_mirror=true 的 resource_pool 的 public_url_prefix。
     * 回退：老的 system_settings.agent_mirror_url（R66 兼容）。
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
        } catch (\Throwable $e) {
            // 表未建 / 迁移未跑：静默回退
        }
        return (string) \App\Models\SystemSetting::get('agent_mirror_url', '');
    }
}
