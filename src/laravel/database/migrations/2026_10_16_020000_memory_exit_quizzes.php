<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_exit_quizzes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('flashcard_deck_id')->constrained();
            $t->unsignedInteger('session_number');
            $t->jsonb('items');
            $t->jsonb('answers')->nullable();
            $t->unsignedInteger('score')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->unique(['flashcard_deck_id', 'session_number']);
        });
    }

    public function down(): void {}
};
