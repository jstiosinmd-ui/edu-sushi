<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_set_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 80);
            $table->string('content_hash', 64);
            $table->jsonb('answers');
            $table->timestamps();
            $table->unique(['user_id', 'slug', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_set_progress');
    }
};
