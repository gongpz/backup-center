<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\RestoreController;
use App\Models\BackupLog;
use App\Models\BackupUpload;
use App\Models\Server;
use App\Models\Storage;
use App\Models\Website;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 恢复页套件合并回归测试（RestoreController::backups / buildSuites）。
 *
 * 策略演进：
 * 1. 旧版纯 30 分钟时间窗口 + Carbon 3 diffInSeconds 有符号值 bug → 窗口恒真，
 *    同网站全部文件并成一个大套件，「19日卡片混入 18/17 日记录」（4265f44 修 bug）；
 * 2. 现行策略「类型配对 + 5 分钟窗口兜底」：一次备份 = 1 个网站目录包 + 1 个数据库包，
 *    先按类型一对一配对，配不上的按窗口归并。窗口 300s 的依据（用户生产库实测）：
 *    同场次 web/db 间隔 ≤45s（宝塔两任务先后跑；自主备份两文件同秒），
 *    不同场次间隔 ≥600s（00:55 / 01:05 两次手动自主备份）。
 *
 * 数据形态取自用户生产库真实 12 条记录，日期改为相对生成保证测试随时间稳定。
 */
class RestoreSuiteWindowTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsite(): Website
    {
        $server = Server::create([
            'name'         => 'Agent 服务器',
            'connect_type' => Server::CONNECT_AGENT,
            'status'       => Server::STATUS_ONLINE,
        ]);

        return Website::create([
            'server_id'      => $server->id,
            'name'           => 'hiimask',
            'backup_source'  => Website::BACKUP_SOURCE_EXTERNAL,
            'restore_mode'   => Website::RESTORE_MODE_LITE,
            'web_root'       => '/www/wwwroot/hiimask',
            'db_type'        => Website::DB_TYPE_MYSQL,
            'db_host'        => '127.0.0.1',
            'db_port'        => 3306,
            'db_name'        => 'hiimask',
            'db_user'        => 'root',
            'db_password'    => 's3cret-pass',
            'status'         => Website::STATUS_ENABLED,
        ]);
    }

    private function makeRestorableLog(Website $website, string $filename, Carbon $at): BackupLog
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
            'full_path'  => '/www/backup/' . $filename,
            'size'       => 1024,
            'status'     => BackupLog::STATUS_SUCCESS,
            'backup_at'  => $at,
        ]);
        BackupUpload::create([
            'backup_log_id' => $log->id,
            'storage_id'    => $storage->id,
            'status'        => BackupUpload::STATUS_UPLOADED,
            'remote_path'   => '/remote/' . $filename,
        ]);

        return $log;
    }

    /**
     * 造出与生产库同构的 6 场备份（每场 web+db）：
     *   今天 01:05 / 00:55（self，两文件同秒；两场间隔 10 分钟 → 必须分开）
     *   昨天 04:41 / 03:05（external，db 比 web 晚 8s / 20s；两场间隔 96 分钟）
     *   2 天前 09:19（db 晚 11s）、4 天前 15:00（db 晚 45s，跨分钟边界）
     */
    private function seedProductionShape(Website $website): void
    {
        $batches = [
            [Carbon::today()->setTime(1, 5, 31), 0,  'site-web-010531.tar.gz',               'site-db-010531.sql.gz'],
            [Carbon::today()->setTime(0, 55, 31), 0,  'site-web-005531.tar.gz',               'site-db-005531.sql.gz'],
            [Carbon::yesterday()->setTime(4, 41, 38), 8,  'hi.imask.cc_20261018_044137.tar.gz', 'hiimask_2026-10-18_04-41-45_mysql_data_wbaLN.sql.zip'],
            [Carbon::yesterday()->setTime(3, 5, 17), 20, 'hi.imask.cc_20261018_030516.tar.gz', 'hiimask_2026-10-18_03-05-37_mysql_data_NWJvx.sql.zip'],
            [Carbon::now()->subDays(2)->setTime(9, 19, 38), 11, 'hi.imask.cc_20261017_091937.tar.gz', 'hiimask_2026-10-17_09-19-48_mysql_data_uyeyg.sql.zip'],
            [Carbon::now()->subDays(4)->setTime(15, 0, 50), 45, 'hi.imask.cc_20261015_150050.tar.gz', 'hiimask_2026-10-15_15-01-35_mysql_data_UvYtQ.sql.zip'],
        ];
        foreach ($batches as [$at, $gap, $web, $db]) {
            $this->makeRestorableLog($website, $web, $at);
            $this->makeRestorableLog($website, $db, $at->copy()->addSeconds($gap));
        }
    }

    private function fetchGroups(Website $website): array
    {
        $resp = (new RestoreController())->backups(new Request([
            'website_id' => $website->id,
            'range'      => 'month',
        ]));
        $this->assertSame(200, $resp->getStatusCode());

        return $resp->getData(true)['data'];
    }

    private function fetchSuites(Website $website): array
    {
        return collect($this->fetchGroups($website))->pluck('suites')->flatten(1)->all();
    }

    public function test_production_shape_yields_one_suite_per_batch(): void
    {
        $website = $this->makeWebsite();
        $this->seedProductionShape($website);

        $suites = $this->fetchSuites($website);

        // 6 场备份 = 6 个套件（旧 30 分钟窗口会把今天两场糊成 1 个 → 只有 5 个套件）
        $this->assertCount(6, $suites);

        foreach ($suites as $suite) {
            // 每个套件恰好 web+db 一对，且不跨天
            $this->assertCount(2, $suite['files'], 'suite_id=' . $suite['suite_id']);
            $dates = array_unique(array_map(
                fn ($f) => Carbon::parse($f['backup_at'])->format('Y-m-d'),
                $suite['files']
            ));
            $this->assertCount(1, $dates, '单个套件不得跨天；suite_id=' . $suite['suite_id']);
        }

        // match：自主备份两文件 backup_at 同秒 → exact；宝塔 external 间隔 8–45s → window
        $this->assertSame('exact', $suites[0]['match']);  // 今天 01:05
        $this->assertSame('exact', $suites[1]['match']);  // 今天 00:55
        $this->assertSame('window', $suites[2]['match']); // 昨天 04:41（db 晚 8s）
        $this->assertSame('window', $suites[5]['match']); // 4 天前 15:00（db 晚 45s）

        // 今天：两场 self 备份间隔 10 分钟 > 窗口 → 2 个套件（用户核心诉求）
        $todayGroup = collect($this->fetchGroups($website))->firstWhere('date', Carbon::today()->format('Y-m-d'));
        $this->assertCount(2, $todayGroup['suites']);
        $this->assertSame(2, $todayGroup['suites'][0]['total_count']);
        $this->assertSame(2, $todayGroup['suites'][1]['total_count']);

        // 套件倒序：最新场次（今天 01:05）在最前
        $this->assertSame('01:05:31', Carbon::parse($suites[0]['backup_at'])->format('H:i:s'));
    }

    public function test_type_pairing_separates_two_close_runs(): void
    {
        $website = $this->makeWebsite();
        // 两场备份只隔 4 分钟：旧纯 5 分钟窗口会把 4 个文件全糊成一组（用户担心的风险），
        // 类型配对按 web↔db 一对一，两场互不串味
        $this->makeRestorableLog($website, 'a-web.tar.gz', Carbon::now()->subMinutes(8));
        $this->makeRestorableLog($website, 'a-db.sql.gz', Carbon::now()->subMinutes(8)->addSeconds(10));
        $this->makeRestorableLog($website, 'b-web.tar.gz', Carbon::now()->subMinutes(4));
        $this->makeRestorableLog($website, 'b-db.sql.gz', Carbon::now()->subMinutes(4)->addSeconds(10));

        $suites = $this->fetchSuites($website);

        $this->assertCount(2, $suites);
        foreach ($suites as $suite) {
            $this->assertCount(2, $suite['files']);
            // 每组都是一 web 一 db，不串味
            $this->assertTrue(
                (str_contains($suite['files'][0]['filename'], 'web') && str_contains($suite['files'][1]['filename'], 'db'))
                || (str_contains($suite['files'][0]['filename'], 'db') && str_contains($suite['files'][1]['filename'], 'web'))
            );
        }
        // 较新场次（b）在前
        $this->assertSame('suite-' . BackupLog::where('filename', 'b-db.sql.gz')->value('id'), $suites[0]['suite_id']);
    }

    public function test_db_only_website_groups_leftovers_by_window(): void
    {
        $website = $this->makeWebsite();
        // 仅数据库的网站：无 web 可配对，db 走窗口兜底
        $this->makeRestorableLog($website, 'db-1.sql.gz', Carbon::now()->subMinutes(20));
        $this->makeRestorableLog($website, 'db-2.sql.gz', Carbon::now()->subMinutes(17)); // 距上一场 3 分钟 → 合并
        $this->makeRestorableLog($website, 'db-3.sql.gz', Carbon::now()->subMinutes(7));  // 距组内最早 13 分钟 → 独立

        $suites = $this->fetchSuites($website);

        // 套件按组内最新文件倒序：db-3（7 分钟前）最近 → 在最前；
        // db-1 + db-2 相隔 3 分钟 ≤ 窗口 → 合并为第二组
        $this->assertCount(2, $suites);
        $this->assertCount(1, $suites[0]['files']); // db-3
        $this->assertCount(2, $suites[1]['files']); // db-2 + db-1
        $this->assertSame('suite-' . BackupLog::where('filename', 'db-3.sql.gz')->value('id'), $suites[0]['suite_id']);
    }

    public function test_pairing_survives_minute_boundary(): void
    {
        $website = $this->makeWebsite();
        // 宝塔真实形态：web 15:00:50 + db 15:01:35，跨分钟边界 45s
        $this->makeRestorableLog($website, 'site-web-150050.tar.gz', Carbon::now()->subMinutes(30)->setTime(15, 0, 50));
        $this->makeRestorableLog($website, 'site-db-150135.sql.gz', Carbon::now()->subMinutes(30)->setTime(15, 1, 35));

        $suites = $this->fetchSuites($website);

        $this->assertCount(1, $suites);
        $this->assertCount(2, $suites[0]['files']);
    }

    public function test_single_file_suite_is_not_merged_with_far_older_file(): void
    {
        $website = $this->makeWebsite();
        // web 与 db 相距 6 分钟 > 5 分钟窗口 → 不配对，各自单文件成组；
        // old-web 距两者都远超窗口 → 第三个组
        $this->makeRestorableLog($website, 'a-web.tar.gz', Carbon::now()->subMinutes(10));
        $this->makeRestorableLog($website, 'a-db.sql.gz', Carbon::now()->subMinutes(4));
        $this->makeRestorableLog($website, 'old-web.tar.gz', Carbon::now()->subHours(3));

        $suites = $this->fetchSuites($website);

        $this->assertCount(3, $suites);
        $this->assertSame('suite-' . BackupLog::where('filename', 'a-db.sql.gz')->value('id'), $suites[0]['suite_id']);
    }

    public function test_day_groups_only_contain_that_days_files(): void
    {
        $website = $this->makeWebsite();
        $this->seedProductionShape($website);

        $groups = $this->fetchGroups($website);

        $this->assertSame([
            Carbon::today()->format('Y-m-d'),
            Carbon::yesterday()->format('Y-m-d'),
            Carbon::now()->subDays(2)->format('Y-m-d'),
            Carbon::now()->subDays(4)->format('Y-m-d'),
        ], array_column($groups, 'date'));

        foreach ($groups as $group) {
            foreach ($group['suites'] as $suite) {
                foreach ($suite['files'] as $f) {
                    $this->assertSame($group['date'], Carbon::parse($f['backup_at'])->format('Y-m-d'));
                }
            }
        }
    }
}
