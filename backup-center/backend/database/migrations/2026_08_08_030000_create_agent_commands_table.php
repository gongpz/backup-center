<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();

            // 命令类型：目前只有 update；预留扩展
            $table->string('type', 32);

            // 参数（预留：例如 update 传 target_version、force 等）
            $table->json('params')->nullable();

            // 状态机：pending -> dispatched -> success | failed
            $table->enum('status', ['pending', 'dispatched', 'success', 'failed'])
                  ->default('pending');

            // Agent 侧执行结果
            $table->text('output')->nullable();
            $table->text('error')->nullable();

            // 快照发起时的 agent_version（下次心跳时用来判定 update 是否生效）
            $table->string('from_version', 32)->nullable();

            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commands');
    }
};
