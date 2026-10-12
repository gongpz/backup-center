<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentCommand;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AgentUpdateController extends Controller
{
    /** 前端读取当前 Center 分发的最新 Agent 版本 */
    public function latestVersion(): JsonResponse
    {
        return response()->json([
            'version' => (string) config('agent.latest_version'),
        ]);
    }

    /** 单机下发 update 命令 */
    public function dispatchOne(Server $server): JsonResponse
    {
        if ($server->connect_type !== Server::CONNECT_AGENT) {
            return response()->json(['message' => '仅 Agent 模式服务器可下发升级'], 422);
        }

        $result = DB::transaction(function () use ($server) {
            // 锁定该服务器行防止并发派单
            $locked = Server::where('id', $server->id)->lockForUpdate()->first();
            if ($this->hasActiveUpdateCommand($locked)) {
                return ['status' => 'conflict'];
            }
            $cmd = $this->createUpdateCommand($locked);
            return ['status' => 'ok', 'command' => $cmd];
        });

        if ($result['status'] === 'conflict') {
            return response()->json(['message' => '该服务器已有升级任务在执行中，请稍后再看'], 409);
        }
        return response()->json([
            'message'    => '已下发升级指令，最长 60 秒后 Agent 收到',
            'command_id' => $result['command']->id,
        ]);
    }

    /** 批量下发 */
    public function bulkDispatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'server_ids'   => ['nullable', 'array'],
            'server_ids.*' => ['integer', Rule::exists('servers', 'id')],
        ]);

        $latest = (string) config('agent.latest_version');

        $q = Server::where('connect_type', Server::CONNECT_AGENT);
        if (! empty($data['server_ids'])) {
            $q->whereIn('id', $data['server_ids']);
        } else {
            $q->where(function ($sq) use ($latest) {
                $sq->whereNull('agent_version')->orWhere('agent_version', '!=', $latest);
            });
        }
        $servers = $q->get();

        $created = 0;
        $skipped = 0;
        foreach ($servers as $s) {
            $ok = DB::transaction(function () use ($s) {
                $locked = Server::where('id', $s->id)->lockForUpdate()->first();
                if ($this->hasActiveUpdateCommand($locked)) {
                    return false;
                }
                $this->createUpdateCommand($locked);
                return true;
            });
            if ($ok) {
                $created++;
            } else {
                $skipped++;
            }
        }

        return response()->json([
            'message' => "已下发 {$created} 台，跳过 {$skipped} 台（已有升级任务）",
            'created' => $created,
            'skipped' => $skipped,
            'total'   => $servers->count(),
        ]);
    }

    /**
     * 判断是否已有活动升级命令。dispatched 超过 STALE_MINUTES 视为 stale，允许覆盖。
     * pending 状态永远算冲突（否则用户点两次就会堆积）
     */
    private function hasActiveUpdateCommand(Server $server): bool
    {
        $staleCutoff = now()->subMinutes(AgentCommand::STALE_MINUTES);

        return $server->agentCommands()
            ->where('type', AgentCommand::TYPE_UPDATE)
            ->where(function ($q) use ($staleCutoff) {
                $q->where('status', AgentCommand::STATUS_PENDING)
                  ->orWhere(function ($qq) use ($staleCutoff) {
                      $qq->where('status', AgentCommand::STATUS_DISPATCHED)
                         ->where('dispatched_at', '>', $staleCutoff);
                  });
            })
            ->exists();
    }

    private function createUpdateCommand(Server $server): AgentCommand
    {
        return AgentCommand::create([
            'server_id'    => $server->id,
            'type'         => AgentCommand::TYPE_UPDATE,
            'from_version' => $server->agent_version,
            'to_version'   => (string) config('agent.latest_version'),
            'status'       => AgentCommand::STATUS_PENDING,
        ]);
    }
}
