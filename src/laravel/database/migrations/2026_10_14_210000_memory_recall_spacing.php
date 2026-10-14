<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flashcard_decks', fn (Blueprint $t) => $t->json('recent_question_ids')->nullable());
    }

    public function down(): void
    {
        Schema::table('flashcard_decks', fn (Blueprint $t) => $t->dropColumn('recent_question_ids'));
    }
};
