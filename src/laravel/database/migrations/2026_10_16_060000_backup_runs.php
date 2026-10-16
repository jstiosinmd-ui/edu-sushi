<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('status');
            $t->string('path')->nullable();
            $t->string('sha256', 64)->nullable();
            $t->unsignedBigInteger('bytes')->nullable();
            $t->boolean('mirrored')->default(false);
            $t->jsonb('counts')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->string('restore_database')->nullable();
            $t->text('error')->nullable();
            $t->timestamp('created_at');
            $t->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void {}
};
