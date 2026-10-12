<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->json('backup_paths')->nullable()->after('domain');
        });

        // 把旧的单目录字段迁移到 backup_paths[0]
        foreach (DB::table('websites')->get() as $w) {
            $path = $w->backup_path ?? null;
            if (! $path) {
                continue;
            }
            DB::table('websites')->where('id', $w->id)->update([
                'backup_paths' => json_encode([[
                    'label'   => '备份目录1',
                    'path'    => $path,
                    'pattern' => $w->file_pattern ?? '',
                ]], JSON_UNESCAPED_UNICODE),
            ]);
        }

        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['backup_path', 'file_pattern']);
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('backup_path')->nullable()->after('domain');
            $table->string('file_pattern')->nullable()->after('backup_path');
        });

        foreach (DB::table('websites')->get() as $w) {
            $arr = json_decode($w->backup_paths ?? '[]', true);
            if (! is_array($arr) || count($arr) === 0) {
                continue;
            }
            $first = $arr[0];
            DB::table('websites')->where('id', $w->id)->update([
                'backup_path'  => $first['path'] ?? null,
                'file_pattern' => $first['pattern'] ?? null,
            ]);
        }

        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('backup_paths');
        });
    }
};
