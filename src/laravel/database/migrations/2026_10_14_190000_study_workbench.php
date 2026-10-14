<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_drafts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained();
            $t->string('title', 150);
            $t->jsonb('settings');
            $t->jsonb('items');
            $t->unsignedInteger('revision')->default(0);
            $t->timestamp('expires_at');
            $t->foreignId('saved_exam_id')->nullable()->constrained('exams');
            $t->timestamps();
        });
        Schema::create('study_presets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('name', 80);
            $t->jsonb('settings');
            $t->timestamps();
            $t->unique(['user_id', 'name']);
        });
    }

    public function down(): void {}
};
