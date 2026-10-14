<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_answer_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('request_token', 36);
            $t->string('fingerprint', 64);
            $t->timestamp('created_at');
            $t->unique(['user_id', 'request_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_answer_receipts');
    }
};
