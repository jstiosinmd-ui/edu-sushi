<?php

namespace Tests\Feature;

use App\Models\Progress;
use App\Models\Question;
use App\Models\QuestionVersion;
use App\Models\User;
use App\Services\Catalog;
use App\Services\QuestionContentV3;
use App\Services\RetagQuestionsV3;
use App\Services\Study;
use App\Services\Taxonomy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaxonomyV3Test extends TestCase
{
    use RefreshDatabase;

    public function test_taxonomy_is_complete_and_targets_are_unset(): void
    {
        $migration = require database_path('migrations/2026_10_18_000100_create_question_model_v3.php');
        $availability = require database_path('migrations/2026_10_18_000200_sync_question_availability.php');
        $availability->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('topics'));
        $migration->up();
        $availability->up();
        $this->assertSame(12, DB::table('subjects')->count());
        $this->assertSame(175, DB::table('topics')->count());
        $this->assertSame(790, DB::table('subtopics')->count());
        $this->assertSame(0, DB::table('subtopics')->whereNotNull('target_items')->count());
        $this->assertSame(790, DB::table('v_subtopic_coverage')->count());
    }

    public function test_retag_is_complete_idempotent_and_preserves_existing_versions(): void
    {
        $this->seed();
        $user = User::where('role', 'student')->firstOrFail();
        $user->update(['must_change_password' => false]);
        $settings = ['mode' => 'tutor', 'count' => 1, 'seed' => 1, 'subjects' => ['Anatomy'], 'topics' => [],
            'difficulty' => 'All', 'balance' => false, 'pool' => 'all', 'minutes' => 30, 'selected_ids' => ['ANA-Q0001']];
        $attempt = app(Study::class)->start($user, $settings);
        $item = $attempt->items()->first();
        app(Study::class)->answer($attempt, $item->id, ['answer' => $item->snapshot['key'], 'check' => true, 'revision' => 0]);
        Progress::where('user_id', $user->id)->where('question_id', $item->question_id)->update(['bookmarked' => true]);
        $skipped = app(Study::class)->start($user, [...$settings, 'selected_ids' => ['ANA-Q0002']]);
        app(Study::class)->finish($skipped);
        $this->assertNull($skipped->items()->first()->answer);
        $this->assertNotNull($skipped->items()->first()->graded_at);
        $protected = [];
        foreach (['attempts', 'attempt_items', 'progress', 'study_events', 'users'] as $table) {
            $protected[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        Question::findOrFail('ANA-Q0500')->update(['active' => false]);
        $before = Question::with('currentVersion')->get()->keyBy('id');
        $versionCount = DB::table('question_versions')->count();
        $preview = app(RetagQuestionsV3::class)->run();
        $this->assertSame(6000, $preview['to_retag']);
        $this->assertSame(0, DB::table('authorities')->count());
        $this->assertSame($versionCount, DB::table('question_versions')->count());
        $this->assertSame(6000, app(RetagQuestionsV3::class)->run(true)['to_retag']);
        $this->assertSame(6000, Question::whereNotNull('topic_code')->count());
        $this->assertSame(6000, DB::table('release_items')->count());
        $this->assertSame(6000, DB::table('question_taxonomy_changes')->count());
        $this->assertSame(0, DB::table('learning_notices')->count());
        $this->assertSame(6000, Question::count());
        foreach ($protected as $table => $json) {
            $this->assertSame($json, DB::table($table)->orderBy('id')->get()->toJson(), $table.' changed');
        }
        $this->assertFalse(Question::findOrFail('ANA-Q0500')->active);
        $mastery = DB::table('v_learner_subtopic_mastery')->where('user_id', $user->id);
        $this->assertSame(1, (int) (clone $mastery)->sum('answered'));
        $this->assertSame(1, (int) (clone $mastery)->sum('correct'));
        $originals = QuestionVersion::whereIn('id', $before->pluck('current_version_id'))->get()->keyBy('id');
        foreach (Question::with('currentVersion')->get() as $question) {
            $old = $before[$question->id]->currentVersion;
            $this->assertSame($old->content, $originals[$old->id]->content);
            foreach (['id', 'stem', 'options', 'key', 'rationale', 'expl', 'tier'] as $key) {
                $this->assertSame($old->content[$key], $question->currentVersion->content[$key]);
            }
            $this->assertSame($old->version + 1, $question->currentVersion->version);
        }
        $this->assertSame('IM.01', Question::findOrFail('IM-Q0338')->topic_code);
        $this->assertSame('IM.09', Question::findOrFail('IM-Q0464')->topic_code);
        $this->assertSame('BCH.09', Question::findOrFail('MPA-Q0097')->topic_code);
        $this->assertSame('Biochemistry', Question::findOrFail('MPA-Q0097')->subject);
        $this->assertSame(0, app(RetagQuestionsV3::class)->run(true)['to_retag']);
        $this->assertSame($versionCount + 6000, DB::table('question_versions')->count());
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version])->withoutVite();
        $this->postJson('/study/available', [...$settings, 'topics' => ['ANA.01']])->assertOk();
        $this->postJson('/flashcards/preview', ['subjects' => ['Anatomy'], 'topics' => ['ANA.01'], 'topic_scope' => 'custom',
            'difficulty' => 'All', 'range_start' => 1, 'range_end' => 3])->assertOk()->assertJsonCount(3, 'questions');
    }

    private function content(): array
    {
        $bank = json_decode(file_get_contents(database_path('data/bank.json')), true);
        $q = $bank['items'][0];
        $q = app(QuestionContentV3::class)->convert($q, Taxonomy::mappings()[$q['id']], true);
        foreach ($q['references'] as $ref) {
            DB::table('authorities')->insertOrIgnore(['id' => $ref['authority_id'], 'title' => $ref['authority_id'],
                'kind' => 'web', 'mode' => 'current_practice', 'registered_on' => '2026-10-18']);
        }

        return $q;
    }

    public function test_held_items_do_not_enter_drills_and_legacy_import_cannot_downgrade_v3(): void
    {
        $q = $this->content();
        $q['review']['status'] = $q['status'] = 'Held';
        app(Catalog::class)->import(['items' => [$q]]);
        $this->assertNotContains($q['id'], array_column(app(Catalog::class)->items(), 'id'));
        $this->assertFalse(Question::findOrFail($q['id'])->active);
        $legacy = json_decode(file_get_contents(database_path('data/bank.json')), true)['items'][0];
        $this->expectException(ValidationException::class);
        app(Catalog::class)->import(['items' => [$legacy]]);
    }

    public function test_wrong_taxonomy_is_rejected(): void
    {
        $q = $this->content();
        $q['topic_code'] = 'ANA.01';
        $this->expectException(ValidationException::class);
        app(Catalog::class)->import(['items' => [$q]]);
    }

    public function test_unregistered_authority_is_rejected(): void
    {
        $q = $this->content();
        $q['references'][0]['authority_id'] = 'UNKNOWN-AUTHORITY';
        $this->expectException(ValidationException::class);
        app(Catalog::class)->import(['items' => [$q]]);
    }

    public function test_new_legacy_imports_are_rejected_after_the_v3_release(): void
    {
        DB::table('bank_releases')->insert(['id' => RetagQuestionsV3::RELEASE, 'applied_at' => now()]);
        $legacy = json_decode(file_get_contents(database_path('data/bank.json')), true)['items'][0];
        $legacy['id'] = 'IM-Q9000';
        $this->expectException(ValidationException::class);
        app(Catalog::class)->import(['items' => [$legacy]]);
    }

    public function test_commercial_groups_cannot_be_used_as_conversion_sources(): void
    {
        $q = $this->content();
        DB::table('source_groups')->insert(['id' => 99, 'origin' => 'commercial', 'families' => 1, 'recurrence' => 1]);
        $q['lineage']['origin'] = 'school_conversion';
        $q['lineage']['source_groups'] = ['99'];
        $this->expectException(ValidationException::class);
        app(Catalog::class)->import(['items' => [$q]]);
    }

    public function test_retag_rejects_incomplete_or_changed_banks_without_partial_writes(): void
    {
        $legacy = json_decode(file_get_contents(database_path('data/bank.json')), true)['items'][0];
        $legacy['stem'] .= ' A curator changed this item.';
        app(Catalog::class)->import(['items' => [$legacy]]);
        $this->artisan('sushi:retag-v3 --apply')->assertExitCode(1);
        $this->assertSame(0, DB::table('question_taxonomy_changes')->count());
        $this->assertNull(Question::first()->topic_code);
        $this->assertSame(0, DB::table('authorities')->count());
    }

    public function test_active_concept_duplicates_are_blocked_by_database(): void
    {
        $q = $this->content();
        $q['subtopic_code'] = 'IM.10.06';
        $q['concept_key'] = 'stable-concept-test';
        app(Catalog::class)->import(['items' => [$q]]);
        $q['id'] = 'IM-Q9000';
        $this->expectException(UniqueConstraintViolationException::class);
        app(Catalog::class)->import(['items' => [$q]]);
    }

    public function test_public_questions_hide_internal_lineage_and_keep_codes(): void
    {
        $q = $this->content();
        $public = Catalog::publicQuestion($q, true);
        $this->assertSame($q['topic_code'], $public['topic_code']);
        foreach (['raw', 'lineage', 'references', 'review', 'source_groups'] as $key) {
            $this->assertArrayNotHasKey($key, $public);
        }
        $this->assertTrue(Taxonomy::matches($q, $q['topic_code']));
        $this->assertTrue(Taxonomy::matches($q, 'Internal Medicine'."\x1f".Taxonomy::mappings()[$q['id']]['legacy_topic']));
    }
}
