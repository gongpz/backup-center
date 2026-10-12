<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storages', function (Blueprint $t) {
            $t->string('access_url', 255)->nullable()->after('remark');
        });
    }

    public function down(): void
    {
        Schema::table('storages', function (Blueprint $t) {
            $t->dropColumn('access_url');
        });
    }
};
