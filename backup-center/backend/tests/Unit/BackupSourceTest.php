<?php

namespace Tests\Unit;

use App\Support\BackupSource;
use PHPUnit\Framework\TestCase;

class BackupSourceTest extends TestCase
{
    private function paths(): array
    {
        return [
            ['path' => '/data/backup/site', 'pattern' => '*.tar.gz', 'label' => '网站目录'],
            ['path' => '/data/backup/db', 'pattern' => '*.sql.gz', 'label' => '数据库目录'],
        ];
    }

    /** ① self 网站归档：文件名 -web-*.tar.gz */
    public function test_self_web_filename(): void
    {
        $this->assertSame(
            'self:web',
            BackupSource::derive('mysite-web-20260809.tar.gz', '/tmp/bc-self-12-abc/mysite-web-20260809.tar.gz', $this->paths())
        );
    }

    /**
     * self 备份的 full_path 是 Agent 的临时目录（bc-self-<id>-），不是配置的备份目录。
     * 这个 case 锁死「filename 匹配优先于 full_path 前缀匹配」的顺序。
     */
    public function test_self_web_wins_over_path_prefix_in_temp_dir(): void
    {
        $tmp = sys_get_temp_dir() . '/bc-self-12-abc/foo-web-123.tar.gz';

        $this->assertSame('self:web', BackupSource::derive('foo-web-123.tar.gz', $tmp, $this->paths()));
    }

    /** self 数据库 dump：.sql.gz 与未压缩的 .sql 都要命中 */
    public function test_self_db_filename(): void
    {
        $this->assertSame('self:db', BackupSource::derive('mysite-db-20260809.sql.gz', '/tmp/bc-self-12-abc/x', $this->paths()));
        $this->assertSame('self:db', BackupSource::derive('mysite-db-20260809.sql', null, $this->paths()));
    }

    /** ③ external：full_path 前缀命中 backup_paths[i].path → ext:<path> */
    public function test_external_matched_path(): void
    {
        $this->assertSame(
            'ext:/data/backup/site',
            BackupSource::derive('thirdparty_20260809.tar.gz', '/data/backup/site/thirdparty_20260809.tar.gz', $this->paths())
        );
        $this->assertSame(
            'ext:/data/backup/db',
            BackupSource::derive('dump_20260809.sql.gz', '/data/backup/db/dump_20260809.sql.gz', $this->paths())
        );
    }

    /** ④ external 但匹配不到任何配置目录 → ext:（仍属于 external 组） */
    public function test_external_unmatched_path(): void
    {
        $this->assertSame('ext:', BackupSource::derive('someone.tar.gz', '/var/backups/other/someone.tar.gz', $this->paths()));
        $this->assertSame('ext:', BackupSource::derive('no_full_path.tar.gz', null, $this->paths()));
        $this->assertSame('ext:', BackupSource::derive('empty_full_path.tar.gz', '', $this->paths()));
    }

    /** ⑤ 无任何 backup_paths 配置（老数据 / 纯 self 站）→ 落进兜底组 ext: */
    public function test_legacy_rows_without_paths_fall_back(): void
    {
        $this->assertSame('ext:', BackupSource::derive('legacy_20260101.tar.gz', '/data/backup/site/legacy_20260101.tar.gz', []));
        $this->assertSame('ext:', BackupSource::derive('legacy_no_path.txt', null, []));
    }

    /** backup_paths 项缺 path 键时不能炸，直接跳过 */
    public function test_malformed_backup_paths_are_skipped(): void
    {
        $this->assertSame('ext:', BackupSource::derive('x.tar.gz', '/data/backup/site/x.tar.gz', [['label' => '坏数据'], null]));
    }
}
