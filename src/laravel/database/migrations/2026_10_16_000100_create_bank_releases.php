<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_releases', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->timestamp('applied_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_releases');
    }
};
