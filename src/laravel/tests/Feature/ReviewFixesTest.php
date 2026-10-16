<?php

namespace Tests\Feature;

use App\Jobs\SendCatchReminder;
use App\Models\Attempt;
use App\Models\ExamDraft;
use App\Models\FlashcardDeck;
use App\Models\Progress;
use App\Models\Question;
use App\Models\User;
use App\Services\Achievements;
use App\Services\Analytics;
use App\Services\Catalog;
use App\Services\CatchReminders;
use App\Services\CohortInsights;
use App\Services\Study;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Other suites seed the full bank once. Isolate this fixture inside the
        // test transaction, so it behaves identically alone and in the suite.
        User::query()->update(['disabled' => true]);
        Question::query()->update(['active' => false]);
    }

    private function learner(string $role = 'student'): User
    {
        $u = User::factory()->create(['username' => 'fix-'.Str::random(12), 'role' => $role, 'must_change_password' => false])->refresh();
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version])->withoutVite();

        return $u;
    }

    private function bank(int $count = 12): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['id' => 'FIX-'.$i, 'subject' => 'Anatomy', 'topic' => 'Topic '.$i,
                'tier' => $i % 2 ? 'Easy' : 'Difficult', 'stem' => 'Synthetic regression question '.$i,
                'options' => ['One', 'Two', 'Three', 'Four'], 'key' => 'A', 'rationale' => 'Synthetic explanation',
                'expl' => ['Yes', 'No', 'No', 'No'], 'raw' => ['Answer Source' => 'Synthetic regression fixture']];
        }
        app(Catalog::class)->import(['items' => $rows]);

        return app(Catalog::class)->items();
    }

    private function deck(User $u, array $q): FlashcardDeck
    {
        $deck = FlashcardDeck::create(['user_id' => $u->id, 'title' => 'Regression deck', 'settings' => []]);
        $deck->items()->create(['question_id' => $q['id'], 'snapshot' => $q]);

        return $deck;
    }

    private function memoryAnswer(FlashcardDeck $deck, string $answer): array
    {
        $card = $this->get('/flashcards/decks/'.$deck->id)->assertOk()->viewData('page')['props']['deck']['question'];
        $this->postJson('/flashcards/decks/'.$deck->id.'/answer', ['question_id' => $card['id'], 'token' => $card['token'], 'answer' => $answer])->assertOk();

        return $card;
    }

    private function tutorAnswer(User $u, array $q, string $answer): void
    {
        $a = app(Study::class)->start($u, ['mode' => 'tutor', 'count' => 1, 'seed' => 8, 'subjects' => ['Anatomy'], 'selected_ids' => [$q['id']], 'difficulty' => 'All']);
        $a = app(Study::class)->answer($a, $a->items()->first()->id, ['answer' => $answer, 'check' => true, 'revision' => 0]);
        app(Study::class)->finish($a);
    }

    private function missed(User $u): array
    {
        return array_column(app(Study::class)->eligible($u->id, ['mode' => 'tutor', 'pool' => 'missed']), 'id');
    }

    public function test_memory_answers_count_immediately_and_repeated_ratings_do_not_double_count(): void
    {
        $u = $this->learner();
        $q = $this->bank()[0];
        $this->travelTo(Carbon::parse('2026-10-16 10:00:00', 'Asia/Manila'));
        $deck = $this->deck($u, $q);
        $card = $this->memoryAnswer($deck, 'B');
        $stats = app(Analytics::class)->for($u->id);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(1, $stats['active_completed']);
        $this->assertSame(1, $stats['graded_total']);
        $this->assertSame(0, $stats['graded_correct']);
        $this->assertSame(1, $stats['first_total']);
        $this->assertSame(1, $stats['coverage']->firstWhere('subject', 'Anatomy')['completed']);
        $this->assertContains($q['id'], $this->missed($u));
        $this->assertNotContains($q['id'], array_column(app(Study::class)->eligible($u->id, ['pool' => 'new']), 'id'));

        $rate = ['question_id' => $q['id'], 'token' => $card['token'], 'confidence' => 1];
        $this->postJson('/flashcards/decks/'.$deck->id.'/rate', $rate)->assertOk();
        $this->postJson('/flashcards/decks/'.$deck->id.'/rate', $rate)->assertOk();
        $this->assertSame(1, app(Analytics::class)->for($u->id)['graded_total']);
        // Same second: study event order must settle ties across presentations.
        $this->memoryAnswer($deck, 'A');
        $stats = app(Analytics::class)->for($u->id);
        $this->assertSame(2, $stats['graded_total']);
        $this->assertSame(1, $stats['graded_correct']);
        $this->assertSame(0, $stats['first_correct']);
        $this->assertSame(1, $stats['latest_correct']);
        $this->assertSame(1, $stats['completed']);
        $this->assertNotContains($q['id'], $this->missed($u));
        $this->assertSame(1, Achievements::for($u->id)['count']);
    }

    public function test_latest_answers_follow_cross_mode_order_and_first_accuracy_precedes_filters(): void
    {
        $u = $this->learner();
        $q = $this->bank()[0];
        $this->travelTo(Carbon::parse('2026-10-15 23:59:00', 'Asia/Manila'));
        $this->tutorAnswer($u, $q, 'B');
        Progress::where('user_id', $u->id)->update(['bookmarked' => true]);
        $this->travel(2)->minutes();
        $this->memoryAnswer($this->deck($u, $q), 'A');
        $this->assertNotContains($q['id'], $this->missed($u));
        $stats = $this->get('/progress?mode=memory&subject=Anatomy&topic=Topic%201&difficulty=Easy&from=2026-10-16&to=2026-10-16')
            ->assertOk()->viewData('page')['props']['stats'];
        $this->assertSame(1, $stats['graded_total']);
        $this->assertSame(1, $stats['graded_correct']);
        $this->assertSame(0, $stats['first_total']);
        $this->assertSame(0, app(Analytics::class)->for($u->id, ['mode' => 'memory', 'to' => '2026-10-15'])['graded_total']);
        $this->assertSame(0, app(Analytics::class)->for($u->id, ['topic' => 'Different'])['graded_total']);
        $this->assertContains($q['id'], array_column(app(Study::class)->eligible($u->id, ['pool' => 'bookmarks']), 'id'));
        $this->tutorAnswer($u, $q, 'B');
        $this->assertContains($q['id'], $this->missed($u));
        $stats = app(Analytics::class)->for($u->id);
        $this->assertSame(0, $stats['latest_correct']);
        $this->assertSame(3, $stats['graded_total']);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame([0, 0], DB::table('attempts')->where('user_id', $u->id)->orderBy('id')->pluck('score')->all());
    }

    public function test_historical_memory_reviews_work_without_events_and_preserve_unknown_import_history(): void
    {
        $u = $this->learner();
        $q = $this->bank()[0];
        $deck = $this->deck($u, $q);
        $item = $deck->items()->first();
        $token = (string) Str::uuid();
        $item->update(['answer' => 'A', 'correct' => true, 'review_token' => $token, 'confidence' => 5]);
        DB::table('flashcard_reviews')->insert(['user_id' => $u->id, 'question_id' => $q['id'], 'flashcard_deck_item_id' => $item->id,
            'answer' => 'A', 'correct' => true, 'review_token' => $token, 'confidence' => 5, 'created_at' => now(), 'updated_at' => now()]);
        Progress::create(['user_id' => $u->id, 'question_id' => $q['id'], 'completed_at' => now()->subDay(), 'latest_correct' => false,
            'legacy' => true, 'history_unknown' => true, 'bookmarked' => true]);
        $stats = app(Analytics::class)->for($u->id);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(1, $stats['graded_total']);
        $this->assertSame(1, $stats['latest_correct']);
        $this->assertSame(0, $stats['first_total']);
        $this->assertNotContains($q['id'], $this->missed($u));
        $this->assertDatabaseHas('progress', ['user_id' => $u->id, 'question_id' => $q['id'], 'legacy' => true, 'latest_correct' => false, 'bookmarked' => true]);
    }

    public function test_older_attempts_without_events_keep_numeric_order_when_timestamps_tie(): void
    {
        $u = $this->learner();
        $q = $this->bank()[0];
        $this->travelTo(Carbon::parse('2026-10-16 10:00:00', 'Asia/Manila'));
        foreach ([9999999 => 'A', 10000000 => 'B'] as $id => $answer) {
            $attempt = Attempt::create(['user_id' => $u->id, 'mode' => 'tutor', 'settings' => [], 'status' => 'finished']);
            $attempt->items()->create(['id' => $id, 'question_id' => $q['id'], 'position' => 0, 'snapshot' => $q,
                'answer' => $answer, 'correct' => $answer === 'A', 'graded_at' => now()]);
        }
        $stats = app(Analytics::class)->for($u->id);
        $this->assertSame(1, $stats['first_correct']);
        $this->assertSame(0, $stats['latest_correct']);
        $this->assertSame(2, $stats['graded_total']);
    }

    private function preview(array $blueprint, array $extra = []): array
    {
        return $this->postJson('/exams/preview', [...['title' => 'Quota test', 'mode' => 'tutor', 'count' => array_sum(array_column($blueprint, 'count')),
            'seed' => 8, 'subjects' => ['Anatomy'], 'topics' => [], 'difficulty' => 'All', 'minutes' => 30, 'blueprint' => $blueprint], ...$extra])->assertOk()->json();
    }

    public function test_broad_quota_replacement_accepts_a_different_topic_and_difficulty(): void
    {
        $this->learner();
        $this->bank(2);
        $draft = $this->preview([['subject' => 'Anatomy', 'topic' => '', 'difficulty' => 'All', 'count' => 1]]);
        $new = $this->postJson('/exam-drafts/'.$draft['id'].'/replace', ['position' => 0, 'revision' => 0])->assertOk()->assertJsonPath('revision', 1)->json();
        $this->assertNotSame($draft['items'][0]['id'], $new['items'][0]['id']);
        $this->assertNotSame($draft['items'][0]['topic'], $new['items'][0]['topic']);
        $this->assertNotSame($draft['items'][0]['tier'], $new['items'][0]['tier']);
        $this->postJson('/exam-drafts/'.$draft['id'].'/replace', ['position' => 0, 'revision' => 0])->assertConflict();
        $this->learner();
        $this->postJson('/exam-drafts/'.$draft['id'].'/replace', ['position' => 0, 'revision' => 1])->assertForbidden();
    }

    public function test_overlapping_quota_rows_can_reassign_slots_without_changing_other_items(): void
    {
        $u = $this->learner();
        $items = $this->bank(3);
        $items[1]['topic'] = $items[0]['topic'];
        app(Catalog::class)->import(['items' => $items]);
        $items = app(Catalog::class)->items();
        $rows = [['subject' => 'Anatomy', 'topic' => $items[0]['topic'], 'difficulty' => 'All', 'count' => 1],
            ['subject' => 'Anatomy', 'topic' => '', 'difficulty' => 'Easy', 'count' => 1]];
        $draft = ExamDraft::create(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'title' => 'Overlap',
            'settings' => ['mode' => 'tutor', 'subjects' => ['Anatomy'], 'topics' => [], 'difficulty' => 'All', 'blueprint' => $rows],
            'items' => [$items[0], $items[1]], 'expires_at' => now()->addHour()]);
        $unchanged = $draft->fresh()->items[1];
        $this->postJson('/exam-drafts/'.$draft->id.'/replace', ['position' => 0, 'revision' => 0])->assertOk()
            ->assertJsonPath('items.0.id', $items[2]['id'])->assertJsonPath('items.1.id', $items[1]['id']);
        $this->assertSame($unchanged, $draft->fresh()->items[1]);
    }

    public function test_narrow_quotas_and_global_filters_still_reject_invalid_replacements(): void
    {
        $this->learner();
        $q = $this->bank(2)[0];
        foreach ([
            [[['subject' => 'Anatomy', 'topic' => $q['topic'], 'difficulty' => 'Easy', 'count' => 1]], []],
            [[['subject' => 'Anatomy', 'topic' => '', 'difficulty' => 'All', 'count' => 1]], ['topics' => ['Anatomy'."\x1f".$q['topic']]]],
        ] as [$rows, $filters]) {
            $draft = $this->preview($rows, $filters);
            $this->postJson('/exam-drafts/'.$draft['id'].'/replace', ['position' => 0, 'revision' => 0])
                ->assertUnprocessable()->assertJsonValidationErrors('replacement');
            $this->assertSame(0, ExamDraft::find($draft['id'])->revision);
        }
    }

    private function pushReady(User $u, array $extra = []): void
    {
        Queue::fake();
        config(['sushi.push.public_key' => 'fixture', 'sushi.push.private_key' => 'fixture']);
        $u->update([...['reminder_enabled' => true, 'reminder_time' => '19:00', 'timezone' => 'Asia/Manila', 'last_active_at' => null], ...$extra]);
        DB::table('push_subscriptions')->insert(['user_id' => $u->id, 'endpoint_hash' => hash('sha256', (string) $u->id),
            'subscription' => 'synthetic', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_yesterdays_snooze_does_not_bypass_todays_reminder_time(): void
    {
        $u = $this->learner();
        $this->bank();
        $this->travelTo(Carbon::parse('2026-10-16 08:00', 'Asia/Manila'));
        $this->pushReady($u, ['reminder_snoozed_until' => now()->subHours(12)]);
        $this->assertSame(0, app(CatchReminders::class)->dispatchDue());
        Queue::assertNothingPushed();
        $this->travel(11)->hours();
        $this->assertSame(1, app(CatchReminders::class)->dispatchDue());
        $this->assertSame(0, app(CatchReminders::class)->dispatchDue());
        $this->assertDatabaseHas('catch_reminders', ['user_id' => $u->id, 'day' => '2026-10-16', 'status' => 'queued']);
    }

    public function test_active_and_cross_midnight_snoozes_follow_the_learners_local_day(): void
    {
        $u = $this->learner();
        $this->bank();
        $this->travelTo(Carbon::parse('2026-10-15 23:45', 'America/New_York'));
        $this->pushReady($u, ['timezone' => 'America/New_York', 'reminder_snoozed_until' => now()->addHour()]);
        $this->assertSame(0, app(CatchReminders::class)->dispatchDue());
        $this->travel(61)->minutes();
        $this->assertSame(1, app(CatchReminders::class)->dispatchDue());
        $this->assertDatabaseHas('catch_reminders', ['user_id' => $u->id, 'day' => '2026-10-16']);
        $this->travelTo(Carbon::parse('2026-10-17 08:00', 'America/New_York'));
        $this->assertSame(0, app(CatchReminders::class)->dispatchDue());
    }

    public function test_admins_receive_reminders_but_ineligible_or_opted_out_accounts_do_not(): void
    {
        $this->bank();
        $this->travelTo(Carbon::parse('2026-10-16 20:00', 'Asia/Manila'));
        $admin = $this->learner('admin');
        $this->pushReady($admin);
        foreach ([['disabled' => true], ['invitation_pending' => true], ['archived_at' => now()], ['reminder_enabled' => false]] as $extra) {
            $this->pushReady($this->learner(), $extra);
        }
        $this->assertSame(1, app(CatchReminders::class)->dispatchDue());
        Queue::assertPushed(SendCatchReminder::class, 1);
        $this->assertDatabaseHas('catch_reminders', ['user_id' => $admin->id, 'status' => 'queued']);
        $this->assertSame(0, app(CatchReminders::class)->dispatchDue());
    }

    public function test_pending_archived_and_disabled_learners_are_excluded_from_cohort_alerts_and_assignments(): void
    {
        $active = $this->learner();
        $active->update(['cohort' => 'Active group', 'last_active_at' => null]);
        $excluded = [];
        foreach ([['invitation_pending' => true], ['archived_at' => now()], ['disabled' => true]] as $extra) {
            $u = $this->learner();
            $u->update([...$extra, 'cohort' => 'Excluded group', 'last_active_at' => null]);
            $excluded[] = $u;
        }
        $this->learner('admin');
        $this->bank();
        $insights = app(CohortInsights::class);
        $this->assertSame([$active->id], $insights->learners()->pluck('id')->all());
        $data = $this->get('/admin/overview')->assertOk()->viewData('page')['props'];
        $this->assertSame(1, $data['overview']['learners']);
        $this->assertSame(['Active group'], $data['cohorts']);
        $this->assertContains('No recorded study activity in the past 7 days', $data['overview']['rows'][0]['support']);
        foreach ($excluded as $u) {
            $this->postJson('/admin/learners/'.$u->id.'/practice', ['subject' => 'Anatomy', 'count' => 1, 'minutes' => 5, 'days' => 1])->assertUnprocessable();
        }
        $this->post('/admin/learners/'.$active->id.'/practice', ['subject' => 'Anatomy', 'count' => 1, 'minutes' => 5, 'days' => 1])->assertRedirect();
        $this->assertDatabaseHas('assignments', ['user_id' => $active->id]);
    }
}
