<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flashcards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('question_id');
            $t->unsignedTinyInteger('confidence')->nullable();
            $t->unsignedInteger('reviews')->default(0);
            $t->unsignedInteger('interval_seconds')->default(0);
            $t->timestamp('due_at')->nullable();
            $t->timestamp('revealed_at')->nullable();
            $t->string('review_token')->nullable();
            $t->json('snapshot')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'question_id']);
        });
        Schema::create('flashcard_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('question_id');
            $t->unsignedTinyInteger('confidence');
            $t->unsignedInteger('interval_seconds');
            $t->string('review_token')->unique();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flashcard_reviews');
        Schema::dropIfExists('flashcards');
    }
};
