<?php

namespace Tests\Feature;

use App\Models\FlashcardDeck;
use App\Models\MemoryExitQuiz;
use App\Models\Question;
use App\Models\User;
use App\Services\Achievements;
use App\Services\Blueprint;
use App\Services\Catalog;
use App\Services\Closet;
use App\Services\DatabaseBackups;
use App\Services\FeatureUpdates;
use App\Services\FreshCatch;
use App\Services\Leaderboard;
use App\Services\Learning;
use App\Services\Study;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearningOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $role = 'student'): User
    {
        $u = User::factory()->create(['username' => 'test-'.uniqid(), 'role' => $role, 'must_change_password' => false, 'cohort' => 'Clerks A']);
        $u->refresh();
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version])->withoutVite();

        return $u;
    }

    private function bank(int $count = 24): array
    {
        Question::query()->update(['active' => false]);
        $rows = [];
        for ($n = 1; $n <= $count; $n++) {
            $rows[] = ['id' => 'OPS-'.$n, 'subject' => $n <= 12 ? 'Anatomy' : 'Pathology', 'topic' => $n % 2 ? 'Topic A' : 'Topic B',
                'tier' => $n % 3 ? 'Easy' : 'Difficult', 'stem' => 'Operations fixture '.$n, 'options' => ['One', 'Two', 'Three', 'Four'], 'key' => 'A', 'rationale' => 'Test explanation',
                'expl' => ['Yes', 'No', 'No', 'No'], 'raw' => ['Answer Source' => 'Synthetic fixture reference']];
        }
        app(Catalog::class)->import(['items' => $rows]);

        return app(Catalog::class)->items();
    }

    private function settings(): array
    {
        return ['mode' => 'tutor', 'count' => 4, 'seed' => 8, 'subjects' => ['Anatomy', 'Pathology'], 'topics' => [], 'difficulty' => 'All', 'minutes' => 30, 'balance' => false, 'pool' => 'all'];
    }

    public function test_review_is_admin_only_version_specific_and_requires_both_checks(): void
    {
        $this->signIn();
        $items = $this->bank();
        $q = Question::findOrFail($items[0]['id']);
        $payload = ['version_id' => $q->current_version_id, 'source_checked' => true, 'answer_checked' => true, 'decision' => 'checked', 'notes' => 'Checked against the cited source.'];
        $this->postJson('/admin/quality/'.$q->id, $payload)->assertForbidden();
        $this->signIn('admin');
        $this->postJson('/admin/quality/'.$q->id, [...$payload, 'answer_checked' => false])->assertUnprocessable();
        $this->post('/admin/quality/'.$q->id, $payload)->assertRedirect();
        $this->assertDatabaseHas('question_quality_reviews', ['question_version_id' => $q->current_version_id, 'decision' => 'checked']);
        $changed = $items[0];
        $changed['rationale'] = 'Changed explanation.';
        app(Catalog::class)->import(['items' => [$changed]]);
        $this->postJson('/admin/quality/'.$q->id, $payload)->assertConflict();
        $this->get('/admin/quality?filter=unreviewed')->assertOk();
        $this->assertSame(1, DB::table('question_quality_reviews')->count());
    }

    public function test_blueprints_recover_overlapping_rows_and_preserve_replacement_quotas(): void
    {
        $this->signIn();
        $items = $this->bank();
        $rows = [['subject' => 'Anatomy', 'topic' => '', 'difficulty' => 'All', 'count' => 2], ['subject' => 'Anatomy', 'topic' => 'Topic A', 'difficulty' => 'Easy', 'count' => 2]];
        $out = app(Blueprint::class)->select([$items[0], $items[1], $items[4], $items[5]], $rows, 4);
        $this->assertCount(4, array_unique(array_column($out, 'id')));
        $this->assertCount(2, array_filter(array_slice($out, 0, 2), fn ($q) => $q['topic'] === 'Topic A'));
        $rows = [['subject' => 'Anatomy', 'topic' => 'Topic A', 'difficulty' => 'Easy', 'count' => 2], ['subject' => 'Pathology', 'topic' => 'Topic B', 'difficulty' => 'Difficult', 'count' => 2]];
        $draft = $this->postJson('/exams/preview', [...$this->settings(), 'title' => 'Quota exam', 'blueprint' => $rows])->assertOk()->json();
        $this->assertCount(4, $draft['items']);
        $old = $draft['items'][0];
        $replace = $this->postJson('/exam-drafts/'.$draft['id'].'/replace', ['position' => 0, 'revision' => 0]);
        // A fully used cell may have no unused replacement; a valid replacement must keep all three dimensions.
        if ($replace->status() === 200) {
            foreach (['subject', 'topic', 'tier'] as $field) {
                $this->assertSame($old[$field], $replace->json('items.0.'.$field));
            }
        } else {
            $replace->assertUnprocessable();
        }
        $this->postJson('/exams/preview', [...$this->settings(), 'title' => 'Bad quota', 'blueprint' => array_slice($rows, 0, 1)])->assertUnprocessable();
    }

    public function test_shared_recipes_are_scoped_to_cohort_and_cannot_be_published_by_learners(): void
    {
        $this->bank();
        $this->signIn('admin');
        $this->post('/exams/presets', [...$this->settings(), 'name' => 'Class A recipe', 'shared' => true, 'cohort' => 'Clerks A'])->assertRedirect();
        $this->post('/exams/presets', [...$this->settings(), 'name' => 'Class B recipe', 'shared' => true, 'cohort' => 'Clerks B'])->assertRedirect();
        $this->signIn();
        $page = $this->get('/exams')->assertOk()->viewData('page')['props'];
        $this->assertSame(['Class A recipe'], collect($page['presets'])->pluck('name')->all());
        $this->postJson('/exams/presets', [...$this->settings(), 'name' => 'Unauthorized', 'shared' => true])->assertForbidden();
    }

    public function test_exit_check_has_no_answer_leak_is_resumable_and_freezes_score_without_awarding_points(): void
    {
        $u = $this->signIn();
        $items = array_slice($this->bank(), 0, 5);
        $deck = FlashcardDeck::create(['user_id' => $u->id, 'title' => 'Recall fixture', 'settings' => []]);
        foreach ($items as $q) {
            $deck->items()->create(['question_id' => $q['id'], 'snapshot' => $q]);
        }
        $this->postJson('/flashcards/decks/'.$deck->id.'/exit-check', ['session_number' => 1])->assertUnprocessable();
        $deck->items()->update(['confidence' => 5]);
        $this->post('/flashcards/decks/'.$deck->id.'/exit-check', ['session_number' => 1])->assertRedirect();
        $this->post('/flashcards/decks/'.$deck->id.'/exit-check', ['session_number' => 1])->assertRedirect();
        $this->assertSame(1, MemoryExitQuiz::count());
        $quiz = MemoryExitQuiz::firstOrFail();
        $page = $this->get('/memory-checks/'.$quiz->id)->assertOk()->viewData('page')['props']['quiz'];
        foreach ($page['items'] as $i) {
            $this->assertArrayNotHasKey('key', $i['question']);
            $this->assertArrayNotHasKey('rationale', $i['question']);
        }
        $answers = array_fill_keys(array_column($items, 'id'), 'A');
        $this->postJson('/memory-checks/'.$quiz->id, ['answers' => array_slice($answers, 0, 1)])->assertUnprocessable();
        $this->post('/memory-checks/'.$quiz->id, ['answers' => $answers])->assertRedirect();
        $this->assertSame(5, $quiz->fresh()->score);
        $this->post('/memory-checks/'.$quiz->id, ['answers' => array_fill_keys(array_keys($answers), 'B')])->assertRedirect();
        $this->assertSame(5, $quiz->fresh()->score);
        $this->assertSame(0, DB::table('study_events')->where('user_id', $u->id)->count());
        $this->signIn();
        $this->get('/memory-checks/'.$quiz->id)->assertForbidden();
        $this->postJson('/memory-checks/'.$quiz->id, ['answers' => $answers])->assertForbidden();
    }

    public function test_fresh_mix_balances_subjects_fills_shortfalls_and_retries_wrong_answers(): void
    {
        $u = $this->signIn();
        $items = $this->bank();
        foreach ([0, 1, 12, 13] as $n) {
            app(Learning::class)->answer($u->id, 'fixture:'.$n, $items[$n], 'memory', false);
        }
        $s = [...$this->settings(), 'fresh_unseen' => 6, 'fresh_balance' => true];
        $chosen = app(FreshCatch::class)->select($u->id, $s);
        $this->assertCount(10, $chosen);
        $this->assertSame(4, collect($chosen)->where('fresh_kind', 'missed')->count());
        $this->assertSame(['Anatomy' => 5, 'Pathology' => 5], collect($chosen)->countBy('subject')->sortKeys()->all());
        $fallback = app(FreshCatch::class)->select($u->id, [...$s, 'fresh_unseen' => 0]);
        $this->assertSame(6, collect($fallback)->where('fresh_kind', 'unseen')->count());
        app(Learning::class)->answer($u->id, 'fixture:correct', $items[0], 'memory', true);
        $this->assertNotContains($items[0]['id'], array_column(app(FreshCatch::class)->eligible($u->id), 'id'));
        $this->postJson('/fresh-catch/preview', [...$s, 'fresh_unseen' => 11])->assertUnprocessable();
    }

    public function test_memory_answer_counts_before_rating_and_repeat_is_not_extra_completion_or_points(): void
    {
        $u = $this->signIn();
        $q = $this->bank()[0];
        $deck = FlashcardDeck::create(['user_id' => $u->id, 'title' => 'Points fixture', 'settings' => []]);
        $deck->items()->create(['question_id' => $q['id'], 'snapshot' => $q]);
        $present = $this->get('/flashcards/decks/'.$deck->id)->assertOk()->viewData('page')['props']['deck']['question'];
        $payload = ['question_id' => $q['id'], 'token' => $present['token'], 'answer' => 'A'];
        $this->postJson('/flashcards/decks/'.$deck->id.'/answer', $payload)->assertOk()->assertJsonPath('achievement.count', 1);
        $this->postJson('/flashcards/decks/'.$deck->id.'/answer', $payload)->assertOk()->assertJsonPath('achievement.count', 1);
        app(Learning::class)->answer($u->id, 'another-presentation', $q, 'memory', true);
        $this->assertSame(1, Achievements::for($u->id)['count']);
        $row = collect(app(Leaderboard::class)->for($u)['rows'])->firstWhere('profile.id', $u->id);
        $this->assertSame(2, $row['points']);
    }

    public function test_unfinished_catch_remains_accessible_when_new_pool_is_short(): void
    {
        $u = $this->signIn();
        $this->bank();
        $s = [...$this->settings(), 'mode' => 'fresh'];
        $attempt = app(Study::class)->start($u, $s);
        Question::query()->update(['active' => false]);
        $this->postJson('/fresh-catch/preview', $s)->assertUnprocessable();
        $props = $this->get('/practice')->assertOk()->viewData('page')['props'];
        $this->assertSame($attempt->id, $props['activeCatch']);
        $this->post('/attempts', $s)->assertRedirect('/attempts/'.$attempt->id);
        $this->get('/attempts/'.$attempt->id)->assertOk();
    }

    public function test_update_popup_is_per_user_per_release_and_acknowledgment_is_idempotent(): void
    {
        $u = $this->signIn();
        $this->assertTrue(FeatureUpdates::for($u)['unread']);
        $this->postJson('/feature-updates/read', ['release' => 'invented-release'])->assertUnprocessable();
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/feature-updates/read', ['release' => FeatureUpdates::id()])->assertOk();
        }
        $this->assertFalse(FeatureUpdates::for($u)['unread']);
        $this->assertSame(1, DB::table('feature_update_reads')->where('user_id', $u->id)->count());
        $other = $this->signIn();
        $this->assertTrue(FeatureUpdates::for($other)['unread']);
    }

    public function test_roster_preview_rejects_duplicates_and_invites_are_single_use(): void
    {
        $admin = $this->signIn('admin');
        $bad = UploadedFile::fake()->createWithContent('roster.csv', "username,cohort\nnew-test,A\nNEW-TEST,A\n");
        $preview = $this->postJson('/admin/onboarding/preview', ['roster' => $bad])->assertOk()->json();
        $this->assertNull($preview['id']);
        $this->assertNotEmpty($preview['errors']);
        $good = UploadedFile::fake()->createWithContent('roster.csv', "username,display_name,cohort\nnew-test,Test Learner,Clerks A\n");
        $preview = $this->postJson('/admin/onboarding/preview', ['roster' => $good])->assertOk()->json();
        $this->assertNotNull($preview['id']);
        $result = $this->postJson('/admin/onboarding/'.$preview['id'])->assertOk()->json();
        $this->postJson('/admin/onboarding/'.$preview['id'])->assertConflict();
        $user = User::where('username', 'new-test')->firstOrFail();
        $this->assertTrue($user->invitation_pending);
        $this->assertSame('student', $user->role);
        $token = basename($result['links'][0]['url']);
        $this->assertNotSame($token, DB::table('learner_invitations')->where('user_id', $user->id)->value('token_hash'));
        $this->post('/logout');
        $this->get('/join/'.$token)->assertOk();
        $this->post('/join/'.$token, ['password' => 'new-test-password', 'password_confirmation' => 'new-test-password'])->assertRedirect('/login');
        $this->assertFalse($user->fresh()->invitation_pending);
        $this->post('/join/'.$token, ['password' => 'new-test-password', 'password_confirmation' => 'new-test-password'])->assertStatus(410);
        $this->post('/login', ['username' => 'new-test', 'password' => 'new-test-password'])->assertRedirect('/');
        $this->postJson('/admin/onboarding/preview', ['roster' => $good])->assertForbidden();
    }

    public function test_invitation_reissue_expiry_and_revocation_invalidate_old_links(): void
    {
        $admin = $this->signIn('admin');
        $file = UploadedFile::fake()->createWithContent('roster.csv', "username\nexpiry-test\n");
        $batch = $this->postJson('/admin/onboarding/preview', ['roster' => $file])->assertOk()->json('id');
        $old = basename($this->postJson('/admin/onboarding/'.$batch)->assertOk()->json('links.0.url'));
        $id = DB::table('learner_invitations')->where('user_id', User::where('username', 'expiry-test')->value('id'))->value('id');
        $new = basename($this->postJson('/admin/onboarding/invitations/'.$id.'/reissue')->assertOk()->json('links.0.url'));
        $this->post('/logout');
        $this->get('/join/'.$old)->assertStatus(410);
        $this->get('/join/'.$new)->assertOk();
        $this->travel(8)->days();
        $this->get('/join/'.$new)->assertStatus(410);
        $this->travelBack();
        $this->actingAs($admin)->withSession(['auth_version' => $admin->auth_version]);
        $this->post('/admin/onboarding/invitations/'.$id.'/revoke')->assertRedirect();
        $this->post('/logout');
        $this->get('/join/'.$new)->assertStatus(410);
    }

    public function test_admin_can_study_and_opt_into_leaderboard_without_exposing_pending_or_archived_accounts(): void
    {
        $u = $this->signIn('admin');
        $q = $this->bank()[0];
        $u->update(['leaderboard_enabled' => true]);
        app(Learning::class)->answer($u->id, 'admin-practice', $q, 'memory', true);
        $other = User::factory()->create(['username' => 'pending-fixture', 'invitation_pending' => true]);
        $archived = User::factory()->create(['username' => 'retired-fixture', 'archived_at' => now(), 'disabled' => true]);
        $board = app(Leaderboard::class)->for($u);
        $this->assertSame(2, collect($board['rows'])->firstWhere('profile.id', $u->id)['points']);
        $this->assertNotContains($other->id, array_column(array_column($board['rows']->all(), 'profile'), 'id'));
        $this->assertNotContains($archived->id, array_column(array_column($board['rows']->all(), 'profile'), 'id'));
        $this->get('/practice')->assertOk();
        $this->post('/attempts', $this->settings())->assertRedirect();
        $this->get('/tour')->assertOk();
        $this->get('/admin/backups')->assertOk();
        $this->patchJson('/admin/users/'.$archived->id, ['disabled' => false])->assertNotFound();
    }

    public function test_new_tiers_have_fixed_thresholds_distinct_names_and_real_portrait_files(): void
    {
        $this->assertSame('Omakase Legend', Achievements::at(5000)['name']);
        foreach ([5200 => 'Yuzu Moonkeeper', 5400 => 'Onigiri Oracle', 5600 => 'Saba Stargazer', 5800 => 'Futomaki Phoenix', 6000 => 'Sushi Aurora'] as $count => $name) {
            $rank = Achievements::at($count);
            $this->assertSame($name, $rank['name']);
            $this->assertSame($count + 200, $rank['threshold']);
            $this->assertFileExists(public_path(ltrim(Achievements::portrait($count / 200), '/')));
        }
        $names = array_map(fn ($n) => Achievements::at($n * 200)['name'], range(0, 100));
        $this->assertCount(101, array_unique($names));
        $u = $this->signIn();
        $this->assertFalse(collect(Closet::all($u))->firstWhere('id', 'sushi-aurora')['unlocked']);
        $this->post('/profile/equip', ['avatar' => 'sushi-aurora'])->assertSessionHasErrors();
    }

    public function test_restore_guard_rejects_live_names_and_path_input(): void
    {
        foreach (['sushi', 'sushi_test', '../sushi_rehearsal_target', 'sushi_rehearsal_'] as $name) {
            try {
                DatabaseBackups::assertTarget($name, 'sushi');
                $this->fail('Unsafe target accepted.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Rehearsals require', $e->getMessage());
            }
        }
        DatabaseBackups::assertTarget('sushi_rehearsal_12345678', 'sushi');
        $this->assertTrue(true);
    }
}
