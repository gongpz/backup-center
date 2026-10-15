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
 * 根目录绑定（bind_root）下发契约测试：
 *
 * - bindRoot：agent 校验、生成 bind_token、params 携带 website_id/bind_token/search_bases
 * - 指令执行状态的轮询见 AgentCommandStatusTest（GET /api/agent-commands/{command}）
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

    public function test_bind_root_creates_command_with_expected_params(): void
    {
        $website = $this->makeWebsite($this->agentServer());

        $resp = (new WebsiteController())->bindRoot($website, new Request([
            'search_bases' => ['/www/wwwroot', '/home/www'],
        ]));

        $this->assertSame(200, $resp->getStatusCode());
        $payload = $resp->getData(true);
        $this->assertArrayHasKey('command_id', $payload);

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

    public function test_bind_root_reuses_active_command_for_same_website(): void
    {
        $website    = $this->makeWebsite($this->agentServer());
        $controller = new WebsiteController();

        $first  = $controller->bindRoot($website, new Request(['search_bases' => ['/www/wwwroot']]))->getData(true);
        $second = $controller->bindRoot($website, new Request(['search_bases' => ['/www/wwwroot']]))->getData(true);

        $this->assertSame($first['command_id'], $second['command_id']);
        $this->assertSame(1, AgentCommand::where('type', AgentCommand::TYPE_BIND_ROOT)->count());
    }

    public function test_bind_root_rejects_non_agent_server(): void
    {
        $server  = Server::create(['name' => 'SSH 服务器', 'connect_type' => Server::CONNECT_SSH]);
        $website = $this->makeWebsite($server);

        $resp = (new WebsiteController())->bindRoot($website, new Request(['search_bases' => ['/www/wwwroot']]));

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame(0, AgentCommand::count());
    }
}
