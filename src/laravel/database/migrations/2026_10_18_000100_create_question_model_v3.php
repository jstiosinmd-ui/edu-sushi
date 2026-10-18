<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $t) {
            $t->string('code', 3)->primary();
            $t->string('name', 80);
            $t->string('prc_name', 120);
            $t->smallInteger('exam_day')->nullable();
            $t->smallInteger('items_per_exam')->nullable();
            $t->smallInteger('display_order');
        });
        Schema::create('topics', function (Blueprint $t) {
            $t->string('code', 7)->primary();
            $t->string('subject_code', 3);
            $t->foreign('subject_code')->references('code')->on('subjects');
            $t->string('name', 120);
            $t->smallInteger('display_order');
            $t->text('label_pattern')->nullable();
            $t->decimal('blueprint_weight', 6, 4)->nullable();
            $t->integer('target_items')->nullable();
            $t->unique(['code', 'subject_code']);
        });
        Schema::create('subtopics', function (Blueprint $t) {
            $t->string('code', 10)->primary();
            $t->string('topic_code', 7);
            $t->foreign('topic_code')->references('code')->on('topics');
            $t->string('name', 160);
            $t->text('keywords')->nullable();
            $t->integer('target_items')->nullable();
            $t->string('status', 10)->default('draft');
            $t->unique(['code', 'topic_code']);
        });
        $taxonomy = json_decode(file_get_contents(database_path('data/v3/taxonomy.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach (['subjects', 'topics', 'subtopics'] as $table) {
            foreach (array_chunk($taxonomy[$table], 200) as $rows) {
                DB::table($table)->insert($rows);
            }
        }
        Schema::create('authorities', function (Blueprint $t) {
            $t->string('id', 80)->primary();
            $t->text('title');
            $t->string('edition', 40)->nullable();
            $t->smallInteger('year')->nullable();
            $t->string('publisher', 160)->nullable();
            $t->string('kind', 12);
            $t->string('mode', 16);
            $t->text('url')->nullable();
            $t->smallInteger('page_offset')->nullable();
            $t->date('registered_on');
            // Legacy citations are carried forward, without claiming new verification.
            $t->string('registration_status', 24)->default('legacy_unverified');
        });
        Schema::table('questions', function (Blueprint $t) {
            $t->string('subject_code', 3)->nullable();
            $t->foreign('subject_code')->references('code')->on('subjects');
            $t->string('topic_code', 7)->nullable();
            $t->foreign(['topic_code', 'subject_code'])->references(['code', 'subject_code'])->on('topics');
            $t->string('subtopic_code', 10)->nullable();
            $t->foreign(['subtopic_code', 'topic_code'])->references(['code', 'topic_code'])->on('subtopics');
            $t->string('concept_key', 80)->nullable();
            $t->string('origin', 20)->nullable();
            $t->string('status', 8)->default('Active');
            $t->string('cognitive', 10)->nullable();
            $t->string('qtype', 8)->nullable();
            $t->index(['subject_code', 'topic_code', 'subtopic_code'], 'questions_taxonomy');
        });
        DB::table('questions')->where('active', false)->update(['status' => 'Retired']);
        DB::statement("ALTER TABLE questions ADD CONSTRAINT questions_status_active CHECK (status IN ('Draft','Active','Held','Retired') AND active = (status = 'Active'))");
        DB::statement('ALTER TABLE questions ADD CONSTRAINT questions_taxonomy_parents CHECK ((topic_code IS NULL OR subject_code IS NOT NULL) AND (subtopic_code IS NULL OR topic_code IS NOT NULL) AND (concept_key IS NULL OR subtopic_code IS NOT NULL))');
        DB::statement("CREATE UNIQUE INDEX questions_concept_unique ON questions (subtopic_code, concept_key) WHERE status = 'Active' AND concept_key IS NOT NULL");
        DB::statement("ALTER TABLE questions ADD CONSTRAINT questions_origin_values CHECK (origin IS NULL OR origin IN ('sushi_conversion','school_conversion','authority_authored'))");
        Schema::create('question_references', function (Blueprint $t) {
            $t->id();
            $t->string('question_id');
            $t->integer('version');
            $t->foreign(['question_id', 'version'])->references(['question_id', 'version'])->on('question_versions');
            $t->string('authority_id', 80);
            $t->foreign('authority_id')->references('id')->on('authorities');
            $t->text('citation');
            $t->text('locator');
            $t->text('url')->nullable();
            $t->date('accessed_on')->nullable();
            $t->string('supports', 12)->default('key');
            $t->string('verification_status', 24)->default('legacy_unverified');
        });
        Schema::create('question_media', function (Blueprint $t) {
            $t->string('id', 80)->primary();
            $t->string('question_id');
            $t->integer('version');
            $t->foreign(['question_id', 'version'])->references(['question_id', 'version'])->on('question_versions');
            $t->string('role', 12);
            $t->string('path', 300);
            $t->text('alt');
            $t->string('credit', 300)->nullable();
            $t->string('license', 20);
            $t->boolean('required_for_answer')->default(false);
        });
        Schema::create('release_items', function (Blueprint $t) {
            $t->string('release_id');
            $t->foreign('release_id')->references('id')->on('bank_releases');
            $t->string('question_id');
            $t->integer('version');
            $t->foreign(['question_id', 'version'])->references(['question_id', 'version'])->on('question_versions');
            $t->primary(['release_id', 'question_id']);
        });
        Schema::create('exam_families', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->string('label', 200)->nullable();
            $t->integer('files');
        });
        Schema::create('source_files', function (Blueprint $t) {
            $t->char('sha1', 40)->primary();
            $t->text('path');
            $t->json('copy_paths')->nullable();
            $t->string('ext', 8);
            $t->bigInteger('bytes');
            $t->string('class', 24);
            $t->string('license_class', 16);
            $t->string('institution', 40)->nullable();
            $t->smallInteger('year')->nullable();
            $t->integer('pages')->nullable();
            $t->string('text_layer', 12);
            $t->bigInteger('family_id')->nullable();
            $t->foreign('family_id')->references('id')->on('exam_families');
            $t->integer('q_estimate')->nullable();
        });
        Schema::create('source_groups', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->string('origin', 10);
            $t->integer('families');
            $t->integer('recurrence');
            $t->string('subject_code', 3)->nullable();
            $t->foreign('subject_code')->references('code')->on('subjects');
            $t->decimal('p_subject', 4, 3)->nullable();
            $t->string('topic_code', 7)->nullable();
            $t->foreign(['topic_code', 'subject_code'])->references(['code', 'subject_code'])->on('topics');
            $t->decimal('p_topic', 4, 3)->nullable();
            $t->string('subtopic_code', 10)->nullable();
            $t->foreign(['subtopic_code', 'topic_code'])->references(['code', 'topic_code'])->on('subtopics');
            $t->boolean('keyed')->default(false);
            $t->string('conversion_status', 12)->default('unconverted');
            $t->string('exclusion_reason', 200)->nullable();
            $t->index(['subtopic_code', 'origin', 'conversion_status']);
        });
        Schema::create('source_questions', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->char('sha1', 40);
            $t->foreign('sha1')->references('sha1')->on('source_files');
            $t->integer('ordinal');
            $t->text('stem');
            $t->json('options');
            $t->char('source_key', 1)->nullable();
            $t->bigInteger('group_id');
            $t->foreign('group_id')->references('id')->on('source_groups');
            $t->unique(['sha1', 'ordinal']);
        });
        Schema::create('question_sources', function (Blueprint $t) {
            $t->string('question_id');
            $t->foreign('question_id')->references('id')->on('questions');
            $t->bigInteger('group_id');
            $t->foreign('group_id')->references('id')->on('source_groups');
            $t->string('role', 12);
            $t->primary(['question_id', 'group_id']);
        });
        Schema::create('question_taxonomy_changes', function (Blueprint $t) {
            $t->id();
            $t->string('release_id');
            $t->foreign('release_id')->references('id')->on('bank_releases');
            $t->string('question_id');
            $t->foreign('question_id')->references('id')->on('questions');
            $t->foreignId('previous_version_id')->constrained('question_versions');
            $t->foreignId('applied_version_id')->constrained('question_versions');
            $t->json('mapping');
            $t->timestamp('created_at');
            $t->unique(['release_id', 'question_id']);
        });
        DB::statement("ALTER TABLE source_groups ADD CONSTRAINT source_group_values CHECK (origin IN ('school','commercial','reference') AND families >= 0 AND recurrence >= 0 AND conversion_status IN ('unconverted','queued','converted','excluded','held') AND (topic_code IS NULL OR subject_code IS NOT NULL) AND (subtopic_code IS NULL OR topic_code IS NOT NULL))");
        DB::statement("ALTER TABLE question_sources ADD CONSTRAINT question_source_roles CHECK (role IN ('revised_from','topic_map'))");
        DB::statement("ALTER TABLE source_files ADD CONSTRAINT source_file_licenses CHECK (license_class IN ('convertible','topic_map_only','reference','admin'))");
        DB::statement("ALTER TABLE question_media ADD CONSTRAINT question_media_values CHECK (role IN ('stem','explanation') AND length(alt) >= 10 AND license IN ('original','public-domain','cc-by','cc-by-sa','permission-on-file'))");
        DB::statement("CREATE VIEW v_subtopic_coverage AS
            SELECT s.code AS subtopic_code, s.target_items,
            (SELECT COUNT(*) FROM questions q WHERE q.subtopic_code=s.code AND q.status='Active') AS active_items,
            (SELECT COUNT(*) FROM source_groups g WHERE g.subtopic_code=s.code AND g.origin='school') AS school_sources,
            (SELECT COUNT(*) FROM source_groups g WHERE g.subtopic_code=s.code AND g.origin='school' AND g.families>=2) AS school_sources_repeated,
            (SELECT COUNT(*) FROM source_groups g WHERE g.subtopic_code=s.code AND g.origin='school' AND g.conversion_status='unconverted') AS school_unconverted
            FROM subtopics s");
        DB::statement('CREATE VIEW v_learner_subtopic_mastery AS
            SELECT a.user_id, q.subtopic_code, COUNT(*) AS answered,
            SUM(CASE WHEN ai.correct THEN 1 ELSE 0 END) AS correct
            FROM attempt_items ai JOIN attempts a ON a.id=ai.attempt_id JOIN questions q ON q.id=ai.question_id
            WHERE ai.graded_at IS NOT NULL AND ai.answer IS NOT NULL AND q.subtopic_code IS NOT NULL GROUP BY a.user_id,q.subtopic_code');
    }

    public function down(): void
    {
        if (DB::table('question_taxonomy_changes')->exists()) {
            throw new RuntimeException('Retagged versions exist. Restore the pre-migration database backup before rolling back this schema.');
        }
        DB::statement('DROP VIEW IF EXISTS v_learner_subtopic_mastery');
        DB::statement('DROP VIEW IF EXISTS v_subtopic_coverage');
        foreach (['question_taxonomy_changes', 'question_sources', 'source_questions', 'source_groups', 'source_files', 'exam_families', 'release_items', 'question_media', 'question_references'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP INDEX IF EXISTS questions_concept_unique');
        DB::statement('ALTER TABLE questions DROP CONSTRAINT IF EXISTS questions_status_active, DROP CONSTRAINT IF EXISTS questions_taxonomy_parents, DROP CONSTRAINT IF EXISTS questions_origin_values');
        Schema::table('questions', function (Blueprint $t) {
            $t->dropForeign(['subtopic_code', 'topic_code']);
            $t->dropForeign(['topic_code', 'subject_code']);
            $t->dropForeign(['subject_code']);
            $t->dropIndex('questions_taxonomy');
            $t->dropColumn(['subject_code', 'topic_code', 'subtopic_code', 'concept_key', 'origin', 'status', 'cognitive', 'qtype']);
        });
        foreach (['authorities', 'subtopics', 'topics', 'subjects'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
