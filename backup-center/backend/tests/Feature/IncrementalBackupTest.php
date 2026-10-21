<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\RestoreController;
use App\Http\Controllers\Api\WebsiteController;
use App\Models\AgentCommand;
use App\Models\BackupLog;
use App\Models\BackupUpload;
use App\Models\Server;
use App\Models\Storage;
use App\Models\Website;
use App\Support\BackupSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * 增量备份（P5）后端契约聚焦测试（直接调 Controller，绕开 auth 中间件），
 * 对应 .workbuddy/incremental-backup-spec.md §5.1 七组用例：
 *
 * 1. incremental_backup 端点：external 站 422；full 模式 422；无目标存储 422；
 *    正常 → 命令 type/params 正确；二次调用复用 command_id。
 * 2. 网站 store/update 校验矩阵：incremental+external 422；incremental+非 sftp 422；
 *    incremental+sftp 无私钥 422；incremental+加密 sftp 422；happy path 落库。
 * 3. AgentController::websites：响应含 backup_mode/incremental_storage_id；
 *    增量目标存储出现在顶层 storages（即使不在 website_storages pivot）。
 * 4. RestoreController::backups：快照日志（带 batch_code）独立成组、type=snapshot、
 *    label='增量快照'、日期分组正确、无上传时 restorable=false。
 * 5. RestoreController::store：快照日志 → params 含 path_type=dir 且 local_dir 在
 *    sys_get_temp_dir() 下；同快照的 db 文件日志 → 普通文件参数（无 path_type）。
 * 6. BackupSource::derive：-snap-<stamp> → self:snapshot；-db-<stamp>.sql.gz → self:db 不变。
 * 7. 既有套件回归（RestoreSuiteWindowTest 等）由全量 phpunit 覆盖。
 *
 * 附：Website::recentSelfBackups 快照项（规格 §1.2）。
 */
class IncrementalBackupTest extends TestCase
{
    use RefreshDatabase;

    /* ----------------  fixtures ---------------- */

    private function agentServer(): Server
    {
        return Server::create([
            'name'         => 'Agent 服务器',
            'connect_type' => Server::CONNECT_AGENT,
            'status'       => Server::STATUS_ONLINE,
        ]);
    }

    /** SFTP 存储；默认带 SSH 私钥、未加密（合法增量目标） */
    private function sftpStorage(array $overrides = []): Storage
    {
        return Storage::create($overrides + [
            'name'   => '增量目标 VPS',
            'type'   => Storage::TYPE_SFTP,
            'config' => ['host' => '10.0.0.9', 'user' => 'root', 'private_key' => "-----BEGIN KEY-----\nxx"],
        ]);
    }

    private function makeWebsite(
        Server $server,
        string $source = Website::BACKUP_SOURCE_SELF,
        string $backupMode = Website::BACKUP_MODE_FULL,
        ?int $incrementalStorageId = null,
    ): Website {
        return Website::create([
            'server_id'              => $server->id,
            'name'                   => '演示站点',
            'backup_source'          => $source,
            'backup_mode'            => $backupMode,
            'incremental_storage_id' => $incrementalStorageId,
            'web_root'               => '/var/www/demo',
            'db_type'                => Website::DB_TYPE_MYSQL,
            'db_host'                => '127.0.0.1',
            'db_port'                => 3306,
            'db_name'                => 'demo',
            'db_user'                => 'root',
            'db_password'            => 's3cret-pass',
            'status'                 => Website::STATUS_ENABLED,
        ]);
    }

    private function makeLogWithUpload(
        Website $website,
        string $filename,
        string $fullPath,
        string $remotePath,
        string $batchCode = '',
    ): BackupLog {
        $storage = $this->sftpStorage(['name' => '测试存储']);

        $log = BackupLog::create([
            'website_id' => $website->id,
            'server_id'  => $website->server_id,
            'filename'   => $filename,
            'full_path'  => $fullPath,
            'size'       => 2048,
            'status'     => BackupLog::STATUS_SUCCESS,
            'backup_at'  => now(),
            'batch_code' => $batchCode,
        ]);
        BackupUpload::create([
            'backup_log_id' => $log->id,
            'storage_id'    => $storage->id,
            'status'        => BackupUpload::STATUS_UPLOADED,
            'remote_path'   => $remotePath,
        ]);

        return $log;
    }

