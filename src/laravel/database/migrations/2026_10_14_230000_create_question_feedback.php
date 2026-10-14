<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('question_id');
            $t->foreignId('question_version_id')->constrained();
            $t->string('category');
            $t->text('details');
            $t->string('status')->default('open')->index();
            $t->text('resolution')->nullable();
            $t->unsignedInteger('revision')->default(0);
            $t->timestamps();
        });
        Schema::create('question_report_updates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('question_report_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('status');
            $t->text('resolution');
            $t->timestamp('created_at');
        });
        Schema::create('learning_notices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('event_key');
            $t->string('question_id');
            $t->string('title');
            $t->text('message');
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at');
            $t->unique(['user_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_notices');
        Schema::dropIfExists('question_report_updates');
        Schema::dropIfExists('question_reports');
    }
};
