<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\StorageFileController;
use App\Models\Server;
use App\Models\Storage;
use App\Models\Website;
use App\Services\RcloneConfBuilder;
use App\Support\StorageBrowsePath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * VPS 存储文件浏览后端的契约测试。
 *
 * 风格对齐 StorageEncryptionTest：直接调 Controller / 纯函数直接调。
 * CI（Linux）通常预装 rclone，本地 Windows 没有 —— 凡"必须真实越过 available()
 * 预检才能走到"的分支（422 校验、agent_local 拒绝）用 rcloneExists() 守卫 skip；
 * 其余用例环境无关，任何机器都真实跑到。
 */
class StorageBrowseTest extends TestCase
{
    use RefreshDatabase;

    private const CENTER_PASSWORD = 'enc-pw-center-plain';

    /* ---------------- StorageBrowsePath::normalize ---------------- */

    public function test_normalize_rejects_parent_segments_and_collapses_slashes(): void
    {
        // ".." 段一律拒绝（上级目录逃逸）
        $this->assertNull(StorageBrowsePath::normalize('..', false));
        $this->assertNull(StorageBrowsePath::normalize('a/../b', false));
        $this->assertNull(StorageBrowsePath::normalize('/backups/../etc', true));
        // "//" 折叠；去尾斜杠
        $this->assertSame('a/b', StorageBrowsePath::normalize('a//b', false));
        $this->assertSame('/backup', StorageBrowsePath::normalize('/backup/', false));
        // S3 去前导 "/"（key 相对语义），其余保留（绝对路径语义）
        $this->assertSame('bc-backups', StorageBrowsePath::normalize('/bc-backups', true));
        // 空 / null / 纯斜杠 → 远端根 ''
        $this->assertSame('', StorageBrowsePath::normalize('', false));
        $this->assertSame('', StorageBrowsePath::normalize(null, false));
        $this->assertSame('', StorageBrowsePath::normalize('///', false));
        $this->assertSame('', StorageBrowsePath::normalize('///', true));
    }

    public function test_sanitize_segment_mirrors_go_uploader(): void
    {
        $this->assertSame('官网_主页', StorageBrowsePath::sanitizeSegment('官网/主页'));
        // 九个危险字符 / \ : * ? " < > | 全部替换为 _
        $this->assertSame('a_b_c_d_e_f_g_h_i', StorageBrowsePath::sanitizeSegment('a:b*c?d"e<f>g|h\\i'));
        // trim 后为空串 → default（Go 版 uploader.go:299-310 同规则）
        $this->assertSame('default', StorageBrowsePath::sanitizeSegment('   '));
        $this->assertSame('default', StorageBrowsePath::sanitizeSegment(''));
        // 中文原样保留
        $this->assertSame('中文站点', StorageBrowsePath::sanitizeSegment('中文站点'));
    }

    public function test_resolve_initial_prefers_path_then_website_then_base_path(): void
    {
        $storage = $this->makeStorage(Storage::TYPE_SFTP, '/backup');
        $website = $this->makeWebsite('官网主页', $storage->id);

        // 1) 显式 path 优先（顺带去尾斜杠）
        $this->assertSame(
            '/backup/x',
            StorageBrowsePath::resolveInitial($storage, '/backup/x/', null)
        );

        // 2) website 匹配 → 快照根 <base_path>/<sanitize(网站名)>
        $this->assertSame(
            '/backup/官网主页',
            StorageBrowsePath::resolveInitial($storage, null, $website->id)
        );

        // 3) website 不匹配 / 未传 → 回落 base_path
        $other = $this->makeStorage(Storage::TYPE_SFTP, '/elsewhere');
        $mismatchSite = $this->makeWebsite('别的站的站', $other->id);
        $this->assertSame(
            '/backup',
            StorageBrowsePath::resolveInitial($storage, null, $mismatchSite->id)
        );
        $this->assertSame(
            '/backup',
            StorageBrowsePath::resolveInitial($storage, null, null)
        );

        // 4) base_path 为空、无 website → 远端根 ''
        $emptyBase = $this->makeStorage(Storage::TYPE_SFTP, '');
        $this->assertSame('', StorageBrowsePath::resolveInitial($emptyBase, null, null));
        // base_path 为空 + website 匹配 → 仅站点目录名
        $websiteOnEmptyBase = $this->makeWebsite('官网主页', $emptyBase->id);
        $this->assertSame(
            '官网主页',
            StorageBrowsePath::resolveInitial($emptyBase, null, $websiteOnEmptyBase->id)
        );

        // 5) S3：website 路径同样剥掉前导斜杠（key 相对语义）
        $s3 = $this->makeStorage(Storage::TYPE_S3, 'bc-backups');
        $s3Site = $this->makeWebsite('营销落地页', $s3->id);
        $this->assertSame(
            'bc-backups/营销落地页',
            StorageBrowsePath::resolveInitial($s3, null, $s3Site->id)
        );
        $this->assertSame(
            'bc-backups',
            StorageBrowsePath::resolveInitial($s3, null, null)
        );
    }

