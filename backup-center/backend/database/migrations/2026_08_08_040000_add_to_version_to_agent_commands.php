<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_commands', function (Blueprint $table) {
            $table->string('to_version', 32)->nullable()->after('from_version');
        });
    }

    public function down(): void
    {
        Schema::table('agent_commands', function (Blueprint $table) {
            $table->dropColumn('to_version');
        });
    }
};
