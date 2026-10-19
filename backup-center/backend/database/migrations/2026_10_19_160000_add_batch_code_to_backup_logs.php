<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_logs', function (Blueprint $table) {
            // 任务编码：self_backup 指令 ID（高级模式/未来的增量模式按编码精确分组）。
            // 一次 self_backup 指令 = Agent 完整跑一次，同编码必同场次、不同编码必不同
            // 场次，零时间猜测——纯时间窗口有「两场挨得近就糊住」的固有风险
            // （用户 00:55/01:05 两次手动备份只隔 10 分钟）。
            // 宝塔 external 扫描上报不带编码（保持 ''），继续走「类型配对 + 窗口」。
            // 长度 64：指令 ID 最长 20 位，留足余量；默认 '' 避免 NULL 分组语义坑。
            $table->string('batch_code', 64)->default('')->after('source_key');
            $table->index(['website_id', 'batch_code']);
        });

        // 无需回填：存量 self 备份记录编码为 ''，自动走配对+窗口老路，
        // 只有升级 Agent（0.14.5）后新产生的自主备份才带编码。
    }

    public function down(): void
    {
        Schema::table('backup_logs', function (Blueprint $table) {
            $table->dropIndex(['website_id', 'batch_code']);
            $table->dropColumn('batch_code');
        });
    }
};
