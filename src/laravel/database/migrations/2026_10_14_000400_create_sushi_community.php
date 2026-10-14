<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('display_name', 50)->nullable();
            $t->string('bio', 240)->nullable();
            $t->string('avatar')->default('rice');
            $t->string('photo_path')->nullable();
            $t->json('pinned_badges')->nullable();
            $t->string('timezone')->default('Asia/Manila');
            $t->timestamp('timezone_changed_at')->nullable();
            $t->string('reminder_time', 5)->default('19:00');
            $t->boolean('reminder_enabled')->default(false);
            $t->timestamp('reminder_snoozed_until')->nullable();
            $t->string('reminder_dismissed_date', 10)->nullable();
            $t->json('fresh_subjects')->nullable();
        });
        Schema::create('study_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('source')->unique();
            $t->string('question_id');
            $t->string('question_hash', 64)->index();
            $t->string('subject');
            $t->string('mode');
            $t->boolean('correct');
            $t->boolean('bookmarked')->default(false);
            $t->timestamp('occurred_at')->index();
            $t->index(['user_id', 'occurred_at']);
        });
        Schema::create('learning_views', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('question_hash', 64);
            $t->string('subject');
            $t->string('mode');
            $t->timestamp('created_at');
            $t->unique(['user_id', 'question_hash', 'mode']);
        });
        Schema::create('earned_badges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('badge');
            $t->timestamp('earned_at');
            $t->unique(['user_id', 'badge']);
        });
        Schema::create('daily_catches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('attempt_id')->unique()->constrained();
            $t->date('day');
            $t->string('timezone');
            $t->timestamp('completed_at');
            $t->unique(['user_id', 'day']);
        });
        Schema::create('push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('endpoint_hash', 64)->unique();
            $t->text('subscription');
            $t->timestamps();
        });
        Schema::create('catch_reminders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->date('day');
            $t->string('status')->default('queued');
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('clicked_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'day']);
        });
        Schema::table('flashcard_decks', function (Blueprint $t) {
            $t->unsignedInteger('session_number')->default(1);
            $t->unsignedInteger('session_start_reviews')->default(0);
            $t->boolean('goal_prompt_dismissed')->default(false);
            $t->timestamp('session_finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('flashcard_decks', fn (Blueprint $t) => $t->dropColumn(['session_number', 'session_start_reviews', 'goal_prompt_dismissed', 'session_finished_at']));
        foreach (['catch_reminders', 'push_subscriptions', 'daily_catches', 'earned_badges', 'learning_views', 'study_events'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['display_name', 'bio', 'avatar', 'photo_path', 'pinned_badges', 'timezone', 'timezone_changed_at', 'reminder_time', 'reminder_enabled', 'reminder_snoozed_until', 'reminder_dismissed_date', 'fresh_subjects']));
    }
};
