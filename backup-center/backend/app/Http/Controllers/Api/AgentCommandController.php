<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentCommand;
use Illuminate\Http\JsonResponse;

class AgentCommandController extends Controller
{
    /**
     * 查询一条指令的执行状态（前端轮询用）。
     * 指令经 Agent 心跳下发、异步执行，前端下发时只拿到 command_id；
     * 轮询此接口把 Agent 回传的 output/error 展示出来，
     * 避免「点了按钮只提示已下发、之后没有任何反馈」。
     */
    public function show(AgentCommand $command): JsonResponse
    {
        return response()->json([
            'command' => [
                'id'            => $command->id,
                'type'          => $command->type,
                'status'        => $command->status,
                'output'        => $command->output,
                'error'         => $command->error,
                'dispatched_at' => optional($command->dispatched_at)->toIso8601String(),
                'completed_at'  => optional($command->completed_at)->toIso8601String(),
            ],
        ]);
    }
}
