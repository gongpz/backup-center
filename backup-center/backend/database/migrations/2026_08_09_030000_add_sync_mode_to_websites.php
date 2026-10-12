<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $t) {
            // auto = Agent 每 2 分钟自动扫描（默认，向后兼容）
            // scheduled = Agent 不主动扫，Center 每天到点派发 sync_now
            $t->string('sync_mode', 16)->default('auto')->after('schedule');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $t) {
            $t->dropColumn('sync_mode');
        });
    }
};