    /** abort(422, ...) 断言辅助：捕获 HttpException 并校验状态码与文案 */
    private function assertAborts422(callable $fn, string $message): void
    {
        try {
            $fn();
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame($message, $e->getMessage());
            return;
        }
        $this->fail('预期 abort(422, ' . $message . ')，但调用正常返回了');
    }

    /* ---------------- 1. incremental_backup 端点 ---------------- */

    public function test_incremental_backup_now_rejects_non_agent_server(): void
    {
        $server  = Server::create(['name' => 'SSH 服务器', 'connect_type' => Server::CONNECT_SSH]);
        $website = $this->makeWebsite($server, Website::BACKUP_SOURCE_SELF, Website::BACKUP_MODE_INCREMENTAL, $this->sftpStorage()->id);

        $resp = (new WebsiteController())->incrementalBackupNow($website);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('该网站所属服务器不是 Agent 模式，无法增量备份', $resp->getData(true)['message']);
        $this->assertSame(0, AgentCommand::count());
    }

    public function test_incremental_backup_now_rejects_external_source(): void
    {
        $website = $this->makeWebsite($this->agentServer(), Website::BACKUP_SOURCE_EXTERNAL, Website::BACKUP_MODE_INCREMENTAL);

        $resp = (new WebsiteController())->incrementalBackupNow($website);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('该网站未启用自主压缩备份（backup_source=self）', $resp->getData(true)['message']);
        $this->assertSame(0, AgentCommand::count());
    }

    public function test_incremental_backup_now_rejects_full_mode(): void
    {
        $website = $this->makeWebsite($this->agentServer(), Website::BACKUP_SOURCE_SELF, Website::BACKUP_MODE_FULL);

        $resp = (new WebsiteController())->incrementalBackupNow($website);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('该网站未启用增量备份（backup_mode=incremental）', $resp->getData(true)['message']);
        $this->assertSame(0, AgentCommand::count());
    }

    public function test_incremental_backup_now_rejects_missing_target_storage(): void
    {
        $website = $this->makeWebsite($this->agentServer(), Website::BACKUP_SOURCE_SELF, Website::BACKUP_MODE_INCREMENTAL, null);

        $resp = (new WebsiteController())->incrementalBackupNow($website);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('未配置增量备份目标存储，请在编辑网站时选择', $resp->getData(true)['message']);
        $this->assertSame(0, AgentCommand::count());
    }

    public function test_incremental_backup_now_creates_command_and_reuses_pending(): void
    {
        $website = $this->makeWebsite(
            $this->agentServer(),
            Website::BACKUP_SOURCE_SELF,
            Website::BACKUP_MODE_INCREMENTAL,
            $this->sftpStorage()->id,
        );
        $controller = new WebsiteController();

        $first = $controller->incrementalBackupNow($website);
        $this->assertSame(200, $first->getStatusCode());
        $firstData = $first->getData(true);
        $this->assertArrayHasKey('command_id', $firstData);

        $cmd = AgentCommand::latest('id')->first();
        $this->assertNotNull($cmd);
        $this->assertSame(AgentCommand::TYPE_INCREMENTAL_BACKUP, $cmd->type);
        $this->assertSame(AgentCommand::STATUS_PENDING, $cmd->status);
        $this->assertSame($website->server_id, $cmd->server_id);
        $this->assertSame(['website_id' => $website->id, 'website_name' => '演示站点'], $cmd->params);

        // 二次调用复用同一条 pending 指令，不重复创建
        $second = $controller->incrementalBackupNow($website);
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame($firstData['command_id'], $second->getData(true)['command_id']);
        $this->assertSame(1, AgentCommand::where('type', AgentCommand::TYPE_INCREMENTAL_BACKUP)->count());
    }

    /* ---------------- 2. store/update 校验矩阵 ---------------- */

