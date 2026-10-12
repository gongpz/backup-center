<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pool_resource_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pool_id')->constrained('resource_pools')->cascadeOnDelete();
            $t->string('path', 500); // COS/R2 object key，最长约 1024 但 500 足够
            $t->text('remark')->nullable();
            $t->timestamps();

            $t->unique(['pool_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pool_resource_notes');
    }
};
