<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->json('study_preferences')->nullable());
        Schema::create('catalog_revisions', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(1);
        });
        DB::table('catalog_revisions')->insert(['id' => 1, 'revision' => 1]);
        // Statement triggers cover imports, retirement and bulk taxonomy updates.
        // The increment commits or rolls back with the catalog write.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sushi_catalog_changed() RETURNS trigger AS $$
            BEGIN
                UPDATE catalog_revisions SET revision = revision + 1 WHERE id = 1;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER sushi_catalog_revision
                AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON questions
                FOR EACH STATEMENT EXECUTE FUNCTION sushi_catalog_changed();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sushi_catalog_revision ON questions; DROP FUNCTION IF EXISTS sushi_catalog_changed();');
        Schema::dropIfExists('catalog_revisions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('study_preferences'));
    }
};
