<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            // 备份方式：full=全量（现行为）；incremental=rsync 硬链快照（仅 backup_source=self 可选）
            $table->string('backup_mode', 16)->default('full')->after('backup_source');
            // 增量备份目标存储（单值；必须是 SFTP + SSH 私钥 + 未加密存储）
            $table->unsignedBigInteger('incremental_storage_id')->nullable()->after('backup_mode');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['backup_mode', 'incremental_storage_id']);
        });
    }
};
