<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();

            /* ---------- 基本信息 ---------- */
            $table->string('name');                    // 服务器名称
            $table->string('ip')->nullable();          // IP 地址（Agent 上报后自动写入）
            $table->string('os_type', 32)->nullable(); // 系统类型 linux / debian / centos / ubuntu / windows
            $table->text('remark')->nullable();        // 备注

            /* ---------- 连接模式 ---------- */
            $table->enum('connect_type', ['agent', 'ssh'])->default('agent');

            /* ---------- Agent 模式字段 ---------- */
            $table->string('agent_token', 80)->nullable()->unique(); // Agent 认证 token
            $table->string('agent_version', 32)->nullable();
            $table->string('hostname')->nullable();
            $table->string('kernel', 64)->nullable();
            $table->string('arch', 16)->nullable();
            $table->decimal('cpu_usage', 5, 2)->nullable();  // 最近一次心跳的 CPU%
            $table->decimal('mem_usage', 5, 2)->nullable();  // 内存%
            $table->decimal('disk_usage', 5, 2)->nullable(); // 备份盘%
            $table->unsignedBigInteger('uptime')->nullable();

            /* ---------- SSH 模式字段 ---------- */
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('ssh_user', 64)->nullable();
            $table->text('ssh_private_key')->nullable(); // SSH 私钥（明文存储，只本地使用）
            $table->string('backup_path')->nullable();   // 默认备份目录

            /* ---------- 状态 ---------- */
            $table->enum('status', ['pending', 'online', 'offline', 'error'])
                ->default('pending');
            $table->string('status_message')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('registered_at')->nullable(); // 第一次 Agent 注册成功

            $table->timestamps();

            $table->index('status');
            $table->index('connect_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
