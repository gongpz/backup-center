<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BackupLogResource;
use App\Models\BackupLog;
use App\Models\Server;
use App\Models\Storage;
use App\Models\Website;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * 一次性返回 Dashboard 所有数据：KPI + 图表 + 最近记录 + 存储列表
     */
    public function index(Request $request): JsonResponse
    {
        $range = $request->string('range', 'month')->toString();
        $ranges = ['day', 'week', 'month', 'quarter', 'halfyear', 'year'];
        if (! in_array($range, $ranges, true)) {
            $range = 'month';
        }

        $cfg = $this->rangeConfig($range);
        $now = Carbon::now();
        $start = $now->copy()->subDays($cfg['days']);
        $prevStart = $start->copy()->subDays($cfg['days']);

        // ---------- 服务器 & 网站 计数 ----------
        $serverCount = Server::count();
        $onlineCount = Server::all()
            ->filter(fn ($s) => $s->computedStatus() === Server::STATUS_ONLINE)
            ->count();
        $websiteCount = Website::count();
        $enabledWebsiteCount = Website::where('status', Website::STATUS_ENABLED)->count();

        // ---------- 当前周期 备份统计 ----------
        $curLogs = BackupLog::whereBetween('backup_at', [$start, $now])
            ->get(['id', 'status', 'size', 'backup_at']);
        $curSuccess = $curLogs->where('status', BackupLog::STATUS_SUCCESS)->count();
        $curFailed  = $curLogs->where('status', BackupLog::STATUS_FAILED)->count();
        $curCount   = $curLogs->count();
        $curSize    = (int) $curLogs->sum('size');

        // ---------- 上一周期 用于计算变化率 ----------
        $prevAgg = BackupLog::whereBetween('backup_at', [$prevStart, $start])
            ->selectRaw("
                COUNT(CASE WHEN status='success' THEN 1 END) as success_count,
                COUNT(CASE WHEN status='failed' THEN 1 END) as failed_count,
                COUNT(*) as total_count,
                COALESCE(SUM(size), 0) as total_size
            ")
            ->first();
        $prevSuccess = (int) ($prevAgg->success_count ?? 0);
        $prevFailed  = (int) ($prevAgg->failed_count ?? 0);
        $prevCount   = (int) ($prevAgg->total_count ?? 0);
        $prevSize    = (int) ($prevAgg->total_size ?? 0);

        // ---------- 累计（历史全部） ----------
        $cumAgg = BackupLog::selectRaw("COUNT(*) as cnt, COALESCE(SUM(size),0) as sz")->first();
        $cumCount = (int) ($cumAgg->cnt ?? 0);
        $cumSize  = (int) ($cumAgg->sz ?? 0);

        // ---------- 时间分桶：备份次数趋势 + 大小趋势 ----------
        $buckets = $this->buildBuckets($range, $now);
        $countTrend = [];
        $sizeTrend  = [];
        foreach ($buckets as $b) {
            $inBucket = $curLogs->filter(fn ($l) => $l->backup_at->between($b['start'], $b['end']));
            $countTrend[] = ['label' => $b['label'], 'value' => $inBucket->count()];
            $sizeTrend[]  = ['label' => $b['label'], 'value' => (int) $inBucket->sum('size')];
        }

        // ---------- 最近备份记录（10 条） ----------
        $recent = BackupLog::with(['website:id,name,domain', 'server:id,name'])
            ->orderByDesc('backup_at')
            ->limit(10)
            ->get();

        // ---------- 存储列表（含配额和已用量） ----------
        $storages = Storage::withCount('websites')->get()->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'type' => $s->type,
            'status' => $s->status,
            'is_mandatory' => (bool) $s->is_mandatory,
            'websites_count' => $s->websites_count,
            'upload_count' => $s->uploadCount(),
            'last_test_status' => $s->last_test_status,
            'quota_bytes' => $s->quota_bytes,
            'used_bytes' => (int) $s->usedBytes(),
        ]);

        return response()->json([
            'range' => $range,
            'range_label' => $cfg['label'],

            'stats' => [
                'server_count' => $serverCount,
                'server_online' => $onlineCount,
                'website_count' => $websiteCount,
                'website_enabled' => $enabledWebsiteCount,

                'period_success' => $curSuccess,
                'period_failed'  => $curFailed,
                'period_count'   => $curCount,
                'period_size'    => $curSize,

                'change' => [
                    'success' => $this->pct($curSuccess, $prevSuccess),
                    'failed'  => $this->pct($curFailed,  $prevFailed),
                    'count'   => $this->pct($curCount,   $prevCount),
                    'size'    => $this->pct($curSize,    $prevSize),
                ],

                'cumulative_count' => $cumCount,
                'cumulative_size'  => $cumSize,
            ],

            'count_trend' => $countTrend,
            'size_trend'  => $sizeTrend,

            'recent_logs' => BackupLogResource::collection($recent)->resolve($request),
            'storages' => $storages,
        ]);
    }

    /* ---------- 辅助方法 ---------- */

    private function rangeConfig(string $range): array
    {
        return [
            'day'      => ['days' => 1,   'label' => '近 24 小时'],
            'week'     => ['days' => 7,   'label' => '近 7 天'],
            'month'    => ['days' => 30,  'label' => '近 30 天'],
            'quarter'  => ['days' => 90,  'label' => '近 3 个月'],
            'halfyear' => ['days' => 180, 'label' => '近 6 个月'],
            'year'     => ['days' => 365, 'label' => '近 12 个月'],
        ][$range];
    }

    /**
     * 生成时间分桶。返回 [{start, end, label}, ...]
     */
    private function buildBuckets(string $range, Carbon $now): array
    {
        $buckets = [];
        switch ($range) {
            case 'day':
                // 12 个每 2 小时桶：22 小时前 → 现在
                $start = $now->copy()->subHours(22)->startOfHour();
                for ($i = 0; $i < 12; $i++) {
                    $s = $start->copy()->addHours($i * 2);
                    $e = $s->copy()->addHours(2);
                    $buckets[] = [
                        'start' => $s,
                        'end' => $e,
                        'label' => $s->format('H'),
                    ];
                }
                break;

            case 'week':
                // 7 个每日桶：6 天前 → 今天
                $start = $now->copy()->subDays(6)->startOfDay();
                $weekMap = ['一', '二', '三', '四', '五', '六', '日'];
                for ($i = 0; $i < 7; $i++) {
                    $s = $start->copy()->addDays($i);
                    $e = $s->copy()->addDay();
                    $buckets[] = [
                        'start' => $s,
                        'end' => $e,
                        'label' => '周' . $weekMap[$s->dayOfWeekIso - 1],
                    ];
                }
                break;

            case 'month':
                // 4 个每周桶：3 周前 → 今天
                $start = $now->copy()->subWeeks(3)->startOfWeek();
                for ($i = 0; $i < 4; $i++) {
                    $s = $start->copy()->addWeeks($i);
                    $e = $s->copy()->addWeek();
                    $buckets[] = [
                        'start' => $s,
                        'end' => $e,
                        'label' => '第' . ($i + 1) . '周',
                    ];
                }
                break;

            case 'quarter':
                // 3 个月桶
                $months = 3;
                $start = $now->copy()->subMonths($months - 1)->startOfMonth();
                for ($i = 0; $i < $months; $i++) {
                    $s = $start->copy()->addMonths($i);
                    $e = $s->copy()->addMonth();
                    $buckets[] = [
                        'start' => $s,
                        'end' => $e,
                        'label' => $s->format('n') . '月',
                    ];
                }
                break;

            case 'halfyear':
                $months = 6;
                $start = $now->copy()->subMonths($months - 1)->startOfMonth();
                for ($i = 0; $i < $months; $i++) {
                    $s = $start->copy()->addMonths($i);
                    $e = $s->copy()->addMonth();
                    $buckets[] = [
                        'start' => $s,
                        'end' => $e,
                        'label' => $s->format('n') . '月',
                    ];
                }
                break;

            case 'year':
                $months = 12;
                $start = $now->copy()->subMonths($months - 1)->startOfMonth();
                for ($i = 0; $i < $months; $i++) {
                    $s = $start->copy()->addMonths($i);
                    $e = $s->copy()->addMonth();
                    $buckets[] = [
                        'start' => $s,
                        'end' => $e,
                        'label' => $s->format('n') . '月',
                    ];
                }
                break;
        }
        return $buckets;
    }

    /**
     * 计算变化百分比。null 表示上期为 0（无对比意义）
     */
    private function pct(int $cur, int $prev): ?float
    {
        if ($prev === 0) return null;
        return round(($cur - $prev) / $prev * 100, 1);
    }
}
