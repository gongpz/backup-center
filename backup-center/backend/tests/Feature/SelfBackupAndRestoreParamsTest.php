<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\RestoreController;
use App\Http\Controllers\Api\WebsiteController;
use App\Models\AgentCommand;
use App\Models\BackupLog;
use App\Models\BackupUpload;
use App\Models\Server;
use App\Models\Storage;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * P3 自主压缩 / P4 高级恢复的后端指令契约聚焦测试（直接调 Controller，绕开 auth 中间件）：
 *
 * - selfBackupNow：agent+self 双重校验、pending/dispatched 去重、params 只含 website_id/website_name（无凭据）
 * - restore store()：advanced 追加 restore_mode+website_id 且 local_dir 走临时中转目录；
 *   lite 保持 dirname(full_path) 且不追加任何键；dedup 键含 restore_mode（lite/advanced 不互斥）
 */
class SelfBackupAndRestoreParamsTest extends TestCase
{
    use RefreshDatabase;

    /** AgentCommand.params / restore params 中绝不允许出现的凭据类键 */
    private const CREDENTIAL_KEYS = ['db_password', 'db_host', 'db_port', 'db_name', 'db_user', 'db_type', 'web_root'];

    private function agentServer(): Server
    {
        return Server::create([
            'name'         => 'Agent 服务器',
            'connect_type' => Server::CONNECT_AGENT,
            'status'       => Server::STATUS_ONLINE,
        ]);
    }

    private function makeWebsite(
        Server $server,
        string $source = Website::BACKUP_SOURCE_SELF,
        string $restoreMode = Website::RESTORE_MODE_LITE,
    ): Website {
        return Website::create([
            'server_id'      => $server->id,
            'name'           => '演示站点',
            'backup_source'  => $source,
            'restore_mode'   => $restoreMode,
            'web_root'       => '/var/www/demo',
            'db_type'        => Website::DB_TYPE_MYSQL,
            'db_host'        => '127.0.0.1',
            'db_port'        => 3306,
            'db_name'        => 'demo',
            'db_user'        => 'root',
            'db_password'    => 's3cret-pass', // 加密入库；绝不应出现在任何 AgentCommand.params
            'status'         => Website::STATUS_ENABLED,
        ]);
    }

    private function makeLogWithUpload(Website $website, string $filename, string $fullPath, string $remotePath): BackupLog
    {
        $storage = Storage::create([
            'name'   => '测试存储',
            'type'   => Storage::TYPE_SFTP,
            'config' => ['host' => '127.0.0.1'],
        ]);

        $log = BackupLog::create([
            'website_id' => $website->id,
            'server_id'  => $website->server_id,
            'filename'   => $filename,
            'full_path'  => $fullPath,
            'status'     => BackupLog::STATUS_SUCCESS,
            'backup_at'  => now(),
        ]);
        BackupUpload::create([
            'backup_log_id' => $log->id,
            'storage_id'    => $storage->id,
            'status'        => BackupUpload::STATUS_UPLOADED,
            'remote_path'   => $remotePath,
        ]);

        return $log;
    }

    /* ---------------- P3：selfBackupNow ---------------- */