    public function test_target_builds_remote_per_storage_type(): void
    {
        // SFTP 未加密：bc_<id>:<path>，path 保留前导 /
        $sftp = $this->storageModel(5, Storage::TYPE_SFTP, '/backup');
        $this->assertSame('bc_5:/backup', StorageBrowsePath::target($sftp, '/backup'));

        // SFTP + center 加密：走 crypt 包裹层
        $sftpEnc = $this->storageModel(5, Storage::TYPE_SFTP, '/backup', ['encryption' => $this->centerEncryption()]);
        $this->assertSame('bc_5_c:/backup', StorageBrowsePath::target($sftpEnc, '/backup'));

        // S3 未加密：bucket 作为 path 第一段
        $s3 = $this->storageModel(2, Storage::TYPE_S3, 'bc-backups', ['bucket' => 'bc-backups']);
        $this->assertSame('bc_2:bc-backups/官网主页', StorageBrowsePath::target($s3, '官网主页'));
        // S3 空 path → 仅 bucket
        $this->assertSame('bc_2:bc-backups', StorageBrowsePath::target($s3, ''));

        // S3 + center 加密：bucket 已在 crypt section 的 remote 里，path 不能再带 bucket
        $s3Enc = $this->storageModel(2, Storage::TYPE_S3, 'bc-backups', [
            'bucket'     => 'bc-backups',
            'encryption' => $this->centerEncryption(),
        ]);
        $this->assertSame('bc_2_c:官网主页', StorageBrowsePath::target($s3Enc, '官网主页'));
    }

    public function test_parent_returns_null_at_root(): void
    {
        $this->assertNull(StorageBrowsePath::parent(null));
        $this->assertNull(StorageBrowsePath::parent(''));
        // 单段（含前导斜杠的 '/backup'）即根
        $this->assertNull(StorageBrowsePath::parent('/backup'));
        $this->assertNull(StorageBrowsePath::parent('bc-backups'));
        // 去掉最后一段
        $this->assertSame('/backup', StorageBrowsePath::parent('/backup/官网主页'));
        $this->assertSame('bc-backups', StorageBrowsePath::parent('bc-backups/官网主页'));
        $this->assertSame('官网主页', StorageBrowsePath::parent('官网主页/20261018-020000'));
    }

