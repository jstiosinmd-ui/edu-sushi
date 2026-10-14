<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Null preserves the existing highest-earned frame until a learner chooses.
            $table->string('avatar_frame', 16)->nullable();
        });
    }

    public function down(): void {}
};
