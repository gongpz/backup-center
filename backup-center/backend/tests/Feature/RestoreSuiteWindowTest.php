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
 * 恢复页套件合并窗口回归测试（RestoreController::backups）。
 *
 * 历史 bug：合并条件用了 Carbon 3 的 diffInSeconds() 但不传 $absolute=true。
 * Carbon 3 的 diffInSeconds() 默认返回**有符号值**（参数更早时为负），负数 <= 1800 恒真，
 * 导致同网站所有文件被无上限合并成一个大套件；旧版日期分组按套件头（最新文件）分日期，
 * 于是「10月19日」卡片里混入 18/17/15 日的全部记录（用户生产实测截图）。
 *
 * 数据形态取自用户生产库真实 12 条记录（docker exec bc-app tinker 输出），
 * 日期改为相对生成以保证测试随时间稳定：
 *   今天 01:05 ×2、今天 00:55 ×2（self 备份，10 分钟间隔 → 30 分钟窗口内正确合并）
 *   昨天 04:41 ×2、昨天 03:05 ×2（external 两场，间隔 96 分钟 → 不得合并）
 *   2 天前 09:19 ×2、4 天前 15:00 ×2（external，跨天 → 绝不得合并）
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

    /** 造出与生产库同构的 10 条记录（5 场备份 × web+db） */
    private function seedProductionShape(Website $website): void
    {
        $batches = [
            [Carbon::today()->setTime(1, 5, 31), 'site-web-010531.tar.gz', 'site-db-010531.sql.gz'],
            [Carbon::today()->setTime(0, 55, 31), 'site-web-005531.tar.gz', 'site-db-005531.sql.gz'],
            [Carbon::yesterday()->setTime(4, 41, 46), 'site-web-20261018_044137.tar.gz', 'site-db-20261018_044146.sql.zip'],
            [Carbon::yesterday()->setTime(3, 5, 37), 'site-web-20261018_030516.tar.gz', 'site-db-20261018_030537.sql.zip'],
            [Carbon::now()->subDays(2)->setTime(9, 19, 49), 'site-web-20261017_091937.tar.gz', 'site-db-20261017_091949.sql.zip'],
            [Carbon::now()->subDays(4)->setTime(15, 1, 35), 'site-web-20261015_150050.tar.gz', 'site-db-20261015_150135.sql.zip'],
        ];
        foreach ($batches as [$at, $web, $db]) {
            $this->makeRestorableLog($website, $web, $at);
            $this->makeRestorableLog($website, $db, $at->copy()->subSecond());
        }
    }

    public function test_window_merges_same_batch_but_never_crosses_days(): void
    {
        $website = $this->makeWebsite();
        $this->seedProductionShape($website);

        $resp = (new RestoreController())->backups(new Request([
            'website_id' => $website->id,
            'range'      => 'month',
        ]));
        $this->assertSame(200, $resp->getStatusCode());
        $groups = $resp->getData(true)['data'];

        // 全部套件（跨日期组汇总）
        $suites = collect($groups)->pluck('suites')->flatten(1)->all();

        // 核心回归：合并窗口不得把跨天文件串成一个套件。
        // bug 存在时这里只有 1 个套件且包含全部 12 个文件。
        $this->assertCount(5, $suites, '30 分钟窗口应产出 5 个套件（今天 1 + 昨天 2 + 2 天前 1 + 4 天前 1）');
        foreach ($suites as $suite) {
            $dates = array_unique(array_map(
                fn ($f) => Carbon::parse($f['backup_at'])->format('Y-m-d'),
                $suite['files']
            ));
            $this->assertCount(1, $dates, '单个套件不得跨天；suite_id=' . $suite['suite_id']);
        }

        // 今天：两场 self 备份间隔 10 分钟 ≤ 窗口 → 合并为 1 个套件 4 个文件
        $today = Carbon::today()->format('Y-m-d');
        $todayGroup = collect($groups)->firstWhere('date', $today);
        $this->assertNotNull($todayGroup);
        $this->assertCount(1, $todayGroup['suites']);
        $this->assertSame(4, $todayGroup['suites'][0]['total_count']);

        // 昨天：两场 external 间隔 96 分钟 > 窗口 → 2 个套件各 2 个文件
        $yesterday = Carbon::yesterday()->format('Y-m-d');
        $yGroup = collect($groups)->firstWhere('date', $yesterday);
        $this->assertCount(2, $yGroup['suites']);
        $this->assertSame(2, $yGroup['suites'][0]['total_count']);
        $this->assertSame(2, $yGroup['suites'][1]['total_count']);

        // 日期组键正确且倒序（新→旧），共 4 天（data 是列表，date 在各元素内）
        $this->assertSame([
            $today,
            $yesterday,
            Carbon::now()->subDays(2)->format('Y-m-d'),
            Carbon::now()->subDays(4)->format('Y-m-d'),
        ], array_column($groups, 'date'));

        // 每个日期组只包含当天文件（文件级归属，f7e2bc0 的防御逻辑仍然生效）
        foreach ($groups as $group) {
            $date = $group['date'];
            foreach ($group['suites'] as $suite) {
                foreach ($suite['files'] as $f) {
                    $this->assertSame($date, Carbon::parse($f['backup_at'])->format('Y-m-d'));
                }
            }
        }
    }

    public function test_single_file_suite_is_not_merged_with_far_older_file(): void
    {
        $website = $this->makeWebsite();
        // 同一小时内两文件 → 合并；3 小时前的文件 → 独立套件
        $this->makeRestorableLog($website, 'a-web.tar.gz', Carbon::now()->subMinutes(10));
        $this->makeRestorableLog($website, 'a-db.sql.gz', Carbon::now()->subMinutes(5));
        $this->makeRestorableLog($website, 'old-web.tar.gz', Carbon::now()->subHours(3));

        $resp = (new RestoreController())->backups(new Request(['website_id' => $website->id]));
        $suites = collect($resp->getData(true)['data'])->pluck('suites')->flatten(1)->all();

        $this->assertCount(2, $suites);
        $this->assertCount(2, $suites[0]['files']);
        $this->assertCount(1, $suites[1]['files']);
        // suite_id 取组内最新文件（a-db 比 a-web 新 5 分钟，倒序第一个入组）
        $this->assertSame('suite-' . BackupLog::where('filename', 'a-db.sql.gz')->value('id'), $suites[0]['suite_id']);
    }
}
