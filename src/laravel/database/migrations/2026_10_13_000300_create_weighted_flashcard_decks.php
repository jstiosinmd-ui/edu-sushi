<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flashcard_decks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('title');
            $t->json('settings');
            $t->string('active_question_id')->nullable();
            $t->unsignedInteger('reviews')->default(0);
            $t->timestamps();
        });
        Schema::create('flashcard_deck_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('flashcard_deck_id')->constrained();
            $t->string('question_id');
            $t->json('snapshot');
            $t->unsignedTinyInteger('confidence')->nullable();
            $t->unsignedInteger('reviews')->default(0);
            $t->char('answer', 1)->nullable();
            $t->boolean('correct')->nullable();
            $t->string('review_token')->nullable();
            $t->timestamps();
            $t->unique(['flashcard_deck_id', 'question_id']);
        });
        Schema::table('flashcard_reviews', function (Blueprint $t) {
            $t->foreignId('flashcard_deck_item_id')->nullable()->constrained();
            $t->char('answer', 1)->nullable();
            $t->boolean('correct')->nullable();
            $t->dropColumn('interval_seconds');
        });
        Schema::table('flashcards', fn (Blueprint $t) => $t->dropColumn(['interval_seconds', 'due_at', 'revealed_at', 'review_token', 'snapshot']));
    }

    public function down(): void
    {
        Schema::table('flashcard_reviews', function (Blueprint $t) {
            $t->dropConstrainedForeignId('flashcard_deck_item_id');
            $t->dropColumn(['answer', 'correct']);
            $t->unsignedInteger('interval_seconds')->default(0);
        });
        Schema::table('flashcards', function (Blueprint $t) {
            $t->unsignedInteger('interval_seconds')->default(0);
            $t->timestamp('due_at')->nullable();
            $t->timestamp('revealed_at')->nullable();
            $t->string('review_token')->nullable();
            $t->json('snapshot')->nullable();
        });
        Schema::dropIfExists('flashcard_deck_items');
        Schema::dropIfExists('flashcard_decks');
    }
};
