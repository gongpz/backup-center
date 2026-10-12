<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storages', function (Blueprint $table) {
            // 用户手动配置的存储配额（字节）。0 或 null 表示不限
            $table->unsignedBigInteger('quota_bytes')->nullable()->after('base_path');
        });
    }

    public function down(): void
    {
        Schema::table('storages', function (Blueprint $table) {
            $table->dropColumn('quota_bytes');
        });
    }
};
