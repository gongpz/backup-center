<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();

            $table->string('name');                       // 网站名（如"我的博客"）
            $table->string('domain')->nullable();         // 可选：绑定域名
            $table->string('backup_path');                // 备份目录（Agent 侧扫描路径）
            $table->string('file_pattern')->nullable();   // 可选：文件名通配（例 mysite_*.tar.gz）

            $table->unsignedSmallInteger('retention_days')->default(30);
            $table->string('schedule')->nullable();       // cron 表达式（策略，MVP ⑤ 使用）

            $table->enum('status', ['enabled', 'disabled'])->default('enabled');
            $table->text('remark')->nullable();

            // Agent 上报的最新备份信息
            $table->timestamp('last_backup_at')->nullable();
            $table->enum('last_backup_status', ['success', 'failed', 'running'])->nullable();
            $table->unsignedBigInteger('last_backup_size')->nullable();
            $table->string('last_backup_filename')->nullable();

            $table->timestamps();

            $table->index(['server_id', 'status']);
            $table->index('last_backup_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