    public function test_self_backup_now_creates_command_with_only_safe_params(): void
    {
        $website = $this->makeWebsite($this->agentServer());

        $resp = (new WebsiteController())->selfBackupNow($website);

        $this->assertSame(200, $resp->getStatusCode());
        $payload = $resp->getData(true);
        $this->assertArrayHasKey('command_id', $payload);

        $cmd = AgentCommand::latest('id')->first();
        $this->assertNotNull($cmd);
        $this->assertSame(AgentCommand::TYPE_SELF_BACKUP, $cmd->type);
        $this->assertSame(AgentCommand::STATUS_PENDING, $cmd->status);
        $this->assertSame($website->server_id, $cmd->server_id);
        $this->assertSame(['website_id' => $website->id, 'website_name' => '演示站点'], $cmd->params);

        // 红线：params 绝不出现任何凭据 / 连接信息
        foreach (self::CREDENTIAL_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $cmd->params);
        }
    }

    public function test_self_backup_now_reuses_pending_command_for_same_website(): void
    {
        $website = $this->makeWebsite($this->agentServer());
        $controller = new WebsiteController();

        $first  = $controller->selfBackupNow($website)->getData(true);
        $second = $controller->selfBackupNow($website)->getData(true);

        $this->assertSame($first['command_id'], $second['command_id']);
        $this->assertSame(1, AgentCommand::where('type', AgentCommand::TYPE_SELF_BACKUP)->count());
    }

    public function test_self_backup_now_rejects_non_agent_server(): void
    {
        $server = Server::create(['name' => 'SSH 服务器', 'connect_type' => Server::CONNECT_SSH]);
        $website = $this->makeWebsite($server);

        $resp = (new WebsiteController())->selfBackupNow($website);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame(0, AgentCommand::count());
    }

    public function test_self_backup_now_rejects_external_source(): void
    {
        $website = $this->makeWebsite($this->agentServer(), Website::BACKUP_SOURCE_EXTERNAL);

        $resp = (new WebsiteController())->selfBackupNow($website);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame(0, AgentCommand::count());
    }

    /* ---------------- P4：restore store() ---------------- */

    public function test_restore_advanced_appends_mode_and_uses_temp_local_dir(): void
    {
        $website = $this->makeWebsite($this->agentServer(), Website::BACKUP_SOURCE_SELF, Website::RESTORE_MODE_ADVANCED);
        // self 产物的 full_path 在 Agent 临时目录（P3），不能把归档拉回 /tmp 当目标
        $log = $this->makeLogWithUpload(
            $website,
            'demo-web-20261014-101010.tar.gz',
            sys_get_temp_dir() . '/bc-self-1-x/demo-web-20261014-101010.tar.gz',
            'stor:demo/demo-web-20261014-101010.tar.gz',
        );

        $resp = (new RestoreController())->store(new Request(['backup_log_ids' => [$log->id]]));

        $this->assertSame(200, $resp->getStatusCode());
        $cmd = AgentCommand::where('type', AgentCommand::TYPE_RESTORE)->latest('id')->first();
        $this->assertNotNull($cmd);
        $this->assertSame('advanced', $cmd->params['restore_mode']);
        $this->assertSame($website->id, $cmd->params['website_id']);
        $this->assertStringStartsWith(
            sys_get_temp_dir() . '/bc-restore-' . $website->id . '-',
            $cmd->params['local_dir'],
        );

        // 红线：params 不出现 db_* / web_root，Agent 自行从 websites() 内存通道解析
        foreach (self::CREDENTIAL_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $cmd->params);
        }
    }

    public function test_restore_lite_keeps_dirname_local_dir_without_extra_params(): void
    {
        $website  = $this->makeWebsite($this->agentServer(), Website::BACKUP_SOURCE_EXTERNAL, Website::RESTORE_MODE_LITE);
        $fullPath = '/data/backup/site/demo-web.tar.gz';
        $log = $this->makeLogWithUpload($website, 'demo-web.tar.gz', $fullPath, 'stor:demo/demo-web.tar.gz');

        $resp = (new RestoreController())->store(new Request(['backup_log_ids' => [$log->id]]));

        $this->assertSame(200, $resp->getStatusCode());
        $cmd = AgentCommand::where('type', AgentCommand::TYPE_RESTORE)->latest('id')->first();
        $this->assertNotNull($cmd);
        $this->assertSame(dirname($fullPath), $cmd->params['local_dir']);
        $this->assertArrayNotHasKey('restore_mode', $cmd->params);
        $this->assertArrayNotHasKey('website_id', $cmd->params);
    }

    public function test_restore_dedup_key_includes_restore_mode(): void
    {
        $website = $this->makeWebsite($this->agentServer(), Website::BACKUP_SOURCE_SELF, Website::RESTORE_MODE_ADVANCED);
        $log = $this->makeLogWithUpload(
            $website,
            'demo-db-20261014-101010.sql.gz',
            sys_get_temp_dir() . '/bc-self-1-x/demo-db-20261014-101010.sql.gz',
            'stor:demo/demo-db-20261014-101010.sql.gz',
        );
        $controller = new RestoreController();

        // 第一次 advanced 正常下发
        $controller->store(new Request(['backup_log_ids' => [$log->id]]));

        // 同一 upload 再次 advanced：被去重跳过（无新命令，整体 422 + skipped 原因）
        $resp2 = $controller->store(new Request(['backup_log_ids' => [$log->id]]));
        $this->assertSame(422, $resp2->getStatusCode());
        $payload2 = $resp2->getData(true);
        $this->assertSame('该文件已有恢复任务在进行中', $payload2['skipped'][0]['reason']);
        $this->assertSame(1, AgentCommand::where('type', AgentCommand::TYPE_RESTORE)->count());

        // 切到 lite：pending 的 advanced 不阻塞 lite（dedup 键含 restore_mode，二者不互斥）
        $website->update(['restore_mode' => Website::RESTORE_MODE_LITE]);
        $resp3 = $controller->store(new Request(['backup_log_ids' => [$log->id]]));
        $this->assertSame(200, $resp3->getStatusCode());
        $this->assertSame(2, AgentCommand::where('type', AgentCommand::TYPE_RESTORE)->count());
    }
}
