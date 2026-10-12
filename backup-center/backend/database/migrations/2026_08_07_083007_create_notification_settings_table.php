<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->text('serverchan_sendkey')->nullable();   // 加密存储的 Server酱 SendKey
            $table->boolean('notify_backup_success')->default(false);
            $table->boolean('notify_backup_failed')->default(true);
            $table->boolean('notify_agent_offline')->default(true);
            $table->timestamps();
        });

        // 单例行（永远只有 id=1）
        DB::table('notification_settings')->insert([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