    public function test_store_incremental_with_external_source_gets_422(): void
    {
        $server = $this->agentServer();
        $this->assertAborts422(function () use ($server) {
            (new WebsiteController())->store(new Request([
                'server_id' => $server->id,
                'name'      => '外部站',
                'backup_source' => Website::BACKUP_SOURCE_EXTERNAL,
                'backup_paths'  => [['path' => '/data/backup', 'pattern' => '*']],
                'backup_mode'   => Website::BACKUP_MODE_INCREMENTAL,
                'incremental_storage_id' => $this->sftpStorage()->id,
            ]));
        }, '增量备份需要先启用自主压缩备份（backup_source=self）');
        $this->assertSame(0, Website::count());
    }

    public function test_store_incremental_with_non_sftp_storage_gets_422(): void
    {
        $server = $this->agentServer();
        $s3 = Storage::create([
            'name'   => '对象存储',
            'type'   => Storage::TYPE_S3,
            'config' => ['endpoint' => 'https://s3.example.com'],
        ]);
        $this->assertAborts422(function () use ($server, $s3) {
            (new WebsiteController())->store(new Request([
                'server_id' => $server->id,
                'name'      => '增量站',
                'backup_source' => Website::BACKUP_SOURCE_SELF,
                'backup_mode'   => Website::BACKUP_MODE_INCREMENTAL,
                'incremental_storage_id' => $s3->id,
            ]));
        }, '增量备份目标必须是 SFTP 类型存储（rsync 经 SSH 推送到 VPS）');
        $this->assertSame(0, Website::count());
    }

    public function test_store_incremental_sftp_without_private_key_gets_422(): void
    {
        $server = $this->agentServer();
        $noKey = Storage::create([
            'name'   => '无密钥 VPS',
            'type'   => Storage::TYPE_SFTP,
            'config' => ['host' => '10.0.0.10', 'user' => 'root'],
        ]);
        $this->assertAborts422(function () use ($server, $noKey) {
            (new WebsiteController())->store(new Request([
                'server_id' => $server->id,
                'name'      => '增量站',
                'backup_source' => Website::BACKUP_SOURCE_SELF,
                'backup_mode'   => Website::BACKUP_MODE_INCREMENTAL,
                'incremental_storage_id' => $noKey->id,
            ]));
        }, '增量备份目标存储必须配置 SSH 私钥（rsync 不支持交互式密码输入）');
        $this->assertSame(0, Website::count());
    }

    public function test_store_incremental_with_encrypted_sftp_gets_422(): void
    {
        $server = $this->agentServer();
        $encrypted = $this->sftpStorage([
            'name'   => '加密 VPS',
            'config' => [
                'host' => '10.0.0.11', 'user' => 'root', 'private_key' => "-----BEGIN KEY-----\nxx",
                'encryption' => ['enabled' => true, 'key_mode' => 'center', 'password' => 'pw'],
            ],
        ]);
        $this->assertAborts422(function () use ($server, $encrypted) {
            (new WebsiteController())->store(new Request([
                'server_id' => $server->id,
                'name'      => '增量站',
                'backup_source' => Website::BACKUP_SOURCE_SELF,
                'backup_mode'   => Website::BACKUP_MODE_INCREMENTAL,
                'incremental_storage_id' => $encrypted->id,
            ]));
        }, '增量备份目标存储不能开启加密（rsync 明文写入，与加密层不兼容）');
        $this->assertSame(0, Website::count());
    }

    public function test_store_incremental_happy_path_persists(): void
    {
        $server  = $this->agentServer();
        $storage = $this->sftpStorage();

        $resp = (new WebsiteController())->store(new Request([
            'server_id' => $server->id,
            'name'      => '增量站',
            'backup_source' => Website::BACKUP_SOURCE_SELF,
            'backup_mode'   => Website::BACKUP_MODE_INCREMENTAL,
            'incremental_storage_id' => $storage->id,
        ]));

        $this->assertSame(201, $resp->getStatusCode());
        // WebsiteResource 响应包在 data 里，透出两个新字段
        $payload = $resp->getData(true)['data'];
        $this->assertSame('incremental', $payload['backup_mode']);
        $this->assertSame($storage->id, $payload['incremental_storage_id']);

        $this->assertDatabaseHas('websites', [
            'name'                    => '增量站',
            'backup_source'           => Website::BACKUP_SOURCE_SELF,
            'backup_mode'             => Website::BACKUP_MODE_INCREMENTAL,
            'incremental_storage_id'  => $storage->id,
        ]);
    }

