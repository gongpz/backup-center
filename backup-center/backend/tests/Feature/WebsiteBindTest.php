<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\WebsiteController;
use App\Models\AgentCommand;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 根目录绑定（bind_root）后端契约测试：
 *
 * - bindRoot：agent 校验、生成 bind_token、params 携带 website_id/bind_token/search_bases
 * - bindStatus：透出最新一条 bind_root 指令的终态与 error——
 *   前端只轮询 web_root 无法区分「还在等」与「Agent 已报错」，失败原因（如密钥文件名
 *   不符）必须经此接口可见，否则用户只能看到 90 秒通用超时文案
 */
class WebsiteBindTest extends TestCase
{
    use RefreshDatabase;

    private function agentServer(): Server
    {
        return Server::create([
            'name'         => 'Agent 服务器',
            'connect_type' => Server::CONNECT_AGENT,
            'status'       => Server::STATUS_ONLINE,
        ]);
    }

    private function makeWebsite(Server $server): Website
    {
        return Website::create([
            'server_id'     => $server->id,
            'name'          => '演示站点',
            'status'        => Website::STATUS_ENABLED,
        ]);
    }

    private function bindCommand(Website $website, string $status, ?string $error = null): AgentCommand
    {
        return AgentCommand::create([
            'server_id'    => $website->server_id,
            'type'         => AgentCommand::TYPE_BIND_ROOT,
            'status'       => $status,
            'params'       => [
                'website_id'   => $website->id,
                'bind_token'   => $website->bind_token,
                'direct_path'  => null,
                'search_bases' => ['/www/wwwroot'],
            ],
            'error'        => $error,
            'dispatched_at'=> now(),
            'completed_at' => in_array($status, [AgentCommand::STATUS_SUCCESS, AgentCommand::STATUS_FAILED], true) ? now() : null,
        ]);
    }

    public function test_bind_root_creates_command_with_expected_params(): void
    {
        $website = $this->makeWebsite($this->agentServer());

        $resp = (new WebsiteController())->bindRoot($website, new Request([
            'search_bases' => ['/www/wwwroot', '/home/www'],
        ]));

        $this->assertSame(200, $resp->getStatusCode());
        $cmd = AgentCommand::latest('id')->first();
        $this->assertNotNull($cmd);
        $this->assertSame(AgentCommand::TYPE_BIND_ROOT, $cmd->type);
        $this->assertSame(AgentCommand::STATUS_PENDING, $cmd->status);
        $this->assertSame($website->server_id, $cmd->server_id);
        $this->assertSame($website->id, $cmd->params['website_id']);
        $this->assertSame($website->bind_token, $cmd->params['bind_token']);
        $this->assertSame(['/www/wwwroot', '/home/www'], $cmd->params['search_bases']);
        $this->assertNotEmpty($website->fresh()->bind_token);
    }

    public function test_bind_root_rejects_non_agent_server(): void
    {
        $server  = Server::create(['name' => 'SSH 服务器', 'connect_type' => Server::CONNECT_SSH]);
        $website = $this->makeWebsite($server);

        $resp = (new WebsiteController())->bindRoot($website, new Request(['search_bases' => ['/www/wwwroot']]));

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame(0, AgentCommand::count());
    }

    public function test_bind_status_returns_failed_command_with_error(): void
    {
        $website = $this->makeWebsite($this->agentServer());
        $this->bindCommand($website, AgentCommand::STATUS_FAILED, '在给定搜索基址内未找到密钥文件 .bc-bind-abc');

        $resp = (new WebsiteController())->bindStatus($website);

        $this->assertSame(200, $resp->getStatusCode());
        $payload = $resp->getData(true);
        $this->assertSame(AgentCommand::STATUS_FAILED, $payload['command']['status']);
        $this->assertSame('在给定搜索基址内未找到密钥文件 .bc-bind-abc', $payload['command']['error']);
        $this->assertNotNull($payload['command']['completed_at']);
    }

    public function test_bind_status_returns_latest_command_only(): void
    {
        $website = $this->makeWebsite($this->agentServer());
        $this->bindCommand($website, AgentCommand::STATUS_FAILED, '第一次：文件名不符');
        $this->bindCommand($website, AgentCommand::STATUS_DISPATCHED);

        $resp = (new WebsiteController())->bindStatus($website);

        $payload = $resp->getData(true);
        $this->assertSame(AgentCommand::STATUS_DISPATCHED, $payload['command']['status']);
        $this->assertNull($payload['command']['error']);
        $this->assertNull($payload['command']['completed_at']);
    }

    public function test_bind_status_returns_null_without_command(): void
    {
        $website = $this->makeWebsite($this->agentServer());

        $resp = (new WebsiteController())->bindStatus($website);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertNull($resp->getData(true)['command']);
    }
}
