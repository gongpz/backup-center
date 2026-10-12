<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_pools', function (Blueprint $t) {
            $t->id();
            $t->string('name', 64);
            $t->string('provider', 32);
            $t->string('endpoint', 191)->nullable();
            $t->string('region', 64)->nullable();
            $t->string('bucket', 128);
            $t->text('config');
            $t->string('public_domain', 191)->nullable();
            $t->boolean('is_agent_mirror')->default(false);
            $t->string('status', 16)->default('enabled');
            $t->string('last_test_status', 16)->nullable();
            $t->text('last_test_message')->nullable();
            $t->timestamp('last_tested_at')->nullable();
            $t->text('remark')->nullable();
            $t->timestamps();

            $t->index('is_agent_mirror');
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_pools');
    }
};
