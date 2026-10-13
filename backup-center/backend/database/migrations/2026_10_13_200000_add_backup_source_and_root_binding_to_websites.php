<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $t) {
            // 备份来源：external=第三方压缩/监控文件夹（现状）；self=系统自主压缩（P3 落地）
            $t->string('backup_source', 16)->default('external')->after('sync_mode');
            // 恢复模式：lite=轻便（拉回备份目录，手动恢复）；advanced=高级（自动恢复，P4 落地）
            $t->string('restore_mode', 16)->default('lite')->after('backup_source');

            // 根目录绑定（密钥文件机制）：Agent 搜索到密钥文件后回传并写入 web_root
            $t->string('bind_token', 64)->nullable()->unique()->after('restore_mode');
            $t->string('web_root')->nullable()->after('bind_token');
            $t->timestamp('root_bound_at')->nullable()->after('web_root');

            // 数据库连接（P3 自主备份 / P4 高级恢复用；P1 仅建列预留，db_password 加密存储）
            $t->string('db_type', 16)->nullable()->after('root_bound_at');   // mysql | postgresql
            $t->string('db_host', 128)->nullable()->after('db_type');
            $t->string('db_port', 16)->nullable()->after('db_host');
            $t->string('db_name', 128)->nullable()->after('db_port');
            $t->string('db_user', 128)->nullable()->after('db_name');
            $t->text('db_password')->nullable()->after('db_user');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $t) {
            $t->dropColumn([
                'backup_source', 'restore_mode',
                'bind_token', 'web_root', 'root_bound_at',
                'db_type', 'db_host', 'db_port', 'db_name', 'db_user', 'db_password',
            ]);
        });
    }
};