    public function test_store_full_mode_skips_incremental_validation(): void
    {
        $server  = $this->agentServer();
        $s3 = Storage::create(['name' => '对象存储', 'type' => Storage::TYPE_S3, 'config' => ['endpoint' => 'x']]);

        // full 模式不校验 incremental_storage_id（允许滞留，切回 full 即失效）
        $resp = (new WebsiteController())->store(new Request([
            'server_id' => $server->id,
            'name'      => '全量站',
            'backup_source' => Website::BACKUP_SOURCE_SELF,
            'backup_mode'   => Website::BACKUP_MODE_FULL,
            'incremental_storage_id' => $s3->id,
        ]));

        $this->assertSame(201, $resp->getStatusCode());
        $this->assertSame('full', $resp->getData(true)['data']['backup_mode']);
    }

    public function test_update_incremental_merges_existing_values_for_partial_update(): void
    {
        $server  = $this->agentServer();
        $storage = $this->sftpStorage();
        $website = $this->makeWebsite($server, Website::BACKUP_SOURCE_SELF, Website::BACKUP_MODE_INCREMENTAL, $storage->id);
        $controller = new WebsiteController();

        // 局部更新（只改名字）：用库中现值合并后仍合法，不得 422
        $resp = $controller->update($website, new Request(['server_id' => $server->id, 'name' => '改名站点']));
        $this->assertSame('改名站点', $resp->resolve()['name']);
        $this->assertDatabaseHas('websites', [
            'id' => $website->id,
            'backup_mode' => Website::BACKUP_MODE_INCREMENTAL,
            'incremental_storage_id' => $storage->id,
        ]);

        // 切回 full：incremental_storage_id 允许滞留（切回 full 即失效）
        $resp2 = $controller->update($website->fresh(), new Request([
            'server_id' => $server->id, 'name' => '改名站点',
            'backup_mode' => Website::BACKUP_MODE_FULL,
        ]));
        $this->assertSame('full', $resp2->resolve()['backup_mode']);
        $this->assertDatabaseHas('websites', [
            'id' => $website->id,
            'backup_mode' => Website::BACKUP_MODE_FULL,
            'incremental_storage_id' => $storage->id,
        ]);

        // 无存储的全量站开增量：生效值缺 incremental_storage_id → 422
        $plain = $this->makeWebsite($server, Website::BACKUP_SOURCE_SELF, Website::BACKUP_MODE_FULL, null);
        $this->assertAborts422(function () use ($controller, $plain, $server) {
            $controller->update($plain, new Request([
                'server_id' => $server->id, 'name' => '演示站点',
                'backup_mode' => Website::BACKUP_MODE_INCREMENTAL,
            ]));
        }, '请选择增量备份目标存储');
    }

    public function test_update_enabling_incremental_happy_path(): void
    {
        $server  = $this->agentServer();
        $storage = $this->sftpStorage();
        $website = $this->makeWebsite($server, Website::BACKUP_SOURCE_SELF, Website::BACKUP_MODE_FULL, null);

        $resp = (new WebsiteController())->update($website, new Request([
            'server_id' => $server->id, 'name' => '演示站点',
            'backup_mode'   => Website::BACKUP_MODE_INCREMENTAL,
            'incremental_storage_id' => $storage->id,
        ]));

        $this->assertSame('incremental', $resp->resolve()['backup_mode']);
        $this->assertDatabaseHas('websites', [
            'id' => $website->id,
            'backup_mode' => Website::BACKUP_MODE_INCREMENTAL,
            'incremental_storage_id' => $storage->id,
        ]);
    }

    /* ---------------- 3. AgentController::websites ---------------- */

