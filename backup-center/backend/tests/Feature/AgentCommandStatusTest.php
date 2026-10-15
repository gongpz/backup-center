<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AgentCommandController;
use App\Models\AgentCommand;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 指令状态查询（GET /api/agent-commands/{command}）契约测试：
 *
 * 指令经心跳下发、Agent 异步执行，前端下发时只拿到 command_id。轮询此接口把
 * Agent 回传的 output/error 展示出来——同步/绑定/自主备份都靠它把「已下发」
 * 之后的真实执行情况反馈给用户，避免点了按钮没有任何回音。
 */
class AgentCommandStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeCommand(array $attributes = []): AgentCommand
    {
        $server = Server::create([
            'name'         => 'Agent 服务器',
            'connect_type' => Server::CONNECT_AGENT,
            'status'       => Server::STATUS_ONLINE,
        ]);

        return AgentCommand::create(array_merge([
            'server_id'    => $server->id,
            'type'         => AgentCommand::TYPE_SYNC_NOW,
            'status'       => AgentCommand::STATUS_DISPATCHED,
            'params'       => ['website_id' => 1, 'website_name' => '演示站点'],
            'dispatched_at'=> now(),
        ], $attributes));
    }

    public function test_show_returns_finalized_command_with_output_and_error(): void
    {
        $cmd = $this->makeCommand([
            'status'       => AgentCommand::STATUS_FAILED,
            'output'       => '已完成一次全量扫描，用时 1.20s：扫描 2 个网站（1 个未配置备份目录已跳过），上报 0 个文件，触发 0 个上传',
            'error'        => '在给定搜索基址内未找到密钥文件 .bc-bind-abc',
            'completed_at' => now(),
        ]);

        $resp = (new AgentCommandController())->show($cmd);

        $this->assertSame(200, $resp->getStatusCode());
        $payload = $resp->getData(true);
        $this->assertSame($cmd->id, $payload['command']['id']);
        $this->assertSame(AgentCommand::TYPE_SYNC_NOW, $payload['command']['type']);
        $this->assertSame(AgentCommand::STATUS_FAILED, $payload['command']['status']);
        $this->assertStringContainsString('未配置备份目录已跳过', $payload['command']['output']);
        $this->assertSame('在给定搜索基址内未找到密钥文件 .bc-bind-abc', $payload['command']['error']);
        $this->assertNotNull($payload['command']['completed_at']);
    }

    public function test_show_returns_pending_command_with_nulls(): void
    {
        $cmd = $this->makeCommand([
            'status'        => AgentCommand::STATUS_PENDING,
            'dispatched_at' => null,
        ]);

        $resp = (new AgentCommandController())->show($cmd);

        $payload = $resp->getData(true);
        $this->assertSame(AgentCommand::STATUS_PENDING, $payload['command']['status']);
        $this->assertNull($payload['command']['output']);
        $this->assertNull($payload['command']['error']);
        $this->assertNull($payload['command']['dispatched_at']);
        $this->assertNull($payload['command']['completed_at']);
    }
}
