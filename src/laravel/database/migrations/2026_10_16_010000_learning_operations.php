<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_quality_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('question_version_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->boolean('source_checked');
            $t->boolean('answer_checked');
            $t->string('decision');
            $t->text('notes');
            $t->timestamp('created_at');
        });
        Schema::table('study_presets', function (Blueprint $t) {
            $t->boolean('shared')->default(false);
            $t->string('cohort')->nullable();
        });
    }

    public function down(): void {}
};
