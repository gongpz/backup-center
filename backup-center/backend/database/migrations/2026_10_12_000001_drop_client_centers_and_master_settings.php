<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 开源改造：摘除主控 / 客户端体系与推送升级通道。
 *
 * 1. 删除 client_centers 表（主控管理客户端实例用）
 * 2. 清理 system_settings 里的主控 / 推送相关键值
 *
 * 注意：本迁移只删功能开关与登记表，不触碰业务数据
 * （servers / websites / storages / backup_logs 等全部保留）。
 */
return new class extends Migration
{
    private array $obsoleteKeys = [
        'is_master',
        'master_upstream_url',
        'master_push_token',
        'pending_agent_update',
        'pending_center_update',
    ];

    public function up(): void
    {
        Schema::dropIfExists('client_centers');

        DB::table('system_settings')
            ->whereIn('key', $this->obsoleteKeys)
            ->delete();
    }

    public function down(): void
    {
        // 不回滚：删除的是已废弃功能，表结构不再维护
    }
};
