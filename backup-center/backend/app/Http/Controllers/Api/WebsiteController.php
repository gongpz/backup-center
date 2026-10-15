<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebsiteResource;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class WebsiteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Website::with(['server', 'storages'])->orderByDesc('id');

        if ($keyword = $request->string('keyword')->trim()->toString()) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('domain', 'like', "%{$keyword}%")
                  ->orWhere('backup_paths', 'like', "%{$keyword}%")
                  ->orWhere('remark', 'like', "%{$keyword}%");
            });
        }

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($serverId = $request->integer('server_id')) {
            $query->where('server_id', $serverId);
        }

        return WebsiteResource::collection($query->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        // 空密码表示「不修改」，避免把 db_password 清空
        if (array_key_exists('db_password', $data) && ($data['db_password'] === '' || $data['db_password'] === null)) {
            unset($data['db_password']);
        }
        $storageIds = $this->extractStorageIds($data);

        $website = Website::create($data);
        // 建站即生成根目录绑定令牌（用户把 .bc-bind-<token> 放进网站根目录后，Agent 据此定位根目录）
        if (! $website->bind_token) {
            $website->bind_token = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(32));
            $website->save();
        }
        if ($storageIds !== null) {
            $website->storages()->sync($storageIds);
        }
        $website = $website->fresh()->load(['server', 'storages']);

        return (new WebsiteResource($website))
            ->additional(['message' => '添加成功'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Website $website): WebsiteResource
    {
        return new WebsiteResource($website->load(['server', 'storages']));
    }

    public function update(Website $website, Request $request): WebsiteResource
    {
        $data = $this->validated($request);
        // 空密码表示「不修改」，避免把 db_password 清空
        if (array_key_exists('db_password', $data) && ($data['db_password'] === '' || $data['db_password'] === null)) {
            unset($data['db_password']);
        }
        $storageIds = $this->extractStorageIds($data);

        $website->update($data);
        if ($storageIds !== null) {
            $website->storages()->sync($storageIds);
        }

        return new WebsiteResource($website->fresh()->load(['server', 'storages']));
    }

    public function destroy(Website $website): JsonResponse
    {
        $website->delete();
        return response()->json(['message' => '已删除']);
    }

    /**
     * 一键同步：立刻让该网站所属服务器的 Agent 扫一次备份目录，把未上传的文件推到各存储。
     * 走 agent_commands 通道（复用 update/sync_now），下一个心跳（≤60s）Agent 会收到。
     */
    public function syncNow(Website $website): JsonResponse
    {
        $server = $website->server;
        if (! $server || $server->connect_type !== \App\Models\Server::CONNECT_AGENT) {
            return response()->json(['message' => '该网站所属服务器不是 Agent 模式'], 422);
        }

        // 复用一次即可：如已有 pending/dispatched 的 sync_now，直接返回它
        $existing = $server->agentCommands()
            ->where('type', \App\Models\AgentCommand::TYPE_SYNC_NOW)
            ->whereIn('status', [\App\Models\AgentCommand::STATUS_PENDING, \App\Models\AgentCommand::STATUS_DISPATCHED])
            ->latest('id')
            ->first();
        if ($existing) {
            return response()->json([
                'message'    => '已有同步任务在队列中，最长 60 秒内 Agent 会执行',
                'command_id' => $existing->id,
            ]);
        }

        $cmd = \App\Models\AgentCommand::create([
            'server_id'   => $server->id,
            'type'        => \App\Models\AgentCommand::TYPE_SYNC_NOW,
            'params'      => ['website_id' => $website->id, 'website_name' => $website->name],
            'status'      => \App\Models\AgentCommand::STATUS_PENDING,
        ]);

        return response()->json([
            'message'    => '已下发同步指令，最长 60 秒后 Agent 会扫描并上传未同步的备份',
            'command_id' => $cmd->id,
        ]);
    }

    /**
     * 自主压缩备份：立刻让该网站所属服务器的 Agent 打包网站目录 + 导出数据库并上传（P3）。
     * 走 agent_commands 通道（type=self_backup），下一个心跳（≤60s）Agent 会收到。
     */
    public function selfBackupNow(Website $website): JsonResponse
    {
        $server = $website->server;
        if (! $server || $server->connect_type !== \App\Models\Server::CONNECT_AGENT) {
            return response()->json(['message' => '该网站所属服务器不是 Agent 模式，无法自主备份'], 422);
        }

        if ($website->backup_source !== Website::BACKUP_SOURCE_SELF) {
            return response()->json(['message' => '该网站未启用自主压缩备份（backup_source=self）'], 422);
        }

        // 复用一次即可：如已有 pending/dispatched 的 self_backup（同一网站），直接返回它
        $existing = \App\Models\AgentCommand::where('server_id', $server->id)
            ->where('type', \App\Models\AgentCommand::TYPE_SELF_BACKUP)
            ->whereIn('status', [\App\Models\AgentCommand::STATUS_PENDING, \App\Models\AgentCommand::STATUS_DISPATCHED])
            ->whereJsonContains('params', ['website_id' => $website->id])
            ->latest('id')
            ->first();
        if ($existing) {
            return response()->json([
                'message'    => '已有自主备份任务在队列中，最长 60 秒内 Agent 会执行',
                'command_id' => $existing->id,
            ]);
        }

        $cmd = \App\Models\AgentCommand::create([
            'server_id'   => $server->id,
            'type'        => \App\Models\AgentCommand::TYPE_SELF_BACKUP,
            'params'      => ['website_id' => $website->id, 'website_name' => $website->name],
            'status'      => \App\Models\AgentCommand::STATUS_PENDING,
        ]);

        return response()->json([
            'message'    => '已下发自主备份指令，最长 60 秒后 Agent 会执行（打包网站目录 + 导出数据库并上传）',
            'command_id' => $cmd->id,
        ]);
    }

    /**
     * 根目录绑定：下发 bind_root 指令，让 Agent 校验/搜索密钥文件(.bc-bind-<token>)并回传网站根目录。
     * 支持两种方式：direct_path（用户直接填根目录，Agent 校验密钥文件在不在）或 search_bases（Agent 有界搜索）。
     */
    public function bindRoot(Website $website, Request $request): JsonResponse
    {
        $data = $request->validate([
            'direct_path'    => ['nullable', 'string', 'max:255'],
            'search_bases'   => ['nullable', 'array', 'max:10'],
            'search_bases.*' => ['string', 'max:255'],
        ]);

        $server = $website->server;
        if (! $server || $server->connect_type !== Server::CONNECT_AGENT) {
            return response()->json(['message' => '该网站所属服务器不是 Agent 模式，无法绑定'], 422);
        }

        if (! $website->bind_token) {
            $website->bind_token = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(32));
            $website->save();
        }

        // 防重复：该网站已有进行中的 bind_root 则复用
        $active = $server->agentCommands()
            ->where('type', \App\Models\AgentCommand::TYPE_BIND_ROOT)
            ->whereIn('status', [\App\Models\AgentCommand::STATUS_PENDING, \App\Models\AgentCommand::STATUS_DISPATCHED])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->first(function ($c) use ($website) {
                $p = $c->params ?? [];
                return ($p['website_id'] ?? null) == $website->id;
            });
        if ($active) {
            return response()->json([
                'message'    => '该网站已有绑定任务在进行中，最长 60 秒内 Agent 会执行',
                'command_id' => $active->id,
            ]);
        }

        $cmd = \App\Models\AgentCommand::create([
            'server_id' => $server->id,
            'type'      => \App\Models\AgentCommand::TYPE_BIND_ROOT,
            'status'    => \App\Models\AgentCommand::STATUS_PENDING,
            'params'    => [
                'website_id'   => $website->id,
                'bind_token'   => $website->bind_token,
                'direct_path'  => $data['direct_path'] ?? null,
                'search_bases' => $data['search_bases'] ?? [],
            ],
        ]);

        return response()->json([
            'message'    => '已下发绑定指令，最长 60 秒内 Agent 会查找密钥文件',
            'command_id' => $cmd->id,
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'server_id' => ['required', 'integer', Rule::exists('servers', 'id')],
            'name' => ['required', 'string', 'max:64'],
            'domain' => ['nullable', 'string', 'max:128'],
            'backup_paths' => ['required_if:backup_source,external', 'array', 'max:20'],
            'backup_paths.*.label' => ['nullable', 'string', 'max:64'],
            'backup_paths.*.path' => ['required', 'string', 'max:255'],
            'backup_paths.*.pattern' => ['nullable', 'string', 'max:128'],
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'schedule' => ['nullable', 'string', 'max:64', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            // 同步方式：auto=自动扫描（现行为），scheduled=只在 schedule 设定时间由 Center 派发 sync_now
            'sync_mode' => ['nullable', 'string', Rule::in(['auto', 'scheduled'])],
            // 备份来源 / 恢复模式（P1 仅存值，self/advanced 的执行能力在 P3/P4 落地）
            'backup_source' => ['nullable', 'string', Rule::in([Website::BACKUP_SOURCE_EXTERNAL, Website::BACKUP_SOURCE_SELF])],
            'restore_mode' => ['nullable', 'string', Rule::in([Website::RESTORE_MODE_LITE, Website::RESTORE_MODE_ADVANCED])],
            'web_root' => ['nullable', 'string', 'max:255'],
            'db_type'  => ['nullable', 'string', Rule::in([Website::DB_TYPE_MYSQL, Website::DB_TYPE_POSTGRESQL])],
            'db_host'  => ['nullable', 'string', 'max:255'],
            'db_port'  => ['nullable', 'integer', 'min:1', 'max:65535'],
            'db_name'  => ['nullable', 'string', 'max:255'],
            'db_user'  => ['nullable', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([Website::STATUS_ENABLED, Website::STATUS_DISABLED])],
            'remark' => ['nullable', 'string', 'max:500'],
            'storage_ids' => ['nullable', 'array'],
            'storage_ids.*' => ['integer', Rule::exists('storages', 'id')],
        ]);
    }

    /**
     * 从 $data 中取出 storage_ids（并从 $data 里删掉，避免 mass assignment）。
     * 返回 null 表示请求里没有这个字段（意思：保持关联不变）
     */
    private function extractStorageIds(array &$data): ?array
    {
        if (! array_key_exists('storage_ids', $data)) {
            return null;
        }
        $ids = $data['storage_ids'];
        unset($data['storage_ids']);
        return is_array($ids) ? $ids : [];
    }
}
