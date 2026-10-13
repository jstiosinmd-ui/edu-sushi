<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('email')->nullable()->change();
            $t->string('username')->nullable()->unique();
            $t->string('role')->default('student');
            $t->boolean('disabled')->default(false);
            $t->boolean('must_change_password')->default(true);
            $t->unsignedInteger('auth_version')->default(1);
            $t->string('cohort')->nullable();
            $t->timestamp('last_active_at')->nullable();
        });
        Schema::create('questions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('subject');
            $t->string('topic');
            $t->string('difficulty');
            $t->string('stem_hash', 64)->index();
            $t->unsignedInteger('position');
            $t->unsignedBigInteger('current_version_id')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('question_versions', function (Blueprint $t) {
            $t->id();
            $t->string('question_id');
            $t->foreign('question_id')->references('id')->on('questions');
            $t->unsignedInteger('version');
            $t->json('content');
            $t->string('content_hash', 64);
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['question_id', 'version']);
        });
        Schema::create('exams', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('title');
            $t->string('code')->unique();
            $t->json('settings');
            $t->json('items');
            $t->timestamps();
        });
        Schema::create('assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exam_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->timestamp('opens_at');
            $t->timestamp('closes_at');
            $t->unsignedInteger('minutes');
            $t->unsignedInteger('max_attempts')->default(1);
            $t->boolean('release_answers')->default(false);
            $t->timestamps();
            $t->unique(['exam_id', 'user_id']);
        });
        Schema::create('attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('assignment_id')->nullable()->constrained();
            $t->string('mode');
            $t->string('status')->default('active');
            $t->json('settings');
            $t->timestamp('deadline')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->unsignedInteger('revision')->default(0);
            $t->unsignedInteger('active_seconds')->default(0);
            $t->timestamp('heartbeat_at')->nullable();
            $t->unsignedInteger('score')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
        Schema::create('attempt_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('attempt_id')->constrained();
            $t->string('question_id');
            $t->unsignedInteger('position');
            $t->json('snapshot');
            $t->char('answer', 1)->nullable();
            $t->boolean('correct')->nullable();
            $t->timestamp('graded_at')->nullable();
            $t->timestamps();
            $t->unique(['attempt_id', 'question_id']);
        });
        Schema::create('answer_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('attempt_item_id')->constrained();
            $t->char('answer', 1);
            $t->unsignedInteger('revision');
            $t->timestamps();
        });
        Schema::create('progress', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('question_id');
            $t->timestamp('selected_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->boolean('bookmarked')->default(false);
            $t->boolean('legacy')->default(false);
            $t->boolean('history_unknown')->default(false);
            $t->char('latest_answer', 1)->nullable();
            $t->boolean('latest_correct')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'question_id']);
        });
        Schema::create('imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('kind');
            $t->string('hash', 64);
            $t->json('summary');
            $t->timestamps();
            $t->unique(['user_id', 'kind', 'hash']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('action');
            $t->string('target');
            $t->json('details')->nullable();
            $t->timestamps();
        });
        Schema::create('pdf_exports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exam_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('status')->default('queued');
            $t->boolean('explanations')->default(true);
            $t->string('error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['pdf_exports', 'audit_logs', 'imports', 'progress', 'answer_revisions', 'attempt_items', 'attempts', 'assignments', 'exams', 'question_versions', 'questions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['username', 'role', 'disabled', 'must_change_password', 'auth_version', 'cohort', 'last_active_at']));
    }
};
