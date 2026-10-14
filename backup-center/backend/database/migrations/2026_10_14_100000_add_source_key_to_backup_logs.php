<?php

use App\Support\BackupSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_logs', function (Blueprint $table) {
            // 非空 + 默认 ''：避免 GROUP BY / JOIN 时 NULL 的语义坑（NULL 分组能分，
            // 但 JOIN ON source_key = source_key 对 NULL 不成立）。解析不出来的老数据
            // 统一落进 '' 组，护栏 4 至少保住其中最新 1 条。
            // 长度 300 而不是默认 255：external 的取值是 'ext:' + 备份目录，
            // 而 backup_paths.*.path 的校验上限是 255，拼上 4 字符前缀后最大 259，
            // 超过 255 会在严格模式下直接报 "Data too long"（打挂 Agent 上报），
            // 非严格模式则静默截断、导致两个长路径撞进同一分组、把要修的缺陷又引回来。
            $table->string('source_key', 300)->default('')->after('full_path');
            $table->index(['website_id', 'source_key', 'backup_at']);
        });

        // 回填存量行：join 出网站的 backup_paths，用与写入端同一个纯函数推导，
        // 保证回填结果与 AgentController::report() 的写入完全一致。
        DB::table('backup_logs')
            ->join('websites', 'websites.id', '=', 'backup_logs.website_id')
            ->select([
                'backup_logs.id as id',
                'backup_logs.filename as filename',
                'backup_logs.full_path as full_path',
                'websites.backup_paths as backup_paths',
            ])
            ->orderBy('backup_logs.id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $paths = json_decode((string) ($row->backup_paths ?? ''), true);
                    if (! is_array($paths)) {
                        $paths = [];
                    }

                    DB::table('backup_logs')->where('id', $row->id)->update([
                        'source_key' => BackupSource::derive(
                            (string) $row->filename,
                            $row->full_path,
                            $paths
                        ),
                    ]);
                }
            }, 'backup_logs.id');
    }

    public function down(): void
    {
        Schema::table('backup_logs', function (Blueprint $table) {
            $table->dropIndex(['website_id', 'source_key', 'backup_at']);
            $table->dropColumn('source_key');
        });
    }
};
