<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_uploads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('backup_log_id')->constrained('backup_logs')->cascadeOnDelete();
            $table->foreignId('storage_id')->constrained('storages')->cascadeOnDelete();

            $table->enum('status', ['pending', 'uploading', 'uploaded', 'failed'])->default('pending');
            $table->string('remote_path')->nullable();        // 上传到存储的完整路径（含子目录）
            $table->unsignedBigInteger('uploaded_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamps();

            // 一个 backup_log 对每个 storage 只有一条记录
            $table->unique(['backup_log_id', 'storage_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_uploads');
    }
};