    public function test_parse_entries_orders_dirs_first_and_normalizes_fields(): void
    {
        $raw = [
            ['Path' => 'db.sql.gz', 'Name' => 'db.sql.gz', 'Size' => 47382738, 'ModTime' => '2026-10-18T02:03:11+08:00', 'IsDir' => false],
            ['Path' => 'wp-content', 'Name' => 'wp-content', 'Size' => -1, 'ModTime' => '2026-10-18T02:00:00+08:00', 'IsDir' => true],
            ['Path' => '20261018-020000', 'Name' => '20261018-020000', 'Size' => -1, 'ModTime' => '2026-10-18T02:00:05+08:00', 'IsDir' => true],
            ['Path' => 'index.php', 'Name' => 'index.php', 'Size' => 12, 'ModTime' => '2026-10-18T02:00:09+08:00', 'IsDir' => false],
            // Path 空串 → 用 Name 兜底
            ['Path' => '', 'Name' => '孤儿条目', 'Size' => 3, 'ModTime' => '2026-10-18T02:00:12+08:00', 'IsDir' => false],
        ];

        $r = StorageBrowsePath::parseEntries($raw);

        // 目录优先，同组按名称排序
        $this->assertSame(
            ['20261018-020000', 'wp-content', 'db.sql.gz', 'index.php', '孤儿条目'],
            array_column($r['entries'], 'name')
        );
        $this->assertSame(2, $r['dir_count']);
        $this->assertSame(3, $r['file_count']);
        $this->assertFalse($r['truncated']);

        // 目录 size 忽略（lsjson 常给 -1）；ModTime 原串透传
        $dirEntry = $r['entries'][0];
        $this->assertTrue($dirEntry['is_dir']);
        $this->assertNull($dirEntry['size']);
        $this->assertSame('2026-10-18T02:00:05+08:00', $dirEntry['modified']);
        $this->assertSame(47382738, $r['entries'][2]['size']);
        // Path 空串用 Name 兜底
        $this->assertSame('孤儿条目', $r['entries'][4]['path']);
    }

    public function test_parse_entries_truncates_at_limit(): void
    {
        $raw = [];
        for ($i = 0; $i < 501; $i++) {
            $raw[] = ['Path' => 'f' . $i, 'Name' => 'f' . $i, 'Size' => 1, 'IsDir' => false];
        }

        $r = StorageBrowsePath::parseEntries($raw, '', 500);

        $this->assertTrue($r['truncated']);
        $this->assertCount(500, $r['entries']);
        // 计数记全量，不随截断丢失
        $this->assertSame(501, $r['file_count']);
    }

    public function test_parse_entries_prefixes_current_dir_for_navigation(): void
    {
        // rclone lsjson 官方文档：不加 --recursive 时 "the Path will always be the
        // same as Name" —— 后端必须把当前目录拼回，否则前端下钻只能拿到裸名字，
        // 重新请求会定位到错误层级。mock 演示树同口径（完整路径）。
        $raw = [
            ['Path' => '20261018-020000', 'Name' => '20261018-020000', 'IsDir' => true, 'Size' => -1],
            ['Path' => 'db.sql.gz', 'Name' => 'db.sql.gz', 'IsDir' => false, 'Size' => 100],
        ];

        // SFTP：base 保留前导斜杠
        $r = StorageBrowsePath::parseEntries($raw, '/backup/官网主页');
        $this->assertSame('/backup/官网主页/20261018-020000', $r['entries'][0]['path']);
        $this->assertSame('/backup/官网主页/db.sql.gz', $r['entries'][1]['path']);

        // S3：base 无前导斜杠
        $r2 = StorageBrowsePath::parseEntries($raw, 'bc-backups');
        $this->assertSame('bc-backups/20261018-020000', $r2['entries'][0]['path']);

        // base 为空（远端根）→ 原样返回相对段
        $r3 = StorageBrowsePath::parseEntries($raw, '');
        $this->assertSame('20261018-020000', $r3['entries'][0]['path']);

        // join 的边界：base 带尾斜杠 / 相对段带前导斜杠都不重复拼
        $this->assertSame('/backup/x', StorageBrowsePath::join('/backup/', '/x'));
        $this->assertSame('x', StorageBrowsePath::join('', 'x'));
    }

    /* ---------------- RcloneConfBuilder ---------------- */

