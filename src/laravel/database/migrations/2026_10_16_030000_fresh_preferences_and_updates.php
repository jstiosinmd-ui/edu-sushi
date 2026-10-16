<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedTinyInteger('fresh_unseen')->default(7);
            $t->boolean('fresh_balance')->default(true);
        });
        Schema::create('feature_update_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('release');
            $t->timestamp('read_at');
            $t->unique(['user_id', 'release']);
        });
    }

    public function down(): void {}
};
