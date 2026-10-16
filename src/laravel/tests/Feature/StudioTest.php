<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Exam;
use App\Models\ExamDraft;
use App\Models\FlashcardDeck;
use App\Models\Progress;
use App\Models\Question;
use App\Models\User;
use App\Services\Achievements;
use App\Services\Catalog;
use App\Services\Closet;
use App\Services\Passport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudioTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function user(string $name = 'powquanico'): User
    {
        $u = User::where('username', $name)->firstOrFail();
        $u->update(['must_change_password' => false]);
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version]);
        $this->withoutVite();

        return $u;
    }

    private function settings(array $extra = []): array
    {
        return array_replace(['title' => 'QA Bento', 'mode' => 'tutor', 'count' => 20, 'seed' => 123, 'subjects' => Catalog::SUBJECTS, 'topics' => [], 'topic_scope' => 'all', 'difficulty' => 'All', 'balance' => true, 'pool' => 'all', 'minutes' => 30], $extra);
    }

    public function test_every_achievement_has_a_distinct_character_and_rank_frame(): void
    {
        $u = $this->user();
        $closet = Closet::all($u);
        $this->assertCount(59, $closet);
        $this->assertCount(59, array_unique(array_column($closet, 'image')));
        $rewards = array_values(array_filter(array_column($closet, 'badge')));
        $this->assertEqualsCanonicalizing(array_keys(Passport::definitions()), $rewards);
        foreach ($closet as $a) {
            $this->assertFileExists(public_path($a['image']));
            $this->assertSame('bronze', $a['frame']);
        }
        $this->postJson('/profile/equip', ['avatar' => 'toro'])->assertUnprocessable();
        DB::table('earned_badges')->insert(['user_id' => $u->id, 'badge' => 'fresh_perfect', 'earned_at' => now()]);
        $this->post('/profile/equip', ['avatar' => 'toro'])->assertRedirect();
        $this->assertSame('toro', $u->fresh()->avatar);
        foreach ([0 => 'bronze', 199 => 'bronze', 200 => 'silver', 399 => 'silver', 400 => 'gold', 599 => 'gold', 600 => 'sakura', 800 => 'sakura', 50000 => 'sakura'] as $n => $frame) {
            $this->assertSame($frame, Achievements::at($n)['frame']);
        }
    }

    public function test_portrait_frames_are_earned_selectable_and_independent_of_tier_and_character(): void
    {
        $u = $this->user();
        $data = ['display_name' => 'Chef Q', 'avatar' => 'rice', 'pins' => []];
        foreach (['silver', 'gold', 'sakura', 'unrecognized'] as $frame) {
            $this->postJson('/profile', [...$data, 'avatar_frame' => $frame])->assertUnprocessable();
        }
        $this->assertNull($u->fresh()->avatar_frame);
        $questions = Question::all()->unique('stem_hash')->take(600)->values();
        $complete = fn ($items) => Progress::insert($items->map(fn ($q) => ['user_id' => $u->id, 'question_id' => $q->id, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()])->all());
        $complete($questions->take(200));
        $this->assertSame('Maki Explorer', Achievements::for($u->id)['name']);
        $this->assertSame('silver', Closet::profile($u->fresh())['avatar']['frame']);
        $this->postJson('/profile', [...$data, 'avatar_frame' => 'gold'])->assertUnprocessable();
        $this->post('/profile', [...$data, 'avatar_frame' => 'silver'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/profile', [...$data, 'avatar_frame' => 'bronze'])->assertRedirect()->assertSessionHasNoErrors();
        $complete($questions->slice(200));
        $this->assertSame('Temaki Trailblazer', Achievements::for($u->id)['name']);
        $this->assertSame('bronze', Closet::profile($u->fresh())['avatar']['frame']);
        foreach (['sakura', 'silver', 'gold', 'bronze'] as $frame) {
            $this->post('/profile', [...$data, 'avatar_frame' => $frame])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($frame, Closet::profile($u->fresh())['avatar']['frame']);
        }
        DB::table('earned_badges')->insert(['user_id' => $u->id, 'badge' => 'fresh_perfect', 'earned_at' => now()]);
        $this->post('/profile/equip', ['avatar' => 'toro'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('bronze', Closet::profile($u->fresh())['avatar']['frame']);
        $this->assertSame('toro', Closet::profile($u->fresh())['avatar']['id']);
        $this->assertSame('Temaki Trailblazer', $this->get('/')->assertOk()->viewData('page')['props']['achievement']['name']);
        $public = $this->get('/profiles/'.$u->id)->assertOk()->viewData('page')['props'];
        $this->assertSame('Temaki Trailblazer', $public['tier']['name']);
        $this->assertSame('bronze', $public['profile']['avatar']['frame']);
        $this->assertNull(User::where('username', 'hannahquintero')->firstOrFail()->avatar_frame);
    }

    public function test_counts_match_unique_pool_and_reject_invalid_custom_scope(): void
    {
        $this->user();
        $r = $this->postJson('/study/available', $this->settings())->assertOk()->json();
        $unique = collect(app(Catalog::class)->items())->unique(fn ($q) => Catalog::stemHash($q['stem']));
        $this->assertSame($unique->count(), $r['available']);
        $this->assertNull($r['problem']);
        $this->assertArrayNotHasKey('items', $r);
        $this->postJson('/study/available', $this->settings(['topic_scope' => 'custom', 'topics' => []]))->assertUnprocessable();
        $r = $this->postJson('/study/available', $this->settings(['count' => 10000]))->assertOk()->json();
        $this->assertNotNull($r['problem']);
    }

    public function test_blueprint_enforces_counts_and_replacement_keeps_subject(): void
    {
        $this->user();
        $allocation = array_fill_keys(Catalog::SUBJECTS, 2);
        $draft = $this->postJson('/exams/preview', $this->settings(['count' => 24, 'allocation' => $allocation]))->assertOk()->json();
        $this->assertEqualsCanonicalizing($allocation, collect($draft['items'])->countBy('subject')->all());
        $this->assertSame(0, $draft['revision']);
        $old = $draft['items'][0];
        $replacement = $this->postJson('/exam-drafts/'.$draft['id'].'/replace', ['position' => 0, 'revision' => 0])->assertOk()->json();
        $this->assertSame($old['subject'], $replacement['items'][0]['subject']);
        $this->assertNotSame($old['id'], $replacement['items'][0]['id']);
        $this->assertCount(24, array_unique(array_column($replacement['items'], 'id')));
        $this->postJson('/exam-drafts/'.$draft['id'].'/replace', ['position' => 0, 'revision' => 0])->assertConflict();
        $this->postJson('/exams/preview', $this->settings(['allocation' => ['Internal Medicine' => 19]]))->assertUnprocessable();
        $this->postJson('/exams/preview', $this->settings(['allocation' => ['Bogus' => 20]]))->assertUnprocessable();
    }

    public function test_reviewed_exam_is_owned_idempotent_and_frozen(): void
    {
        $this->user();
        $d = $this->postJson('/exams/preview', $this->settings())->assertOk()->json();
        $this->user('hannahquintero');
        $this->postJson('/exam-drafts/'.$d['id'].'/replace', ['position' => 0, 'revision' => 0])->assertForbidden();
        $this->postJson('/exam-drafts/'.$d['id'].'/save', ['revision' => 0])->assertForbidden();
        $this->user();
        $original = ExamDraft::findOrFail($d['id'])->items;
        $this->post('/exam-drafts/'.$d['id'].'/save', ['revision' => 0, 'items' => [['key' => 'forged']]])->assertRedirect();
        $this->post('/exam-drafts/'.$d['id'].'/save', ['revision' => 0])->assertRedirect();
        $this->assertDatabaseCount('exams', 1);
        $exam = Exam::first();
        $this->assertSame($original, $exam->items);
        $this->postJson('/exam-drafts/'.$d['id'].'/replace', ['position' => 0, 'revision' => 0])->assertConflict();
        $copy = $this->postJson('/exams/'.$exam->id.'/duplicate')->assertOk()->json();
        $this->postJson('/exam-drafts/'.$copy['id'].'/replace', ['position' => 0, 'revision' => 0])->assertOk();
        $this->assertSame($original, $exam->fresh()->items);
        $this->post('/exam-drafts/'.$copy['id'].'/save', ['revision' => 1])->assertRedirect();
        $this->assertDatabaseCount('exams', 2);
        $this->assertNotSame(Exam::first()->code, Exam::latest('id')->first()->code);
    }

    public function test_expired_preview_cannot_be_saved(): void
    {
        $this->user();
        $d = $this->postJson('/exams/preview', $this->settings())->assertOk()->json();
        $this->travel(25)->hours();
        $this->postJson('/exam-drafts/'.$d['id'].'/save', ['revision' => 0])->assertStatus(410);
        $this->assertDatabaseCount('exams', 0);
    }

    public function test_presets_are_private_and_update_by_name(): void
    {
        $this->user();
        $this->post('/exams/presets', [...$this->settings(), 'name' => 'My recipe'])->assertRedirect();
        $this->post('/exams/presets', [...$this->settings(['count' => 40]), 'name' => 'My recipe'])->assertRedirect();
        $this->assertDatabaseCount('study_presets', 1);
        $props = $this->get('/exams')->assertOk()->viewData('page')['props'];
        $this->assertCount(1, $props['presets']);
        $this->assertSame(40, $props['presets'][0]['settings']['count']);
        $this->user('hannahquintero');
        $this->assertCount(0, $this->get('/exams')->assertOk()->viewData('page')['props']['presets']);
    }

    public function test_tray_creates_exact_deck_and_tutor_round_and_rejects_inactive_questions(): void
    {
        $this->user();
        $ids = Question::where('active', true)->orderBy('position')->limit(3)->pluck('id')->all();
        $this->post('/library/study', ['mode' => 'memory', 'selected_ids' => $ids])->assertRedirect();
        $this->assertEqualsCanonicalizing($ids, FlashcardDeck::first()->items->pluck('question_id')->all());
        $this->post('/library/study', ['mode' => 'tutor', 'selected_ids' => $ids])->assertRedirect();
        $this->assertEqualsCanonicalizing($ids, Attempt::first()->items->pluck('question_id')->all());
        $this->postJson('/library/study', ['mode' => 'memory', 'selected_ids' => [$ids[0], $ids[0]]])->assertUnprocessable();
        Question::whereKey($ids[0])->update(['active' => false]);
        $this->postJson('/library/study', ['mode' => 'tutor', 'selected_ids' => $ids])->assertUnprocessable();
    }

    public function test_roll_call_views_keep_learner_progress_separate(): void
    {
        $u = $this->user();
        $q = Question::where('active', true)->first();
        Progress::create(['user_id' => $u->id, 'question_id' => $q->id, 'completed_at' => now(), 'latest_correct' => false, 'bookmarked' => true]);
        foreach (['missed', 'bookmarks'] as $view) {
            $props = $this->get('/library?view='.$view)->assertOk()->viewData('page')['props'];
            $this->assertSame(1, $props['questions']['total']);
            $this->assertSame($q->id, $props['questions']['data'][0]['id']);
        }
        $props = $this->get('/library?view=new&search='.$q->id)->assertOk()->viewData('page')['props'];
        $this->assertSame(0, $props['questions']['total']);
        $this->user('hannahquintero');
        $this->assertSame(0, $this->get('/library?view=missed')->assertOk()->viewData('page')['props']['questions']['total']);
    }
}