    public function test_agent_websites_includes_backup_mode_and_incremental_storage_in_top_level_storages(): void
    {
        $server  = $this->agentServer();
        $storage = $this->sftpStorage();
        $incSite = $this->makeWebsite($server, Website::BACKUP_SOURCE_SELF, Website::BACKUP_MODE_INCREMENTAL, $storage->id);
        $fullSite = $this->makeWebsite($server, Website::BACKUP_SOURCE_EXTERNAL, Website::BACKUP_MODE_FULL, null);
        $fullSite->update(['name' => '全量站']);

        $request = new Request();
        $request->attributes->set('server', $server);
        $resp = (new AgentController())->websites($request);

        $this->assertSame(200, $resp->getStatusCode());
        $data = $resp->getData(true);

        $byId = collect($data['websites'])->keyBy('id');
        $this->assertSame('incremental', $byId[$incSite->id]['backup_mode']);
        $this->assertSame($storage->id, $byId[$incSite->id]['incremental_storage_id']);
        $this->assertSame('full', $byId[$fullSite->id]['backup_mode']);
        $this->assertNull($byId[$fullSite->id]['incremental_storage_id']);

        // 增量目标存储不在 website_storages pivot 里，也必须出现在顶层 storages
        // （Agent 据此渲染 rclone.conf section 并落地 SSH 私钥）
        $this->assertContains($storage->id, array_column($data['storages'], 'id'));
    }

    /* ---------------- 4. RestoreController::backups 快照分组 ---------------- */

    public function test_restore_backups_snapshot_suite_type_label_and_restorable(): void
    {
        $website = $this->makeWebsite($this->agentServer(), Website::BACKUP_SOURCE_EXTERNAL, Website::BACKUP_MODE_FULL, null);
        // 锚定当天 02:00，不用 now()->subHours(2)：应用时区是 UTC，后者在
        // UTC 00:00-02:59 之间跑会跨到前一天，与下面默认 now() 的旧文件分成
        // 两个日期组，assertCount(1) 必炸（CI 每天在这个窗口都会踩）
        $at = now()->startOfDay()->addHours(2);

        // 快照日志：带 batch_code、无上传 → restorable=false；full_path 是远端快照目录
        $snap = BackupLog::create([
            'website_id' => $website->id,
            'server_id'  => $website->server_id,
            'filename'   => 'demo-snap-20261019-143000',
            'full_path'  => '/backup/demo/20261019-143000',
            'size'       => 4096,
            'status'     => BackupLog::STATUS_SUCCESS,
            'backup_at'  => $at,
            'batch_code' => '777',
        ]);
        // 同一次增量指令的 db 文件：带 batch_code、有上传 → restorable=true
        $dbLog = $this->makeLogWithUpload(
            $website,
            'demo-db-20261019-143000.sql.gz',
            '/backup/demo/demo-db-20261019-143000.sql.gz',
            'bc_1:/backup/demo/demo-db-20261019-143000.sql.gz',
            '777',
        );
        $dbLog->update(['backup_at' => $at]);
        // 无编码的旧文件：不得与编码快照组合并
        $this->makeLogWithUpload(
            $website,
            'old-web-20261018.tar.gz',
            '/backup/demo/old-web-20261018.tar.gz',
            'bc_1:/backup/demo/old-web-20261018.tar.gz',
        );

        $resp = (new RestoreController())->backups(new Request([
            'website_id' => $website->id,
            'range'      => 'month',
        ]));
        $this->assertSame(200, $resp->getStatusCode());
        $groups = $resp->getData(true)['data'];

        // 日期分组：全部文件都在「2 小时前」当天 → 单一日期组
        $this->assertCount(1, $groups);
        $this->assertSame($at->format('Y-m-d'), $groups[0]['date']);

        $suites = $groups[0]['suites'];
        // 编码快照套件（snap+db，2 文件）与无编码旧文件（1 文件）各自独立成组
        $this->assertCount(2, $suites);

        $snapSuite = null;
        foreach ($suites as $suite) {
            if (collect($suite['files'])->contains(fn ($f) => $f['backup_log_id'] === $snap->id)) {
                $snapSuite = $suite;
            }
        }
        $this->assertNotNull($snapSuite, '快照日志应独立成组');
        $this->assertCount(2, $snapSuite['files']);
        $this->assertSame(['777', '777'], array_column($snapSuite['files'], 'batch_code'));

        $snapFile = collect($snapSuite['files'])->firstWhere('backup_log_id', $snap->id);
        $this->assertSame('snapshot', $snapFile['type']);
        $this->assertSame('增量快照', $snapFile['label']);
        $this->assertFalse($snapFile['restorable']); // 无上传 → 不可恢复

        $dbFile = collect($snapSuite['files'])->firstWhere('backup_log_id', $dbLog->id);
        $this->assertSame('database', $dbFile['type']);
        $this->assertSame('数据库', $dbFile['label']);
        $this->assertTrue($dbFile['restorable']);
    }

