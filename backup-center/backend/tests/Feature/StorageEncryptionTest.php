<?php

namespace Tests\Feature;

use App\Console\Commands\CleanupExpiredBackups;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\StorageController;
use App\Models\AgentCommand;
use App\Models\BackupLog;
use App\Models\BackupUpload;
use App\Models\Server;
use App\Models\Storage;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 存储加密（C1）+ 保留期清理搬迁 Agent（C4）的后端契约聚焦测试。
 *
 * 风格对齐 SelfBackupAndRestoreParamsTest：直接调 Controller / 直接跑 command，
 * 绕开 auth 中间件。CI 里没有 rclone，所以：
 *   - 凡"必须真跑 rclone"的行为（round-trip 探测、Center 侧删云）不在本文件覆盖；
 *   - buildRcloneConf 的断言只看 section 结构（obscure 失败时只缺 password 行，结构照旧）。
 */
class StorageEncryptionTest extends TestCase
{
    use RefreshDatabase;

    /** AgentCommand.params 里绝不允许出现的凭据类键（红线） */
    private const CREDENTIAL_KEYS = [
        'password', 'secret_access_key', 'access_key_id', 'private_key',
        'encryption', 'config', 'db_password',
    ];

    private const CENTER_PASSWORD = 'enc-pw-center-plain';

    /* ---------------- helpers ---------------- */

    private function agentServer(): Server
    {
        return Server::create([
            'name'         => 'Agent 服务器',
            'connect_type' => Server::CONNECT_AGENT,
            'status'       => Server::STATUS_ONLINE,
            'last_seen_at' => now(),
        ]);
    }

    private function sshServer(): Server
    {
        return Server::create([
            'name'         => 'SSH 服务器',
            'connect_type' => Server::CONNECT_SSH,
            'status'       => Server::STATUS_ONLINE,
        ]);
    }

    private function makeStorage(array $encryption = []): Storage
    {
        $config = ['host' => '1.2.3.4', 'user' => 'backup'];
        if ($encryption !== []) {
            $config['encryption'] = $encryption;
        }

        return Storage::create([
            'name'      => '测试存储',
            'type'      => Storage::TYPE_SFTP,
            'status'    => Storage::STATUS_ENABLED,
            'config'    => $config,
            'base_path' => '/backups',
        ]);
    }

    private function centerEncryption(): array
    {
        return [
            'enabled'                   => true,
            'key_mode'                  => 'center',
            'password'                  => self::CENTER_PASSWORD,
            'filename_encryption'       => 'standard',
            'directory_name_encryption' => true,
        ];
    }

    private function makeWebsite(Server $server, Storage $storage, int $retentionDays = 7): Website
    {
        $website = Website::create([
            'server_id'      => $server->id,
            'name'           => '演示站点',
            'web_root'       => '/var/www/demo',
            'retention_days' => $retentionDays,
            'status'         => Website::STATUS_ENABLED,
        ]);
        $website->storages()->attach($storage->id);

        return $website;
    }

    private function makeLogWithUpload(
        Website $website,
        Storage $storage,
        string $at,
        string $sourceKey = 'ext:/data/backup/site',
        ?string $remotePath = null,
    ): BackupLog {
        $log = BackupLog::create([
            'website_id' => $website->id,
            'server_id'  => $website->server_id,
            'filename'   => 'demo-web-' . md5($at . $sourceKey) . '.tar.gz',
            'full_path'  => '/data/backup/site/demo-web.tar.gz',
            'source_key' => $sourceKey,
            'status'     => BackupLog::STATUS_SUCCESS,
            'backup_at'  => $at,
        ]);

        if ($remotePath !== null) {
            BackupUpload::create([
                'backup_log_id' => $log->id,
                'storage_id'    => $storage->id,
                'status'        => BackupUpload::STATUS_UPLOADED,
                'remote_path'   => $remotePath,
            ]);
        }

        return $log;
    }

    /** @return array{0: string, 1: array<int,string>} [conf 内容, warnings] */
    private function renderConf(Storage $storage, string $remote): array
    {
        $method = new \ReflectionMethod(StorageController::class, 'buildRcloneConf');
        $method->setAccessible(true);

        $tempFiles = [];
        $warnings  = [];
        $conf = $method->invokeArgs(
            new StorageController(),
            [$remote, $storage, &$tempFiles, &$warnings],
        );

        return [$conf, $warnings];
    }