    public function test_conf_builder_uses_default_remote_name_and_collects_private_key(): void
    {
        $storage = $this->makeStorage(Storage::TYPE_SFTP, '/backup', [
            'host' => '1.2.3.4', 'user' => 'backup',
        ]);

        // 缺省 remoteName = bc_<id>；无凭证无私钥 → 无 warnings / 无临时文件
        $r = RcloneConfBuilder::build($storage);
        $this->assertStringContainsString('[bc_' . $storage->id . ']', $r['conf']);
        $this->assertStringContainsString('type = sftp', $r['conf']);
        $this->assertSame([], $r['warnings']);
        $this->assertSame([], $r['tempFiles']);

        // 自定义 remoteName（StorageController 探测路径传 probe_<id>）
        $probe = RcloneConfBuilder::build($storage, 'probe_' . $storage->id);
        $this->assertStringContainsString('[probe_' . $storage->id . ']', $probe['conf']);

        // SFTP 私钥 → 落 0600 临时文件并在 conf 里引用（caller 负责清理）
        $withKey = $this->makeStorage(Storage::TYPE_SFTP, '/backup', [
            'host' => '1.2.3.4', 'user' => 'backup',
            'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----",
        ]);
        $r2 = RcloneConfBuilder::build($withKey);
        $this->assertCount(1, $r2['tempFiles']);
        $this->assertStringContainsString('key_file = ' . $r2['tempFiles'][0], $r2['conf']);
        $this->assertStringContainsString('-----BEGIN OPENSSH PRIVATE KEY-----', (string) file_get_contents($r2['tempFiles'][0]));
        foreach ($r2['tempFiles'] as $f) {
            @unlink($f);
        }
    }

    public function test_conf_builder_s3_crypt_remote_includes_bucket(): void
    {
        // 与 StorageEncryptionTest::test_probe_conf_s3_crypt_remote_includes_bucket 同一契约：
        // S3 的 crypt remote 必须拼 bucket，否则 bucket 名被一起加密 → object not found。
        // 本用例不依赖 rclone：即使 obscure 失败（无 rclone 环境），remote 行仍然渲染，
        // 缺失的只有 password 行。
        $storage = $this->makeStorage(Storage::TYPE_S3, '/backups', [
            'endpoint'          => 's3.cstcloud.cn',
            'region'            => 'us-west-000',
            'bucket'            => 'my-bucket',
            'access_key_id'     => 'AKIATEST',
            'secret_access_key' => 'secret-test',
        ], $this->centerEncryption());

        $r = RcloneConfBuilder::build($storage, 'probe_' . $storage->id);

        $this->assertStringContainsString('[probe_' . $storage->id . '_c]', $r['conf']);
        $this->assertStringContainsString('type = crypt', $r['conf']);
        $this->assertStringContainsString('remote = probe_' . $storage->id . ':my-bucket', $r['conf']);
        $this->assertStringContainsString('type = s3', $r['conf']);
        // obscure 拿不到值时宁缺 password 行，也绝不写明文
        $this->assertStringNotContainsString(self::CENTER_PASSWORD, $r['conf']);
        foreach ($r['tempFiles'] as $f) {
            @unlink($f);
        }
    }

    public function test_conf_builder_never_writes_plaintext_password(): void
    {
        // 锁死「禁止明文降级」契约：无论 obscure 成功与否，conf 里绝不出现明文密码。
        $storage = $this->makeStorage(Storage::TYPE_SFTP, '/backup', [
            'host' => '1.2.3.4', 'user' => 'backup', 'password' => 'sftp-plain-pw',
        ], $this->centerEncryption());

        $r = RcloneConfBuilder::build($storage, 'probe_' . $storage->id);

        // 环境无关的红线断言
        $this->assertStringNotContainsString('sftp-plain-pw', $r['conf']);
        $this->assertStringNotContainsString(self::CENTER_PASSWORD, $r['conf']);

        if ($this->rcloneExists()) {
            // 有 rclone：obscure 成功 → 无 warnings，conf 里是 obscure 后的值
            $this->assertSame([], $r['warnings']);
            $this->assertStringContainsString('pass = ', $r['conf']);
            $this->assertStringContainsString('password = ', $r['conf']);
        } else {
            // 无 rclone：obscure 失败 → warnings 非空，password/pass 行整行缺失
            $this->assertNotSame([], $r['warnings']);
            $this->assertStringNotContainsString('pass = ', $r['conf']);
            $this->assertStringNotContainsString('password = ', $r['conf']);
        }
        foreach ($r['tempFiles'] as $f) {
            @unlink($f);
        }
    }

    public function test_conf_builder_available_returns_bool(): void
    {
        $this->assertIsBool(RcloneConfBuilder::available());
    }

    /* ---------------- StorageFileController ---------------- */

