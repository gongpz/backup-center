<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServerResource;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ServerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Server::query()->orderByDesc('id');

        if ($keyword = $request->string('keyword')->trim()->toString()) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('ip', 'like', "%{$keyword}%")
                  ->orWhere('remark', 'like', "%{$keyword}%");
            });
        }

        if ($status = $request->string('status')->toString()) {
            // 这里按 raw status 过滤（简化）
            $query->where('status', $status);
        }

        if ($type = $request->string('connect_type')->toString()) {
            $query->where('connect_type', $type);
        }

        return ServerResource::collection($query->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        if ($data['connect_type'] === Server::CONNECT_AGENT) {
            $data['agent_token'] = Server::generateAgentToken();
            $data['status'] = Server::STATUS_PENDING;
        } else {
            $data['status'] = Server::STATUS_PENDING;
        }

        $server = Server::create($data);

        return (new ServerResource($server->fresh()))
            ->additional(['message' => '添加成功'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Server $server, Request $request): ServerResource
    {
        // 详情默认带 token（前端要复制 Agent 安装命令）
        $request->merge(['with_token' => true]);
        return new ServerResource($server);
    }

    public function update(Server $server, Request $request): ServerResource
    {
        $data = $this->validated($request, $server->id);

        // 连接类型可切换，但要清空对方字段的敏感数据
        if ($data['connect_type'] !== $server->connect_type) {
            if ($data['connect_type'] === Server::CONNECT_AGENT) {
                $data['agent_token'] = $server->agent_token ?: Server::generateAgentToken();
                $data['ssh_private_key'] = null;
            } else {
                $data['agent_token'] = null;
            }
        }

        $server->update($data);
        return new ServerResource($server->fresh());
    }

    public function destroy(Server $server): JsonResponse
    {
        $server->delete();
        return response()->json(['message' => '已删除']);
    }

    public function regenerateToken(Server $server): JsonResponse
    {
        if ($server->connect_type !== Server::CONNECT_AGENT) {
            return response()->json(['message' => '仅 Agent 模式可重生 Token'], 422);
        }

        $server->update([
            'agent_token' => Server::generateAgentToken(),
            'status' => Server::STATUS_PENDING,
            'last_seen_at' => null,
            'registered_at' => null,
            'agent_version' => null,
        ]);

        return response()->json([
            'message' => 'Token 已重生',
            'agent_token' => $server->agent_token,
        ]);
    }

    /* ---------- 参数校验 ---------- */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'ip' => ['nullable', 'string', 'max:64'],
            'os_type' => ['nullable', 'string', 'max:32'],
            'remark' => ['nullable', 'string', 'max:500'],
            'connect_type' => ['required', Rule::in([Server::CONNECT_AGENT, Server::CONNECT_SSH])],

            // SSH 字段（仅 ssh 模式必填部分）
            'ssh_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'ssh_user' => ['nullable', 'string', 'max:64'],
            'ssh_private_key' => ['nullable', 'string'],
            'backup_path' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
