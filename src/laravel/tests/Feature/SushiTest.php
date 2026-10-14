<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Attempt;
use App\Models\Exam;
use App\Models\Flashcard;
use App\Models\FlashcardDeck;
use App\Models\PdfExport;
use App\Models\Progress;
use App\Models\Question;
use App\Models\User;
use App\Services\Achievements;
use App\Services\Analytics;
use App\Services\Catalog;
use App\Services\Draw;
use App\Services\ExamPdf;
use App\Services\Study;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SushiTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function learner(string $name = 'powquanico'): User
    {
        $u = User::where('username', $name)->firstOrFail();
        $u->update(['must_change_password' => false]);
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version]);

        return $u;
    }

    private function settings(array $extra = []): array
    {
        return array_replace(['mode' => 'tutor', 'count' => 10, 'subjects' => Catalog::SUBJECTS, 'topics' => [], 'difficulty' => 'All', 'seed' => 12345, 'balance' => false, 'pool' => 'all', 'minutes' => 1], $extra);
    }

    private function attempt(array $extra = []): Attempt
    {
        return app(Study::class)->start(auth()->user(), $this->settings($extra));
    }

    private function inertia(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    public function test_seed_content_and_accounts_are_repeatable(): void
    {
        $this->assertSame(2463, Question::count());
        $this->assertSame(4, User::count());
        $u = User::where('username', 'jstiosin')->first();
        $this->assertSame('admin', $u->role);
        $this->assertTrue(Hash::check('password', $u->password));
        $u->update(['password' => 'changed-password']);
        $hash = $u->password;
        $this->seed();
        $this->assertSame($hash, $u->fresh()->password);
        $this->assertSame(2463, DB::table('question_versions')->count());
        $bank = json_decode(file_get_contents(database_path('data/bank.json')), true);
        foreach ($bank['items'] as $q) {
            $this->assertSame($q, Question::find($q['id'])->currentVersion->content);
        }
    }

    public function test_guest_login_shows_active_bank_count_without_question_content(): void
    {
        $page = $this->get('/login')->assertOk()->viewData('page');
        $this->assertSame('Login', $page['component']);
        $this->assertSame(2463, $page['props']['questionCount']);
        $this->assertArrayNotHasKey('items', $page['props']);
        $this->assertArrayNotHasKey('questions', $page['props']);
        Question::firstOrFail()->update(['active' => false]);
        $this->assertSame(2462, $this->get('/login')->assertOk()->viewData('page')['props']['questionCount']);
    }

    public function test_all_requested_accounts_can_login_and_must_change_password(): void
    {
        foreach (['powquanico', 'hannahquintero', 'rachirpunla', 'jstiosin'] as $name) {
            $this->post('/login', ['username' => $name, 'password' => 'password'])->assertRedirect('/password');
            $this->assertAuthenticatedAs(User::where('username', $name)->first());
            $this->get('/')->assertRedirect('/password');
            $this->post('/logout');
        }
    }

    public function test_bank_preview_publish_append_order_and_reseeding_preserve_admin_edits(): void
    {
        $admin = $this->learner('jstiosin');
        $q = Question::orderBy('position')->first();
        $edited = $q->currentVersion->content;
        $edited['stem'] .= ' Updated by the administrator.';
        app(Catalog::class)->import(['items' => [$edited]], $admin->id);
        $new = $edited;
        $new['id'] = 'IM-QA-NEW';
        $new['stem'] .= ' New question.';
        $bytes = json_encode(['items' => [$new]]);
        $file = fn () => UploadedFile::fake()->createWithContent('bank.json', $bytes);
        $this->postJson('/admin/import', ['bank' => $file()])->assertOk()->assertJsonPath('preview.items', 1);
        $this->assertSame(2463, Question::count());
        $this->postJson('/admin/import', ['bank' => $file(), 'publish' => true, 'preview_hash' => 'wrong'])->assertUnprocessable();
        $this->postJson('/admin/import', ['bank' => $file(), 'publish' => true, 'preview_hash' => hash('sha256', $bytes)])->assertOk();
        $this->assertSame(2464, Question::findOrFail('IM-QA-NEW')->position);
        $this->seed();
        $this->assertSame($edited['stem'], $q->fresh()->currentVersion->content['stem']);
        $this->assertSame(2, $q->fresh()->currentVersion->version);
        $this->assertSame(2464, Question::count());
        $bad = UploadedFile::fake()->createWithContent('invalid.json', json_encode(['items' => ['invalid']]));
        $this->postJson('/admin/import', ['bank' => $bad])->assertUnprocessable();
    }

    public function test_tier_credit_is_shared_across_mcq_flashcard_and_retired_items(): void
    {
        $u = $this->learner();
        $q = Question::first();
        Progress::create(['user_id' => $u->id, 'question_id' => $q->id, 'completed_at' => now(), 'latest_correct' => true]);
        Flashcard::create(['user_id' => $u->id, 'question_id' => $q->id, 'confidence' => 5]);
        $this->assertSame(1, Achievements::for($u->id)['count']);
        $q->update(['active' => false]);
        $this->assertSame(1, Achievements::for($u->id)['count']);
    }

    public function test_login_is_throttled_and_disabled_users_cannot_login(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', ['username' => 'powquanico', 'password' => 'wrong-password'])->assertSessionHasErrors('username');
        }
        $this->assertGuest();
        User::where('username', 'hannahquintero')->update(['disabled' => true]);
        $this->post('/login', ['username' => 'hannahquintero', 'password' => 'password'])->assertSessionHasErrors('username');
    }

    public function test_password_change_and_student_admin_boundary(): void
    {
        $u = $this->learner();
        $this->post('/password', ['current_password' => 'password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertRedirect('/');
        $this->assertTrue(Hash::check('new-password-123', $u->fresh()->password));
        $this->get('/admin')->assertForbidden();
        $this->post('/admin/users', ['username' => 'intruder', 'password' => 'password'])->assertForbidden();
    }

    public function test_answers_persist_without_leaking_keys_and_award_unique_completion(): void
    {
        $u = $this->learner();
        $a = $this->attempt();
        $item = $a->items->first();
        $props = $this->inertia('/attempts/'.$a->id);
        $this->assertArrayNotHasKey('key', $props['attempt']['items'][0]);
        $this->postJson('/attempts/'.$a->id.'/answer', ['item_id' => $item->id, 'answer' => $item->snapshot['key'], 'revision' => 0])->assertOk();
        $this->assertSame(0, Achievements::for($u->id)['count']);
        $this->postJson('/attempts/'.$a->id.'/answer', ['item_id' => $item->id, 'answer' => $item->snapshot['key'], 'revision' => 1, 'check' => true])->assertOk()->assertJsonPath('attempt.items.0.correct', true);
        $this->assertSame(1, Achievements::for($u->id)['count']);
        $this->postJson('/attempts/'.$a->id.'/answer', ['item_id' => $item->id, 'answer' => $item->snapshot['key'], 'revision' => 1, 'check' => true])->assertOk();
        $this->assertSame(1, Achievements::for($u->id)['count']);
        $this->assertSame(1, DB::table('answer_revisions')->count());
        $this->learner('hannahquintero');
        $this->get('/attempts/'.$a->id)->assertForbidden();
        $this->postJson('/attempts/'.$a->id.'/answer', ['item_id' => $item->id, 'answer' => 'A', 'revision' => 2])->assertForbidden();
    }

    public function test_concurrent_stale_answers_do_not_overwrite_saved_answers(): void
    {
        $this->learner();
        $a = $this->attempt();
        $i = $a->items->first();
        $this->postJson('/attempts/'.$a->id.'/answer', ['item_id' => $i->id, 'answer' => 'A', 'revision' => 0])->assertOk();
        $this->postJson('/attempts/'.$a->id.'/answer', ['item_id' => $i->id, 'answer' => 'B', 'revision' => 0])->assertConflict();
        $this->assertSame('A', $i->fresh()->answer);
    }

    public function test_fresh_catch_retries_wrong_answers_and_excludes_latest_correct(): void
    {
        $u = $this->learner();
        $a = $this->attempt(['mode' => 'fresh']);
        $item = $a->items->first();
        $q = $item->snapshot;
        $wrong = $q['key'] === 'A' ? 'B' : 'A';
        app(Study::class)->answer($a, $item->id, ['answer' => $wrong, 'revision' => 0, 'check' => true]);
        $pool = app(Study::class)->eligible($u->id, $this->settings(['mode' => 'fresh']));
        $this->assertContains($q['id'], array_column($pool, 'id'));
        $b = Attempt::create(['user_id' => $u->id, 'mode' => 'tutor', 'settings' => $this->settings()]);
        $i = $b->items()->create(['question_id' => $q['id'], 'position' => 0, 'snapshot' => $q]);
        app(Study::class)->answer($b, $i->id, ['answer' => $q['key'], 'revision' => 0, 'check' => true]);
        $pool = app(Study::class)->eligible($u->id, $this->settings(['mode' => 'fresh']));
        $this->assertNotContains($q['id'], array_column($pool, 'id'));
    }

    public function test_fresh_pool_uses_latest_result_and_duplicate_groups(): void
    {
        $u = $this->learner();
        Progress::create(['user_id' => $u->id, 'question_id' => 'SUR-Q0270', 'completed_at' => now()->subMinute(), 'latest_answer' => 'A', 'latest_correct' => true]);
        $s = $this->settings(['mode' => 'fresh']);
        $pool = app(Study::class)->eligible($u->id, $s);
        $this->assertNotContains('SUR-Q0270', array_column($pool, 'id'));
        $this->assertNotContains('SUR-Q0295', array_column($pool, 'id'));
        Progress::create(['user_id' => $u->id, 'question_id' => 'SUR-Q0295', 'completed_at' => now(), 'latest_answer' => 'B', 'latest_correct' => false]);
        $pool = app(Study::class)->eligible($u->id, $s);
        $this->assertContains('SUR-Q0270', array_column($pool, 'id'));
    }

    public function test_fresh_shortage_rejected_and_draw_has_no_duplicate_stems(): void
    {
        $this->learner();
        Question::query()->update(['active' => false]);
        Question::where('position', '<=', 9)->update(['active' => true]);
        $this->post('/attempts', $this->settings(['mode' => 'fresh']))->assertSessionHasErrors('count');
        Question::query()->update(['active' => true]);
        $a = $this->attempt(['count' => 2462]);
        $hashes = $a->items->map(fn ($i) => Catalog::stemHash($i->snapshot['stem']));
        $this->assertSame(2462, $hashes->unique()->count());
    }

    public function test_timer_is_server_authoritative_and_submission_is_idempotent(): void
    {
        $this->freezeTime();
        $u = $this->learner();
        $a = $this->attempt(['mode' => 'exam', 'count' => 2]);
        $i = $a->items->first();
        $this->postJson('/attempts/'.$a->id.'/answer', ['item_id' => $i->id, 'answer' => $i->snapshot['key'], 'revision' => 0, 'check' => true])->assertUnprocessable();
        $this->postJson('/attempts/'.$a->id.'/answer', ['item_id' => $i->id, 'answer' => $i->snapshot['key'], 'revision' => 0])->assertOk()->assertJsonMissingPath('attempt.items.0.key');
        $this->travel(61)->seconds();
        $this->postJson('/attempts/'.$a->id.'/answer', ['item_id' => $i->id, 'answer' => 'B', 'revision' => 1])->assertConflict();
        $this->assertSame('timed_out', $a->fresh()->status);
        $this->assertSame(1, $a->fresh()->score);
        $this->post('/attempts/'.$a->id.'/finish')->assertRedirect();
        $this->assertSame(1, Achievements::for($u->id)['count']);
    }

    public function test_assignment_window_limit_and_answer_release_are_enforced(): void
    {
        $u = $this->learner();
        $items = app(Draw::class)->select(app(Catalog::class)->items(), $this->settings(['count' => 1]));
        $exam = Exam::create(['user_id' => User::where('role', 'admin')->first()->id, 'title' => 'Assigned', 'code' => 'ASSIGNED-TEST', 'items' => $items, 'settings' => $this->settings()]);
        $as = Assignment::create(['user_id' => $u->id, 'exam_id' => $exam->id, 'opens_at' => now()->subMinute(), 'closes_at' => now()->addHour(), 'minutes' => 5, 'max_attempts' => 1]);
        $this->post('/assignments/'.$as->id.'/start')->assertRedirect();
        $a = Attempt::where('assignment_id', $as->id)->first();
        $this->post('/assignments/'.$as->id.'/start')->assertSessionHasErrors('assignment');
        $this->post('/attempts/'.$a->id.'/finish')->assertRedirect();
        $p = $this->inertia('/attempts/'.$a->id);
        $this->assertArrayNotHasKey('key', $p['attempt']['items'][0]);
        $as->update(['release_answers' => true]);
        $p = $this->inertia('/attempts/'.$a->id);
        $this->assertArrayHasKey('key', $p['attempt']['items'][0]);
    }

    public function test_question_edits_preserve_past_attempt_snapshots(): void
    {
        $this->learner();
        $a = $this->attempt();
        $i = $a->items->first();
        $old = $i->snapshot;
        $this->learner('jstiosin');
        $updated = $old;
        unset($updated['version'],$updated['version_id']);
        $updated['stem'] .= ' Updated wording.';
        $this->patch('/admin/questions/'.$i->question_id, ['content' => $updated, 'active' => false])->assertRedirect();
        $this->assertSame($old, $i->fresh()->snapshot);
        $this->assertSame(2, Question::find($i->question_id)->currentVersion->version);
        $this->assertFalse(Question::find($i->question_id)->active);
    }

    public function test_admin_user_management_revokes_sessions_and_audits_without_password(): void
    {
        $this->learner('jstiosin');
        $u = User::where('username', 'powquanico')->first();
        $this->patch('/admin/users/'.$u->id, ['disabled' => true, 'cohort' => 'Clerks', 'password' => 'temporary-123'])->assertRedirect();
        $u->refresh();
        $this->assertTrue($u->disabled);
        $this->assertTrue(Hash::check('temporary-123', $u->password));
        $this->assertStringNotContainsString('temporary-123', DB::table('audit_logs')->latest('id')->first()->details);
        $this->actingAs($u)->withSession(['auth_version' => 1])->get('/')->assertRedirect('/login');
    }

    private function deck(): FlashcardDeck
    {
        $ids = Question::orderBy('position')->limit(5)->pluck('id')->all();
        $this->post('/flashcards/decks', ['title' => 'Test deck', 'subjects' => Catalog::SUBJECTS, 'topics' => [], 'difficulty' => 'All', 'range_start' => 1, 'range_end' => 5, 'selected_ids' => $ids])->assertRedirect();

        return FlashcardDeck::latest()->first();
    }

    public function test_flashcard_selection_immediate_feedback_counts_and_rating_retries(): void
    {
        $u = $this->learner();
        $deck = $this->deck();
        $d = $this->inertia('/flashcards/decks/'.$deck->id)['deck'];
        $q = $d['question'];
        $this->assertSame([5, 0, 0, 0, 0, 0], $d['counts']);
        $this->assertArrayNotHasKey('key', $q);
        $this->postJson('/flashcards/decks/'.$deck->id.'/rate', ['question_id' => $q['id'], 'confidence' => 1, 'token' => $q['token']])->assertConflict();
        $correct = $deck->items()->where('question_id', $q['id'])->first()->snapshot['key'];
        $result = $this->postJson('/flashcards/decks/'.$deck->id.'/answer', ['question_id' => $q['id'], 'answer' => $correct, 'token' => $q['token']])->assertOk()->assertJsonPath('deck.question.correct', true);
        $this->assertNotNull($result->json('deck.question.rationale'));
        $payload = ['question_id' => $q['id'], 'confidence' => 1, 'token' => $q['token']];
        $this->postJson('/flashcards/decks/'.$deck->id.'/rate', $payload)->assertOk()->assertJsonPath('deck.counts.0', 4)->assertJsonPath('deck.counts.1', 1);
        $this->postJson('/flashcards/decks/'.$deck->id.'/rate', $payload)->assertOk();
        $this->postJson('/flashcards/decks/'.$deck->id.'/answer', ['question_id' => $q['id'], 'answer' => $correct, 'token' => $q['token']])->assertConflict();
        $this->assertSame(1, DB::table('flashcard_reviews')->count());
        $this->assertSame(1, Achievements::for($u->id)['count']);
        $this->assertSame(0, app(Analytics::class)->for($u->id)['graded_total']);
        $this->learner('hannahquintero');
        $this->get('/flashcards/decks/'.$deck->id)->assertForbidden();
    }

    public function test_flashcard_range_and_exact_selection_cannot_escape_filters(): void
    {
        $this->learner();
        $s = ['subjects' => ['Pediatrics'], 'topics' => [], 'difficulty' => 'All', 'range_start' => 1, 'range_end' => 10];
        $p = $this->postJson('/flashcards/preview', $s)->assertOk()->json();
        $this->assertCount(10, $p['questions']);
        foreach ($p['questions'] as $q) {
            $this->assertSame('Pediatrics', $q['subject']);
        }
        $this->postJson('/flashcards/decks', [...$s, 'title' => 'Invalid', 'selected_ids' => [Question::where('subject', 'Surgery')->first()->id]])->assertUnprocessable();
    }

    public function test_legacy_import_is_scoped_repeatable_and_excludes_unknown_first_attempts(): void
    {
        $u = $this->learner();
        $q = Question::first();
        $p = ['version' => 2, 'bankHash' => Catalog::HASH, 'answers' => [$q->id => ['answer' => $q->currentVersion->content['key'], 'attempts' => 4]], 'bookmarks' => [$q->id], 'answeredIds' => [$q->id]];
        $bytes = json_encode($p);
        $file = fn () => UploadedFile::fake()->createWithContent('progress.json', $bytes);
        $this->postJson('/import', ['backup' => $file()])->assertOk()->assertJsonPath('preview.completed', 1);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/import', ['backup' => $file(), 'confirm' => true, 'preview_hash' => hash('sha256', $bytes)])->assertOk();
        }
        $this->assertSame(1, Progress::where('user_id', $u->id)->count());
        $this->assertSame(1, DB::table('imports')->count());
        $stats = app(Analytics::class)->for($u->id);
        $this->assertSame(1, $stats['legacy']);
        $this->assertSame(0, $stats['first_total']);
        $this->assertSame(0, Progress::where('user_id', User::where('username', 'hannahquintero')->first()->id)->count());
    }

    public function test_analytics_reconciles_completed_and_unanswered_scores(): void
    {
        $u = $this->learner();
        $a = $this->attempt(['count' => 2]);
        $i = $a->items->first();
        app(Study::class)->answer($a, $i->id, ['answer' => $i->snapshot['key'], 'revision' => 0, 'check' => true]);
        app(Study::class)->finish($a);
        $s = app(Analytics::class)->for($u->id);
        $this->assertSame(1, $s['first_total']);
        $this->assertSame(1, $s['first_correct']);
        $this->assertSame(1, $s['graded_total']);
        $this->assertSame(1, $a->fresh()->score);
        $this->inertia('/progress');
        $this->learner('jstiosin');
        $this->inertia('/admin?student='.$u->id);
    }

    public function test_pdf_contains_signature_and_downloads_are_authorized(): void
    {
        $u = $this->learner();
        $items = app(Draw::class)->select(app(Catalog::class)->items(), $this->settings(['count' => count(Catalog::SUBJECTS), 'balance' => true]));
        $e = Exam::create(['user_id' => $u->id, 'title' => 'Test PDF', 'code' => 'PDF-TEST', 'items' => $items, 'settings' => $this->settings()]);
        foreach (['paper', 'sheet', 'key'] as $kind) {
            $bytes = app(ExamPdf::class)->render($e, $kind, true);
            $this->assertStringStartsWith('%PDF-', $bytes);
        }
        Storage::fake('local');
        $export = PdfExport::create(['user_id' => $u->id, 'exam_id' => $e->id, 'status' => 'ready']);
        Storage::disk('local')->put('exams/'.$export->id.'/key.pdf', '%PDF-test');
        $this->get('/exports/'.$export->id.'/key')->assertOk();
        $this->learner('hannahquintero');
        $this->get('/exports/'.$export->id.'/key')->assertForbidden();
    }
}