    /* ---------------- 1) T1 中心托管：密码随 config 下发 ---------------- */

    public function test_websites_dispatches_plain_password_for_center_mode(): void
    {
        $server  = $this->agentServer();
        $storage = $this->makeStorage($this->centerEncryption());
        $website = $this->makeWebsite($server, $storage);
        $this->assertNotNull($website->id);

        $request = new Request();
        $request->attributes->set('server', $server);

        $payload = (new AgentController())->websites($request)->getData(true);

        $outEncryption = $payload['storages'][0]['config']['encryption'];
        // T1：密码随 config 进 Agent 内存（与"存储密码本就明文下发"同一信任域）
        $this->assertSame(self::CENTER_PASSWORD, $outEncryption['password']);
        $this->assertTrue($outEncryption['enabled']);
        $this->assertSame('center', $outEncryption['key_mode']);
    }

    /* ---------------- 2) T2 Agent 本地密钥：中心不下发密码 ---------------- */

    public function test_websites_strips_password_for_agent_local_mode(): void
    {
        $server  = $this->agentServer();
        $storage = $this->makeStorage([
            'enabled'                   => true,
            'key_mode'                  => 'agent_local',
            'password'                  => '',           // 中心侧永远为空
            'filename_encryption'       => 'standard',
            'directory_name_encryption' => true,
        ]);
        $this->makeWebsite($server, $storage);

        $request = new Request();
        $request->attributes->set('server', $server);

        $payload = (new AgentController())->websites($request)->getData(true);

        $outEncryption = $payload['storages'][0]['config']['encryption'];
        $this->assertArrayNotHasKey('password', $outEncryption);
        $this->assertSame('agent_local', $outEncryption['key_mode']);
    }

    /* ---------------- 3) 探测 conf 里的 crypt 包裹层 ---------------- */

    public function test_probe_conf_renders_crypt_section_only_when_center_holds_key(): void
    {
        // 未加密：只有底层 remote section
        $plain = $this->makeStorage();
        [$conf] = $this->renderConf($plain, 'probe_' . $plain->id);
        $this->assertStringContainsString('[probe_' . $plain->id . ']', $conf);
        $this->assertStringNotContainsString('[probe_' . $plain->id . '_c]', $conf);
        $this->assertStringNotContainsString('type = crypt', $conf);

        // T1 加密：多渲染一个 crypt section 包住底层 remote
        $center = $this->makeStorage($this->centerEncryption());
        [$conf] = $this->renderConf($center, 'probe_' . $center->id);
        $this->assertStringContainsString('[probe_' . $center->id . '_c]', $conf);
        $this->assertStringContainsString('type = crypt', $conf);
        $this->assertStringContainsString('remote = probe_' . $center->id, $conf);
        $this->assertStringContainsString('filename_encryption = standard', $conf);
        $this->assertStringContainsString('directory_name_encryption = true', $conf);
        // 红线：obscure 拿不到值时宁缺密码行，也绝不写明文
        $this->assertStringNotContainsString(self::CENTER_PASSWORD, $conf);

        // T2 加密：中心无密钥，不渲染 crypt section（探测只覆盖底层 remote）
        $local = $this->makeStorage([
            'enabled'  => true,
            'key_mode' => 'agent_local',
        ]);
        [$conf] = $this->renderConf($local, 'probe_' . $local->id);
        $this->assertStringNotContainsString('[probe_' . $local->id . '_c]', $conf);
        $this->assertStringNotContainsString('type = crypt', $conf);
    }

    public function test_obscure_failure_is_reported_as_warning_not_plaintext(): void
    {
        // CI / 本地没有 rclone 时 shell_exec 返回空 ⇒ obscure 失败 ⇒ 只告警、不写明文
        if ($this->rcloneExists()) {
            $this->markTestSkipped('本用例依赖"没有 rclone"的环境：有 rclone 时 obscure 会成功，走不到降级分支');
        }

        $storage = $this->makeStorage($this->centerEncryption());
        [$conf, $warnings] = $this->renderConf($storage, 'probe_' . $storage->id);

        $this->assertNotSame([], $warnings, 'obscure 失败应产生告警供调用方跳过');
        $this->assertStringNotContainsString(self::CENTER_PASSWORD, $conf);
        $this->assertStringNotContainsString('password = ' . self::CENTER_PASSWORD, $conf);
    }