    public function test_browse_reports_missing_rclone_before_anything_else(): void
    {
        // 流程第 1 步是 available() 预检：本机无 rclone 时任何请求都先拿到这条提示
        if ($this->rcloneExists()) {
            $this->markTestSkipped('本用例依赖"没有 rclone"的环境（available() 预检分支）');
        }

        $storage = $this->makeStorage(Storage::TYPE_SFTP, '/backup');
        $response = (new StorageFileController())->index(new Request(), $storage);

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertFalse($data['ok']);
        $this->assertStringContainsString('未检测到 rclone', $data['message']);
    }

    public function test_browse_rejects_parent_dir_path(): void
    {
        if (! $this->rcloneExists()) {
            $this->markTestSkipped('本机无 rclone：流程第 1 步 available() 预检先返回「未检测到 rclone」，走不到 422 校验（CI/Linux 预装 rclone，真实覆盖）');
        }

        $storage = $this->makeStorage(Storage::TYPE_SFTP, '/backup');
        $response = (new StorageFileController())->index(new Request(['path' => '../../etc']), $storage);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('路径不合法（不允许 .. 上级目录）', $response->getData(true)['message']);
    }

    public function test_browse_rejects_website_pointing_to_other_storage(): void
    {
        if (! $this->rcloneExists()) {
            $this->markTestSkipped('本机无 rclone：流程第 1 步 available() 预检先返回，走不到 422 校验（CI/Linux 预装 rclone，真实覆盖）');
        }

        $storage = $this->makeStorage(Storage::TYPE_SFTP, '/backup');
        $other   = $this->makeStorage(Storage::TYPE_SFTP, '/elsewhere');
        $website = $this->makeWebsite('站点A', $other->id);

        $response = (new StorageFileController())->index(new Request(['website_id' => $website->id]), $storage);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('该网站的增量备份目标不是当前存储', $response->getData(true)['message']);
    }

    public function test_browse_refuses_agent_local_encrypted_storage(): void
    {
        if (! $this->rcloneExists()) {
            $this->markTestSkipped('本机无 rclone：流程第 1 步 available() 预检先返回，走不到 agent_local 分支（CI/Linux 预装 rclone，真实覆盖）');
        }

        $storage = $this->makeStorage(Storage::TYPE_SFTP, '/backup', [], [
            'enabled'                   => true,
            'key_mode'                  => 'agent_local',
            'password'                  => '',
            'filename_encryption'       => 'standard',
            'directory_name_encryption' => true,
        ]);

        $response = (new StorageFileController())->index(new Request(), $storage);

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertFalse($data['ok']);
        $this->assertSame('该存储启用了「Agent 本地密钥」加密，中心无法解密文件名，暂不支持浏览', $data['message']);
    }

    /* ---------------- helpers ---------------- */

    private function makeStorage(string $type, string $basePath, array $config = [], array $encryption = []): Storage
    {
        if ($encryption !== []) {
            $config['encryption'] = $encryption;
        }

        return Storage::create([
            'name'      => '测试存储',
            'type'      => $type,
            'status'    => Storage::STATUS_ENABLED,
            'config'    => $config,
            'base_path' => $basePath,
        ]);
    }

    private function makeWebsite(string $name, int $storageId): Website
    {
        $server = Server::create([
            'name'         => 'Agent 服务器',
            'connect_type' => Server::CONNECT_AGENT,
            'status'       => Server::STATUS_ONLINE,
        ]);

        return Website::create([
            'server_id'               => $server->id,
            'name'                    => $name,
            'web_root'                => '/var/www/demo',
            'retention_days'          => 7,
            'status'                  => Website::STATUS_ENABLED,
            'incremental_storage_id'  => $storageId,
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

    /** 不落库的 Storage（仅 target() 等纯读字段的场景需要，id/type/base_path/config 可控） */
    private function storageModel(int $id, string $type, string $basePath, array $config = []): Storage
    {
        $s = new Storage();
        $s->id = $id;
        $s->type = $type;
        $s->base_path = $basePath;
        $s->config = $config;
        return $s;
    }

    private function rcloneExists(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;   // PHP 在 Windows 经 cmd 执行，command -v 不可用（与 StorageEncryptionTest 同口径）
        }
        return trim((string) shell_exec('command -v rclone 2>/dev/null')) !== '';
    }
}