    /* ---------------- 5. RestoreController::store 快照下发 ---------------- */

    public function test_restore_store_snapshot_uses_dir_path_type_and_temp_local_dir(): void
    {
        $website = $this->makeWebsite($this->agentServer(), Website::BACKUP_SOURCE_SELF, Website::BACKUP_MODE_FULL, null);
        $snap = $this->makeLogWithUpload(
            $website,
            'demo-snap-20261019-143000',
            '/backup/demo/20261019-143000',
            'bc_1:/backup/demo/20261019-143000',
        );
        // 同快照内的 db 文件日志：普通文件参数（无 path_type）
        $dbLog = $this->makeLogWithUpload(
            $website,
            'demo-db-20261019-143000.sql.gz',
            '/data/backup/demo/demo-db-20261019-143000.sql.gz',
            'bc_1:/backup/demo/demo-db-20261019-143000.sql.gz',
        );

        $resp = (new RestoreController())->store(new Request(['backup_log_ids' => [$snap->id, $dbLog->id]]));

        $this->assertSame(200, $resp->getStatusCode());
        $commands = AgentCommand::where('type', AgentCommand::TYPE_RESTORE)->get()->keyBy(fn ($c) => $c->params['backup_log_id']);
        $this->assertCount(2, $commands);

        $snapCmd = $commands[$snap->id];
        $this->assertSame('dir', $snapCmd->params['path_type']);
        $this->assertStringStartsWith(sys_get_temp_dir() . '/bc-restore-inc-', $snapCmd->params['local_dir']);
        $this->assertSame('bc_1:/backup/demo/20261019-143000', $snapCmd->params['remote_path']);
        $this->assertArrayNotHasKey('restore_mode', $snapCmd->params); // lite 不追加

        $dbCmd = $commands[$dbLog->id];
        $this->assertArrayNotHasKey('path_type', $dbCmd->params);
        $this->assertSame('/data/backup/demo', $dbCmd->params['local_dir']);
    }

    /* ---------------- 附：retention 清理 is_dir 派发（规格 §2.9） ---------------- */