    /* ---------------- 4) 编辑回填 + 校验 ---------------- */

    public function test_update_keeps_old_password_when_placeholder_is_sent(): void
    {
        $controller = new StorageController();

        $created = $controller->store(new Request([
            'name'      => '加密存储',
            'type'      => Storage::TYPE_SFTP,
            'base_path' => '/backups',
            'config'    => [
                'host' => '1.2.3.4',
                'user' => 'backup',
                'encryption' => $this->centerEncryption(),
            ],
        ]));
        $this->assertSame(201, $created->getStatusCode());

        $storage = Storage::latest('id')->firstOrFail();
        $this->assertSame(self::CENTER_PASSWORD, $storage->config['encryption']['password']);

        // 编辑时回传占位符 = 不改
        $controller->update($storage, new Request([
            'name'   => '加密存储',
            'type'   => Storage::TYPE_SFTP,
            'config' => [
                'host' => '1.2.3.4',
                'user' => 'backup',
                'encryption' => array_merge($this->centerEncryption(), [
                    'password' => '••••••••',
                ]),
            ],
        ]));

        $decrypted = $storage->fresh()->config['encryption'];
        $this->assertSame(self::CENTER_PASSWORD, $decrypted['password'], '占位符应回填旧密码，而不是存成占位符本身');
        $this->assertTrue($decrypted['enabled']);
        $this->assertSame('center', $decrypted['key_mode']);
    }

    public function test_center_mode_requires_password(): void
    {
        $controller = new StorageController();

        $this->expectException(ValidationException::class);

        $controller->store(new Request([
            'name'   => '缺密码的加密存储',
            'type'   => Storage::TYPE_SFTP,
            'config' => [
                'host' => '1.2.3.4',
                'user' => 'backup',
                'encryption' => array_merge($this->centerEncryption(), ['password' => '']),
            ],
        ]));
    }

    public function test_directory_encryption_requires_filename_encryption(): void
    {
        $controller = new StorageController();

        $this->expectException(ValidationException::class);

        $controller->store(new Request([
            'name'   => '目录加密但未开文件名加密',
            'type'   => Storage::TYPE_SFTP,
            'config' => [
                'host' => '1.2.3.4',
                'user' => 'backup',
                'encryption' => [
                    'enabled'                   => true,
                    'key_mode'                  => 'center',
                    'password'                  => self::CENTER_PASSWORD,
                    'filename_encryption'       => 'off',
                    'directory_name_encryption' => true,
                ],
            ],
        ]));
    }

    public function test_agent_local_mode_never_stores_password_in_center(): void
    {
        $controller = new StorageController();

        $controller->store(new Request([
            'name'   => 'T2 存储',
            'type'   => Storage::TYPE_SFTP,
            'config' => [
                'host' => '1.2.3.4',
                'user' => 'backup',
                'encryption' => [
                    'enabled'                   => true,
                    'key_mode'                  => 'agent_local',
                    'password'                  => 'should-not-be-stored',
                    'filename_encryption'       => 'standard',
                    'directory_name_encryption' => true,
                ],
            ],
        ]));

        $encryption = Storage::latest('id')->firstOrFail()->config['encryption'];
        $this->assertSame('', $encryption['password'], 'agent_local 模式中心永不保存密码');
        $this->assertSame('agent_local', $encryption['key_mode']);
    }

    public function test_default_encryption_block_is_filled_when_absent(): void
    {
        $controller = new StorageController();

        $controller->store(new Request([
            'name'   => '未配置加密',
            'type'   => Storage::TYPE_SFTP,
            'config' => ['host' => '1.2.3.4', 'user' => 'backup'],
        ]));

        // 下游（conf 渲染 / 下发）依赖这个形状稳定
        $this->assertSame([
            'enabled'                   => false,
            'key_mode'                  => 'center',
            'password'                  => '',
            'filename_encryption'       => 'standard',
            'directory_name_encryption' => true,
        ], Storage::latest('id')->firstOrFail()->config['encryption']);
    }

    /* ---------------- 5) 清理：派发给 Agent ---------------- */

