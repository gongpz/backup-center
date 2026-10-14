<?php

namespace Tests\Feature;

use App\Models\BackupLog;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 复现并锁死这个缺陷：网站配了多个 backup_paths 时，护栏 4 只按「网站」保留 1 条
 * 最新成功备份，于是某个目录（P2）的全部备份可能被删光 —— 该目录再无可恢复的备份。
 *
 * 改动前：本文件第一个测试会失败（P2 的最新一条被删）；改动后通过。
 */
class CleanupRetentionSourceKeyTest extends TestCase
{
    use RefreshDatabase;

    private const PATH_P1 = '/data/backup/site';
    private const PATH_P2 = '/data/backup/db';

    private function makeWebsite(int $retentionDays = 7): Website
    {
        $server = Server::create(['name' => '测试服务器']);

        return Website::create([
            'server_id'      => $server->id,
            'name'           => '双备份目录站点',
            'backup_paths'   => [
                ['path' => self::PATH_P1, 'pattern' => '*.tar.gz', 'label' => '网站目录'],
                ['path' => self::PATH_P2, 'pattern' => '*.sql.gz', 'label' => '数据库目录'],
            ],
            'retention_days' => $retentionDays,
            'status'         => Website::STATUS_ENABLED,
        ]);
    }

    private function makeLog(Website $website, string $filename, string $fullPath, string $at, ?string $sourceKey = null): BackupLog
    {
        $data = [
            'website_id' => $website->id,
            'server_id'  => $website->server_id,
            'filename'   => $filename,
            'full_path'  => $fullPath,
            'status'     => BackupLog::STATUS_SUCCESS,
            'backup_at'  => $at,
        ];
        if ($sourceKey !== null) {
            $data['source_key'] = $sourceKey;   // 传 null 时不写该列 → 走 DB 默认 ''
        }

        return BackupLog::create($data);
    }

    /**
     * 核心回归：P2 的全部备份都超期、P1 有近期成功时，P2 的最新一条成功备份必须留下。
     */
    public function test_keeps_latest_success_for_second_backup_path(): void
    {
        $website = $this->makeWebsite();

        $p1Recent = $this->makeLog(
            $website, 'site-recent.tar.gz', self::PATH_P1 . '/site-recent.tar.gz',
            now()->subDay(), 'ext:' . self::PATH_P1
        );
        $p2Latest = $this->makeLog(
            $website, 'db-30d.sql.gz', self::PATH_P2 . '/db-30d.sql.gz',
            now()->subDays(30), 'ext:' . self::PATH_P2
        );
        $p2Older = $this->makeLog(
            $website, 'db-31d.sql.gz', self::PATH_P2 . '/db-31d.sql.gz',
            now()->subDays(31), 'ext:' . self::PATH_P2
        );

        $this->artisan('bc:cleanup-retention')->assertExitCode(0);

        // 缺陷所在：改动前 P2 的两条都会被删（keeper 是 P1 那条近期成功的）
        $this->assertDatabaseHas('backup_logs', ['id' => $p2Latest->id]);
        $this->assertDatabaseMissing('backup_logs', ['id' => $p2Older->id]);

        // P1 未超期，本来就不该删
        $this->assertDatabaseHas('backup_logs', ['id' => $p1Recent->id]);
    }

    /**
     * 同一秒多条成功记录（并列）时，组内应稳定保留 id 最大的那条，避免「保留了一条
     * 但同名同秒的另一条被删、实际数据还在」的歧义。
     */
    public function test_same_second_tie_keeps_greatest_id(): void
    {
        $website = $this->makeWebsite();
        $at = now()->subDays(30);

        $first  = $this->makeLog($website, 'tie-a.sql.gz', self::PATH_P2 . '/tie-a.sql.gz', $at, 'ext:' . self::PATH_P2);
        $second = $this->makeLog($website, 'tie-b.sql.gz', self::PATH_P2 . '/tie-b.sql.gz', $at, 'ext:' . self::PATH_P2);

        $this->artisan('bc:cleanup-retention')->assertExitCode(0);

        $this->assertDatabaseHas('backup_logs', ['id' => $second->id]);
        $this->assertDatabaseMissing('backup_logs', ['id' => $first->id]);
    }

    /**
     * 老数据 / 无法解析的行落在 '' 组（列非空、默认 ''），该组同样至少保住 1 条。
     */
    public function test_legacy_empty_source_key_group_keeps_one(): void
    {
        $website = $this->makeWebsite();

        $legacyLatest = $this->makeLog($website, 'legacy-30d.tar.gz', '/somewhere/legacy-30d.tar.gz', now()->subDays(30));
        $legacyOlder  = $this->makeLog($website, 'legacy-31d.tar.gz', '/somewhere/legacy-31d.tar.gz', now()->subDays(31));

        // 不写 source_key 时由 DB 默认值兜底为 ''，而不是 NULL
        $this->assertSame('', (string) $legacyLatest->fresh()->source_key);

        $this->artisan('bc:cleanup-retention')->assertExitCode(0);

        $this->assertDatabaseHas('backup_logs', ['id' => $legacyLatest->id]);
        $this->assertDatabaseMissing('backup_logs', ['id' => $legacyOlder->id]);
    }
}
