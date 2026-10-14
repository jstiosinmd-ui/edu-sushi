<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Attempt;
use App\Models\FlashcardDeck;
use App\Models\Progress;
use App\Models\Question;
use App\Models\User;
use App\Services\Catalog;
use App\Services\CohortInsights;
use App\Services\Learning;
use App\Services\SubjectMap;
use App\Services\WeightedDeck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyImprovementsTest extends TestCase
{
    use RefreshDatabase;

    private function learner(): User
    {
        $u = User::factory()->create(['username' => 'learner', 'role' => 'student', 'must_change_password' => false]);
        $u->refresh();
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version])->withoutVite();

        return $u;
    }

    private function bank(int $count = 24): array
    {
        // Other test classes seed the release bank once for the shared test
        // transaction baseline. Keep these synthetic fixtures self-contained.
        Question::query()->update(['active' => false]);
        $items = [];
        for ($n = 1; $n <= $count; $n++) {
            $items[] = ['id' => 'QA-'.$n, 'subject' => $n <= 12 ? 'Anatomy' : 'Pathology', 'topic' => 'Topic '.($n % 3), 'tier' => 'Easy',
                'stem' => 'Synthetic test question '.$n, 'options' => ['One', 'Two', 'Three', 'Four'], 'key' => 'A',
                'rationale' => 'Synthetic rationale.', 'expl' => ['Yes', 'No', 'No', 'No'], 'raw' => ['Answer Source' => 'Test fixture']];
        }
        app(Catalog::class)->import(['items' => $items]);

        return app(Catalog::class)->items();
    }

    public function test_today_bento_respects_subjects_time_and_prioritizes_private_practice_history(): void
    {
        $u = $this->learner();
        $items = $this->bank();
        app(Learning::class)->answer($u->id, 'fixture:wrong', $items[0], 'tutor', false);
        app(Learning::class)->answer($u->id, 'fixture:assigned', $items[1], 'assigned', false);
        $settings = ['subjects' => ['Anatomy'], 'minutes' => 5];
        $p = $this->postJson('/today-bento/preview', $settings)->assertOk()->json();
        $this->assertSame(3, $p['count']);
        $this->assertSame(1, $p['missed']);
        $this->assertSame('QA-1', $p['selected_ids'][0]);
        $this->assertSame(['Anatomy' => 3], $p['coverage']);
        $this->assertArrayNotHasKey('key', $p);
        $this->post('/today-bento/start', [...$settings, 'selected_ids' => ['QA-24']])->assertRedirect();
        $a = Attempt::firstOrFail();
        $this->assertSame('tutor', $a->mode);
        $this->assertEqualsCanonicalizing($p['selected_ids'], $a->items->pluck('question_id')->all());
        $this->postJson('/today-bento/preview', ['subjects' => [], 'minutes' => 5])->assertUnprocessable();
        $this->postJson('/today-bento/start', ['subjects' => ['Bogus'], 'minutes' => 60])->assertUnprocessable();
        $this->get('/')->assertOk();
    }

    public function test_memory_spacing_calibration_and_retry_tokens(): void
    {
        $u = $this->learner();
        $items = array_slice($this->bank(), 0, 5);
        $deck = FlashcardDeck::create(['user_id' => $u->id, 'title' => 'Test deck', 'settings' => []]);
        foreach ($items as $q) {
            $deck->items()->create(['question_id' => $q['id'], 'snapshot' => $q]);
        }
        $p = $this->get('/flashcards/decks/'.$deck->id)->assertOk()->viewData('page')['props']['deck'];
        $seen = [];
        for ($i = 0; $i < 4; $i++) {
            $q = $p['question'];
            $this->assertNotContains($q['id'], $seen);
            $seen[] = $q['id'];
            $this->postJson('/flashcards/decks/'.$deck->id.'/answer', ['question_id' => $q['id'], 'answer' => $i ? 'A' : 'B', 'token' => $q['token']])->assertOk();
            $rate = ['question_id' => $q['id'], 'confidence' => 5, 'token' => $q['token']];
            $p = $this->postJson('/flashcards/decks/'.$deck->id.'/rate', $rate)->assertOk()->json('deck');
            $again = $this->postJson('/flashcards/decks/'.$deck->id.'/rate', $rate)->assertOk()->json('deck');
            $this->assertSame($p['reviews'], $again['reviews']);
        }
        $this->assertSame(1, $p['calibration']['incorrect_confident']);
        $this->assertSame(3, $p['calibration']['correct_confident']);
        $this->assertSame(['correct' => 3, 'total' => 4], $p['accuracy']);
        $this->assertTrue($p['goal_prompt']);
        $this->assertSame('only', app(WeightedDeck::class)->choose([['question_id' => 'only', 'confidence' => 5]], recent: ['only']));
    }

    public function test_notebook_keeps_notes_private_and_excludes_assigned_answers(): void
    {
        $u = $this->learner();
        $items = $this->bank();
        app(Learning::class)->answer($u->id, 'missed', $items[0], 'memory', false);
        app(Learning::class)->answer($u->id, 'hidden', $items[1], 'assigned', false);
        $p = $this->get('/progress/notebook')->assertOk()->viewData('page')['props'];
        $this->assertSame(['QA-1'], array_column($p['questions']['data'], 'id'));
        $this->postJson('/questions/QA-1/note', ['reason' => 'confused', 'note' => 'My private takeaway', 'revision' => 0])->assertOk();
        $this->postJson('/questions/QA-1/note', ['note' => 'Stale', 'revision' => 0])->assertConflict();
        app(Learning::class)->answer($u->id, 'recovered', $items[0], 'tutor', true);
        $p = $this->get('/progress/notebook?status=recovered')->assertOk()->viewData('page')['props'];
        $this->assertSame('My private takeaway', $p['questions']['data'][0]['note']);
        $other = User::factory()->create(['username' => 'other', 'must_change_password' => false])->fresh();
        $this->actingAs($other)->withSession(['auth_version' => $other->auth_version]);
        $this->getJson('/questions/QA-1/note')->assertOk()->assertJson(['note' => '', 'revision' => 0]);
        $p = $this->get('/progress/notebook')->assertOk()->viewData('page')['props'];
        $this->assertSame(0, $p['questions']['total']);
    }

    public function test_subject_map_separates_first_accuracy_repeated_answers_and_familiarity(): void
    {
        $u = $this->learner();
        $items = $this->bank();
        app(Learning::class)->answer($u->id, 'wrong', $items[0], 'tutor', false);
        app(Learning::class)->answer($u->id, 'right', $items[0], 'memory', true);
        app(Learning::class)->answer($u->id, 'hidden-assigned', $items[1], 'assigned', true);
        DB::table('flashcards')->insert(['user_id' => $u->id, 'question_id' => $items[0]['id'], 'confidence' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $map = $this->get('/progress')->assertOk()->viewData('page')['props']['subjectMap'];
        $this->assertCount(12, $map);
        $s = collect($map)->firstWhere('subject', 'Anatomy');
        $this->assertSame(12, $s['total']);
        $this->assertSame(1, $s['attempted']);
        $this->assertSame(0, $s['first_correct']);
        $this->assertSame(1, $s['distinct_correct']);
        $this->assertSame(1, $s['confident']);
        Progress::updateOrCreate(['user_id' => $u->id, 'question_id' => 'QA-1'], ['completed_at' => now(), 'history_unknown' => true]);
        $s = collect(app(SubjectMap::class)->for($u->id))->firstWhere('subject', 'Anatomy');
        $this->assertSame(1, $s['attempted']);
        $this->assertSame(0, $s['first_total']);
        $overview = app(CohortInsights::class)->overview(collect([$u]));
        $this->assertSame(1, $overview['rows'][0]['attempted']);
        $this->assertSame(0, $overview['first_total']);
    }

    public function test_reports_preserve_version_history_and_send_owned_in_app_updates(): void
    {
        $u = $this->learner();
        $items = $this->bank();
        $this->post('/library/study', ['mode' => 'tutor', 'selected_ids' => ['QA-1']])->assertRedirect();
        $snapshot = Attempt::first()->items->first()->snapshot;
        $report = $this->postJson('/questions/QA-1/report', ['version' => 1, 'category' => 'typo', 'details' => 'Please check the wording.'])->assertOk()->json('id');
        $this->get('/admin/reports')->assertForbidden();
        $this->postJson('/questions/QA-1/report', ['version' => 99, 'category' => 'typo', 'details' => 'Invalid version.'])->assertNotFound();
        $admin = User::factory()->create(['username' => 'chef', 'role' => 'admin', 'must_change_password' => false])->fresh();
        $this->actingAs($admin)->withSession(['auth_version' => $admin->auth_version]);
        $content = Question::findOrFail('QA-1')->currentVersion->content;
        $content['rationale'] = 'Corrected synthetic explanation.';
        $this->patch('/admin/questions/QA-1', ['content' => $content, 'active' => true])->assertRedirect();
        $this->assertSame($snapshot, Attempt::first()->items->first()->snapshot);
        $this->patch('/admin/reports/'.$report, ['status' => 'resolved', 'resolution' => 'Wording reviewed and explanation updated.', 'revision' => 0])->assertRedirect();
        $this->patchJson('/admin/reports/'.$report, ['status' => 'open', 'resolution' => 'Stale update.', 'revision' => 0])->assertConflict();
        $this->assertDatabaseCount('question_report_updates', 1);
        $this->get('/admin/reports?status=all')->assertOk();
        $notice = DB::table('learning_notices')->where('user_id', $u->id)->first();
        $this->assertNotNull($notice);
        $this->post('/updates/'.$notice->id.'/read')->assertNotFound();
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version]);
        $p = $this->get('/updates')->assertOk()->viewData('page')['props'];
        $this->assertSame(2, $p['notices']['total']);
        $this->post('/updates/'.$notice->id.'/read')->assertRedirect();
        $this->assertNotNull(DB::table('learning_notices')->where('id', $notice->id)->value('read_at'));
    }

    public function test_cohort_statistics_deduplicate_repeats_and_admin_can_assign_focused_practice(): void
    {
        $u = $this->learner();
        $u->update(['cohort' => 'October']);
        $this->bank();
        foreach (['B', 'A'] as $answer) {
            $this->post('/library/study', ['mode' => 'tutor', 'selected_ids' => ['QA-1']])->assertRedirect();
            $a = Attempt::latest('id')->first();
            $this->postJson('/attempts/'.$a->id.'/answer', ['item_id' => $a->items->first()->id, 'answer' => $answer, 'check' => true, 'revision' => 0])->assertOk();
        }
        $this->get('/admin/overview')->assertForbidden();
        $this->post('/admin/learners/'.$u->id.'/practice', [])->assertForbidden();
        $admin = User::factory()->create(['username' => 'chef', 'role' => 'admin', 'must_change_password' => false])->fresh();
        $this->actingAs($admin)->withSession(['auth_version' => $admin->auth_version]);
        $p = $this->get('/admin/overview?cohort=October')->assertOk()->viewData('page')['props'];
        $this->assertSame(1, $p['overview']['learners']);
        $this->assertSame(1, $p['overview']['first_total']);
        $this->assertSame(0, $p['overview']['first_correct']);
        $item = $p['items']['data'][0];
        $this->assertSame(1, $item['n']);
        $this->assertNull($item['percent']);
        $this->assertSame(1, $item['options'][1]['count']);
        $this->post('/admin/learners/'.$u->id.'/practice', ['subject' => 'Anatomy', 'topic' => 'Topic 1', 'count' => 3, 'minutes' => 15, 'days' => 7])->assertRedirect();
        $assignment = Assignment::firstOrFail();
        $this->assertCount(3, $assignment->exam->items);
        $this->assertTrue(collect($assignment->exam->items)->every(fn ($q) => $q['subject'] === 'Anatomy' && $q['topic'] === 'Topic 1'));
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version]);
        $this->post('/assignments/'.$assignment->id.'/start')->assertRedirect();
        $a = Attempt::latest('id')->first();
        $p = $this->get('/attempts/'.$a->id)->assertOk()->viewData('page')['props']['attempt'];
        $this->assertArrayNotHasKey('key', $p['items'][0]);
    }

    public function test_lost_answer_response_can_be_retried_without_double_writing_or_overwriting(): void
    {
        $this->learner();
        $this->bank();
        $this->post('/library/study', ['mode' => 'tutor', 'selected_ids' => ['QA-1']])->assertRedirect();
        $a = Attempt::first();
        $payload = ['item_id' => $a->items->first()->id, 'answer' => 'B', 'check' => false, 'revision' => 0, 'request_token' => (string) Str::uuid()];
        $first = $this->postJson('/attempts/'.$a->id.'/answer', $payload)->assertOk()->json('attempt');
        $again = $this->postJson('/attempts/'.$a->id.'/answer', $payload)->assertOk()->json('attempt');
        $this->assertSame($first['revision'], $again['revision']);
        $this->assertDatabaseCount('answer_revisions', 1);
        $this->assertDatabaseCount('study_answer_receipts', 1);
        $this->postJson('/attempts/'.$a->id.'/answer', [...$payload, 'answer' => 'C'])->assertConflict();
        $this->assertSame('B', $a->items()->first()->answer);
    }

    public function test_quick_decks_use_subject_filtered_personal_history_and_empty_pools_fail_clearly(): void
    {
        $u = $this->learner();
        $items = $this->bank();
        app(Learning::class)->answer($u->id, 'mistake', $items[0], 'memory', false);
        app(Learning::class)->answer($u->id, 'other-subject', $items[13], 'memory', false);
        $this->post('/flashcards/quick', ['pool' => 'missed', 'subjects' => ['Anatomy']])->assertRedirect();
        $deck = FlashcardDeck::firstOrFail();
        $this->assertSame(['QA-1'], $deck->items->pluck('question_id')->all());
        $this->postJson('/flashcards/quick', ['pool' => 'bookmarks', 'subjects' => ['Anatomy']])->assertUnprocessable();
        DB::table('flashcards')->insert(['user_id' => $u->id, 'question_id' => 'QA-2', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/flashcards/quick', ['pool' => 'low', 'subjects' => ['Anatomy']])->assertRedirect();
        $this->assertSame(['QA-2'], FlashcardDeck::latest('id')->first()->items->pluck('question_id')->all());
    }
}