    public function test_expired_backups_are_dispatched_to_agent_without_credentials(): void
    {
        $server  = $this->agentServer();
        $storage = $this->makeStorage();
        $website = $this->makeWebsite($server, $storage);

        $older = $this->makeLogWithUpload(
            $website, $storage, now()->subDays(40), 'ext:/data/backup/site', 'bc_' . $storage->id . ':demo/a.tar.gz'
        );
        // 组内最新一条 success —— 护栏 4 保留，不应出现在派发清单里
        $this->makeLogWithUpload(
            $website, $storage, now()->subDays(39), 'ext:/data/backup/site', 'bc_' . $storage->id . ':demo/b.tar.gz'
        );

        $this->artisan('bc:cleanup-retention')->assertExitCode(0);

        $this->assertSame(1, AgentCommand::where('type', AgentCommand::TYPE_CLEANUP_RETENTION)->count());

        $cmd = AgentCommand::where('type', AgentCommand::TYPE_CLEANUP_RETENTION)->firstOrFail();
        $this->assertSame($server->id, $cmd->server_id);
        $this->assertSame(AgentCommand::STATUS_PENDING, $cmd->status);
        $this->assertSame([[
            'log_id'      => $older->id,
            'remote_path' => 'bc_' . $storage->id . ':demo/a.tar.gz',
        ]], $cmd->params['files']);

        // 红线：params 里只有 files[{log_id, remote_path}]，没有任何凭据
        $this->assertSame(['files'], array_keys($cmd->params));
        foreach (self::CREDENTIAL_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $cmd->params);
        }

