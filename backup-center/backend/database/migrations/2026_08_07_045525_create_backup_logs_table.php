<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();

            $table->string('filename');                    // 备份文件名（不含路径）
            $table->string('full_path')->nullable();       // 备份完整路径
            $table->unsignedBigInteger('size')->nullable();// 字节数
            $table->enum('status', ['success', 'failed', 'running'])->default('success');
            $table->string('error_message')->nullable();

            $table->timestamp('backup_at');                // 备份文件的 mtime（真正的备份完成时刻）
            $table->unsignedInteger('duration_seconds')->nullable(); // 备份用时（如果 Agent 能推算）

            // Rclone 上传状态（后续 MVP ⑤ 用）
            $table->enum('upload_status', ['pending', 'uploading', 'uploaded', 'upload_failed'])
                ->default('pending');
            $table->string('upload_target')->nullable();   // 存储目标名字（阿里云盘等）
            $table->timestamp('uploaded_at')->nullable();

            $table->timestamps();

            // 幂等：同一网站的同名文件只有一条记录
            $table->unique(['website_id', 'filename']);

            $table->index(['website_id', 'backup_at']);
            $table->index('status');
            $table->index('upload_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_logs');
    }
};
