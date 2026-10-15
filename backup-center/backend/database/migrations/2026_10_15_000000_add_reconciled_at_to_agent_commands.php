<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * agent_commands 增加 reconciled_at：
     * 保留期清理（cleanup_retention）指令对账专用 —— 指令终态（success/failed）被
     * bc:cleanup-retention 处理完毕后打时间戳，防止重复对账重复删行。
     */
    public function up(): void
    {
        Schema::table('agent_commands', function (Blueprint $table) {
            $table->timestamp('reconciled_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('agent_commands', function (Blueprint $table) {
            $table->dropColumn('reconciled_at');
        });
    }
};