    public function test_cleanup_retention_dispatches_is_dir_for_snapshot_and_file(): void
    {
        $server  = $this->agentServer();
        $storage = $this->sftpStorage(['name' => '清理存储']);
        $website = $this->makeWebsite($server, Website::BACKUP_SOURCE_SELF, Website::BACKUP_MODE_INCREMENTAL, $storage->id);
        $website->update(['retention_days' => 7]);
        $prefix = 'bc_' . $storage->id . ':';

        $makeLog = function (string $filename, string $fullPath, string $sourceKey, string $at, string $remotePath) use ($website, $storage) {
            $log = BackupLog::create([
                'website_id' => $website->id,
                'server_id'  => $website->server_id,
                'filename'   => $filename,
                'full_path'  => $fullPath,
                'source_key' => $sourceKey,
                'size'       => 1024,
                'status'     => BackupLog::STATUS_SUCCESS,
                'backup_at'  => $at,
            ]);
            BackupUpload::create([
                'backup_log_id' => $log->id,
                'storage_id'    => $storage->id,
                'status'        => BackupUpload::STATUS_UPLOADED,
                'remote_path'   => $remotePath,
            ]);
            return $log;
        };

        // 超期快照目录日志 + 同 source_key 的较新 keeper（护栏 4 保留 keeper，派发超期那条）
        $snapOld = $makeLog('demo-snap-20261001-120000', '/backup/demo/20261001-120000', BackupSource::SELF_SNAPSHOT, now()->subDays(40), $prefix . '/backup/demo/20261001-120000');
        $makeLog('demo-snap-20261019-120000', '/backup/demo/20261019-120000', BackupSource::SELF_SNAPSHOT, now()->subDay(), $prefix . '/backup/demo/20261019-120000');
        // 超期普通文件日志 +  keeper
        $fileOld = $makeLog('demo-old.tar.gz', '/data/backup/demo-old.tar.gz', 'ext:/data/backup', now()->subDays(40), $prefix . 'demo/old.tar.gz');
        $makeLog('demo-new.tar.gz', '/data/backup/demo-new.tar.gz', 'ext:/data/backup', now()->subDay(), $prefix . 'demo/new.tar.gz');

        $this->artisan('bc:cleanup-retention')->assertExitCode(0);

        $cmd = AgentCommand::where('type', AgentCommand::TYPE_CLEANUP_RETENTION)->firstOrFail();
        $this->assertSame($server->id, $cmd->server_id);
        $files = collect($cmd->params['files'])->keyBy('log_id');
        $this->assertCount(2, $files);
        // 快照目录日志 → is_dir=true（Agent 走 rclone purge）；普通文件 → false（deletefile）
        $this->assertTrue($files[$snapOld->id]['is_dir']);
        $this->assertSame($prefix . '/backup/demo/20261001-120000', $files[$snapOld->id]['remote_path']);
        $this->assertFalse($files[$fileOld->id]['is_dir']);

        // 先删云端后删 DB：指令未上报成功，行必须还在
        $this->assertDatabaseHas('backup_logs', ['id' => $snapOld->id]);
        $this->assertDatabaseHas('backup_logs', ['id' => $fileOld->id]);
    }

    /* ---------------- 6. BackupSource::derive ---------------- */

    public function test_backup_source_derive_snapshot_db_and_web(): void
    {
        // 快照日志（无扩展名）→ self:snapshot
        $this->assertSame(
            BackupSource::SELF_SNAPSHOT,
            BackupSource::derive('demo-snap-20261019-143000', '/backup/demo/20261019-143000', [])
        );
        $this->assertSame('self:snapshot', BackupSource::derive('demo-snap-20261019-143000', null, []));

        // db/web 既有判定不受影响
        $this->assertSame(
            BackupSource::SELF_DB,
            BackupSource::derive('demo-db-20261019-143000.sql.gz', '/tmp/bc-self-1-x/demo-db-20261019-143000.sql.gz', [])
        );
        $this->assertSame(
            BackupSource::SELF_WEB,
            BackupSource::derive('demo-web-20261019-143000.tar.gz', '/tmp/bc-self-1-x/demo-web-20261019-143000.tar.gz', [])
        );
    }

    /* ---------------- 附：recentSelfBackups 快照项（规格 §1.2） ---------------- */

    public function test_recent_self_backups_includes_snapshot_entry(): void
    {
        $website = $this->makeWebsite($this->agentServer(), Website::BACKUP_SOURCE_SELF, Website::BACKUP_MODE_INCREMENTAL, $this->sftpStorage()->id);
        $snap = BackupLog::create([
            'website_id' => $website->id,
            'server_id'  => $website->server_id,
            'filename'   => 'demo-snap-20261019-143000',
            'full_path'  => '/backup/demo/20261019-143000',
            'size'       => 4096,
            'status'     => BackupLog::STATUS_SUCCESS,
            'backup_at'  => now()->subHour(),
        ]);

        $recent = $website->recentSelfBackups();

        $snapEntry = collect($recent)->firstWhere('label', '增量快照');
        $this->assertNotNull($snapEntry, 'recentSelfBackups 应含增量快照项');
        $this->assertSame($snap->full_path, $snapEntry['path']);
        $this->assertSame($snap->id, $snapEntry['log']['id']);
    }
}
