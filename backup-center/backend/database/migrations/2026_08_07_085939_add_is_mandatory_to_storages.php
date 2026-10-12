<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storages', function (Blueprint $table) {
            // 标记为"强制备份"：所有网站的备份都会自动推送到这个存储
            $table->boolean('is_mandatory')->default(false)->after('status');
            $table->index('is_mandatory');
        });
    }

    public function down(): void
    {
        Schema::table('storages', function (Blueprint $table) {
            $table->dropIndex(['is_mandatory']);
            $table->dropColumn('is_mandatory');
        });
    }
};