        // 先删云端后删 DB：指令还没上报成功，行必须还在
        $this->assertDatabaseHas('backup_logs', ['id' => $older->id]);
    }

    public function test_second_run_does_not_duplicate_pending_command(): void
    {
        $server  = $this->agentServer();
        $storage = $this->makeStorage();
        $website = $this->makeWebsite($server, $storage);
        $this->makeLogWithUpload(
            $website, $storage, now()->subDays(40), 'ext:/data/backup/site', 'bc_' . $storage->id . ':demo/a.tar.gz'
        );
        $this->keepLatest($website, $storage);

        $this->artisan('bc:cleanup-retention')->assertExitCode(0);
        $this->artisan('bc:cleanup-retention')->assertExitCode(0);

        $this->assertSame(1, AgentCommand::where('type', AgentCommand::TYPE_CLEANUP_RETENTION)->count());
    }

    public function test_dry_run_does_not_dispatch_or_delete(): void
    {
        $server  = $this->agentServer();
        $storage = $this->makeStorage();
        $website = $this->makeWebsite($server, $storage);
        $log = $this->makeLogWithUpload(
            $website, $storage, now()->subDays(40), 'ext:/data/backup/site', 'bc_' . $storage->id . ':demo/a.tar.gz'
        );
        $this->keepLatest($website, $storage);

        $this->artisan('bc:cleanup-retention --dry-run')->assertExitCode(0);

        $this->assertSame(0, AgentCommand::where('type', AgentCommand::TYPE_CLEANUP_RETENTION)->count());
        $this->assertDatabaseHas('backup_logs', ['id' => $log->id]);
    }

    /* ---------------- 6) 清理：对账后才删 DB ---------------- */

    public function test_reconcile_deletes_rows_after_success(): void
    {
        [$server, $storage, $log] = $this->seedDispatchedCleanup();

        AgentCommand::where('type', AgentCommand::TYPE_CLEANUP_RETENTION)
            ->firstOrFail()
            ->update(['status' => AgentCommand::STATUS_SUCCESS, 'completed_at' => now(), 'output' => 'deleted 1']);

        $this->artisan('bc:cleanup-retention')->assertExitCode(0);

        $this->assertDatabaseMissing('backup_logs', ['id' => $log->id]);
        $this->assertNotNull(AgentCommand::firstOrFail()->fresh()->reconciled_at);
    }

    public function test_reconcile_keeps_rows_when_agent_failed(): void
    {
        [$server, $storage, $log] = $this->seedDispatchedCleanup();

        AgentCommand::where('type', AgentCommand::TYPE_CLEANUP_RETENTION)
            ->firstOrFail()
            ->update(['status' => AgentCommand::STATUS_FAILED, 'completed_at' => now(), 'error' => 'rclone timeout']);

        $this->artisan('bc:cleanup-retention')->assertExitCode(0);

        // 失败 → 行保留（下轮重派，Agent 侧 not-found 视为已删保证幂等）
        $this->assertDatabaseHas('backup_logs', ['id' => $log->id]);
        $this->assertNotNull(AgentCommand::firstOrFail()->fresh()->reconciled_at);
    }

    /* ---------------- 7) 护栏回归：source_key 保最新 1 条 ---------------- */

    public function test_source_key_guard_still_keeps_latest_success_through_pipeline(): void
    {
        $server  = $this->agentServer();
        $storage = $this->makeStorage();
        $website = $this->makeWebsite($server, $storage);

        $oldest = $this->makeLogWithUpload(
            $website, $storage, now()->subDays(40), 'ext:/data/backup/p1', 'bc_' . $storage->id . ':demo/old1.tar.gz'
        );
        $older = $this->makeLogWithUpload(
            $website, $storage, now()->subDays(39), 'ext:/data/backup/p1', 'bc_' . $storage->id . ':demo/old2.tar.gz'
        );
        // 组内最新一条 success —— 必须一直留着
        $latest = $this->makeLogWithUpload(
            $website, $storage, now()->subDays(38), 'ext:/data/backup/p1', 'bc_' . $storage->id . ':demo/new.tar.gz'
        );

        $this->artisan('bc:cleanup-retention')->assertExitCode(0);

        $dispatched = AgentCommand::where('type', AgentCommand::TYPE_CLEANUP_RETENTION)->get();
        $this->assertSame(1, $dispatched->count());
        $this->assertSame(
            [$oldest->id, $older->id],
            collect($dispatched->first()->params['files'])->pluck('log_id')->all(),
        );

        foreach ($dispatched as $cmd) {
            $cmd->update(['status' => AgentCommand::STATUS_SUCCESS, 'completed_at' => now(), 'output' => 'ok']);
        }
        $this->artisan('bc:cleanup-retention')->assertExitCode(0);

        $this->assertDatabaseMissing('backup_logs', ['id' => $oldest->id]);
        $this->assertDatabaseMissing('backup_logs', ['id' => $older->id]);
        $this->assertDatabaseHas('backup_logs', ['id' => $latest->id]);
    }

    public function test_agent_local_encrypted_backup_without_agent_goes_to_manual_list(): void
    {
        $server  = $this->sshServer();
        $storage = $this->makeStorage([
            'enabled'  => true,
            'key_mode' => 'agent_local',
        ]);
        $website = $this->makeWebsite($server, $storage);
        $log = $this->makeLogWithUpload(
            $website, $storage, now()->subDays(40), 'ext:/data/backup/site', 'bc_' . $storage->id . '_c:demo/e.tar.gz'
        );

        $this->artisan('bc:cleanup-retention')->assertExitCode(0);

        // Center 拿不到密钥 ⇒ 不删云端、也不删行，只能进人工清单
        $this->assertSame(0, AgentCommand::where('type', AgentCommand::TYPE_CLEANUP_RETENTION)->count());
        $this->assertDatabaseHas('backup_logs', ['id' => $log->id]);
    }

    /* ---------------- helpers ---------------- */

    /** @return array{0: Server, 1: Storage, 2: BackupLog} */
    private function seedDispatchedCleanup(): array
    {
        $server  = $this->agentServer();
        $storage = $this->makeStorage();
        $website = $this->makeWebsite($server, $storage);
        $log = $this->makeLogWithUpload(
            $website, $storage, now()->subDays(40), 'ext:/data/backup/site', 'bc_' . $storage->id . ':demo/a.tar.gz'
        );
        $this->keepLatest($website, $storage);

        $this->artisan('bc:cleanup-retention')->assertExitCode(0);
        $this->assertSame(1, AgentCommand::where('type', AgentCommand::TYPE_CLEANUP_RETENTION)->count());

        return [$server, $storage, $log];
    }

    /**
     * 护栏 4 会保留每个 source_key 组里最新的 1 条 success —— 单条日志的场景必须先造一个
     * 未超期的"keeper"，否则那条唯一的日志本身就被护栏保住，根本不会进入派发清单。
     */
    private function keepLatest(Website $website, Storage $storage): BackupLog
    {
        return $this->makeLogWithUpload(
            $website, $storage, now()->subDay(), 'ext:/data/backup/site', 'bc_' . $storage->id . ':demo/keep.tar.gz'
        );
    }

    private function rcloneExists(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;   // CI（ubuntu-latest）与 Linux 主机才有意义
        }
        return trim((string) shell_exec('command -v rclone 2>/dev/null')) !== '';
    }
}
