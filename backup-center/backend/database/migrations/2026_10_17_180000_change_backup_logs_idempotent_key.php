<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 幂等键从 (website_id, filename) 改为 (website_id, full_path)。
     *
     * 背景：一个网站可以配置多个备份目录，不同目录下可能存在同名备份文件
     *（比如两个目录里都叫 backup.zip）。旧的唯一索引会把第二个目录的同名文件
     * 当成重复记录丢弃，表现为「两个目录都备份成功了，但恢复时只显示一个可恢复」。
     *
     * 步骤：
     *   1. 回填历史数据里 full_path 为 NULL / 空字符串的记录（用 filename 兜底），
     *      保证改成按 full_path 去重后，老数据依然能被正确匹配到；
     *   2. 删除旧唯一索引 backup_logs_website_id_filename_unique；
     *   3. 新增 (website_id, full_path) 索引用于高效查找。
     *
     * 幂等本身由 AgentController::report 的 firstOrNew 在应用层保证，
     * 这里刻意不建唯一索引：历史库里若已存在 full_path 重复的行，
     * 建唯一索引会让迁移直接失败。
     */
    public function up(): void
    {
        // 1) 回填空路径，避免老数据按 full_path 查不到而重复建记录
        DB::table('backup_logs')
            ->whereNull('full_path')
            ->orWhere('full_path', '')
            ->update(['full_path' => DB::raw('filename')]);

        // 2) 去掉旧的 (website_id, filename) 唯一索引
        Schema::table('backup_logs', function (Blueprint $table) {
            $table->dropUnique(['website_id', 'filename']);
        });

        // 3) 新的 (website_id, full_path) 查找索引
        Schema::table('backup_logs', function (Blueprint $table) {
            $table->index(['website_id', 'full_path'], 'backup_logs_website_id_full_path_index');
        });
    }

    public function down(): void
    {
        Schema::table('backup_logs', function (Blueprint $table) {
            $table->dropIndex('backup_logs_website_id_full_path_index');
        });

        Schema::table('backup_logs', function (Blueprint $table) {
            $table->unique(['website_id', 'filename']);
        });
    }
};
