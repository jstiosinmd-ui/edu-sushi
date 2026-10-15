<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Exam;
use App\Models\FlashcardDeck;
use App\Models\Question;
use App\Models\User;
use App\Services\Catalog;
use App\Services\CatchReminders;
use App\Services\Habits;
use App\Services\Learning;
use App\Services\PushTransport;
use App\Services\Study;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CommunityTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function learner(): User
    {
        $user = User::where('username', 'powquanico')->firstOrFail();
        $user->update(['must_change_password' => false]);
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
        $this->withoutVite();

        return $user;
    }

    private function settings(array $extra = []): array
    {
        return array_replace(['mode' => 'fresh', 'count' => 10, 'subjects' => Catalog::SUBJECTS, 'topics' => [], 'difficulty' => 'All', 'seed' => 12345, 'balance' => false, 'pool' => 'all', 'minutes' => 10], $extra);
    }

    private function completeCatch(User $u): Attempt
    {
        $study = app(Study::class);
        $a = $study->start($u, $this->settings());
        foreach ($a->items as $item) {
            $a = $study->answer($a, $item->id, ['answer' => $item->snapshot['key'], 'revision' => $a->revision, 'check' => true]);
        }

        return $study->finish($a);
    }

    private function props(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    public function test_catch_resumes_awards_once_and_counts_a_local_day(): void
    {
        $u = $this->learner();
        $this->travelTo(Carbon::parse('2026-10-14 15:55:00', 'UTC'));
        $study = app(Study::class);
        $a = $study->start($u, $this->settings());
        $this->assertSame($a->id, $study->start($u, $this->settings(['seed' => 9]))->id);
        $this->post('/attempts/'.$a->id.'/finish')->assertSessionHasErrors('answers');
        $a = $this->completeCatch($u);
        $this->post('/attempts/'.$a->id.'/finish')->assertRedirect();
        $this->assertDatabaseCount('daily_catches', 1);
        $this->assertDatabaseHas('daily_catches', ['user_id' => $u->id, 'day' => '2026-10-14']);
        $home = $this->props('/');
        $this->assertTrue($home['daily']['completed_today']);
        $this->assertSame(1, $home['community']['habits']['current']);
        $this->assertDatabaseHas('earned_badges', ['user_id' => $u->id, 'badge' => 'fresh_first']);
        $this->assertDatabaseHas('earned_badges', ['user_id' => $u->id, 'badge' => 'fresh_perfect']);
        $this->post('/profile', ['display_name' => 'Captain Q', 'avatar' => 'catch', 'pins' => ['fresh_first']])->assertRedirect();
        $this->travelTo(Carbon::parse('2026-10-14 16:05:00', 'UTC'));
        $this->assertFalse($this->props('/')['daily']['completed_today']);
    }

    public function test_streak_saves_preserve_continuity_without_adding_days(): void
    {
        $u = $this->learner();
        for ($day = 1; $day <= 7; $day++) {
            $this->travelTo(Carbon::parse(sprintf('2026-10-%02d 10:00:00', $day), 'Asia/Manila'));
            $this->completeCatch($u);
        }
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'Asia/Manila'));
        $h = app(Habits::class)->for($u);
        $this->assertSame(7, $h['current']);
        $this->assertSame(7, $h['total_days']);
        $this->assertSame(0, $h['saves']);
        $this->assertSame('protected', collect($h['calendar'])->firstWhere('day', '2026-10-08')['state']);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'Asia/Manila'));
        $h = app(Habits::class)->for($u);
        $this->assertSame(0, $h['current']);
        $this->assertSame(7, $h['longest']);
        $this->completeCatch($u);
        $h = app(Habits::class)->for($u);
        $this->assertSame(1, $h['current']);
        $this->assertSame(8, $h['total_days']);
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00', 'Asia/Manila'));
        $this->completeCatch($u);
        $this->assertSame(1, app(Habits::class)->for($u)['comeback']);
    }

    public function test_leaderboard_deduplicates_modes_resets_week_and_excludes_admin(): void
    {
        $u = $this->learner();
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00', 'Asia/Manila'));
        $q = Question::first()->currentVersion->content;
        $learning = app(Learning::class);
        $learning->answer($u->id, 'qa:one', $q, 'fresh', false);
        $this->travel(1)->seconds();
        $learning->answer($u->id, 'qa:two', $q, 'memory', true);
        $learning->answer($u->id, 'qa:two', $q, 'memory', true);
        $this->assertDatabaseCount('study_events', 2);
        $board = $this->props('/leaderboard')['board'];
        $mine = collect($board['rows'])->firstWhere('is_you', true);
        $this->assertSame(1, $mine['points']);
        $this->assertSame(1, $mine['coverage']);
        $this->assertCount(4, $board['rows']);
        $this->assertArrayNotHasKey('username', $mine['profile']);
        $this->assertArrayNotHasKey('email', $mine['profile']);
        $this->travelTo(Carbon::parse('2026-10-19 00:00:01', 'Asia/Manila'));
        $this->assertSame(0, collect($this->props('/leaderboard')['board']['rows'])->firstWhere('is_you', true)['points']);
        $learning->answer($u->id, 'qa:next-week', $q, 'memory', true);
        $this->assertSame(2, collect($this->props('/leaderboard')['board']['rows'])->firstWhere('is_you', true)['points']);
        $this->assertSame(1, collect($this->props('/leaderboard?view=coverage')['board']['rows'])->firstWhere('is_you', true)['value']);
    }

    public function test_memory_goal_rounds_up_and_survives_refresh_finish_resume(): void
    {
        $u = $this->learner();
        $items = array_slice(app(Catalog::class)->items(), 0, 17);
        $deck = FlashcardDeck::create(['user_id' => $u->id, 'title' => 'Seventeen', 'settings' => []]);
        foreach ($items as $i => $q) {
            $deck->items()->create(['question_id' => $q['id'], 'snapshot' => $q, 'confidence' => $i < 13 ? 5 : null]);
        }
        $path = '/flashcards/decks/'.$deck->id;
        $data = $this->props($path)['deck'];
        $this->assertSame(14, $data['goal_target']);
        $this->assertFalse($data['goal_prompt']);
        $deck->items()->where('question_id', $items[13]['id'])->update(['confidence' => 5]);
        $this->assertTrue($this->props($path)['deck']['goal_prompt']);
        $this->postJson($path.'/session', ['action' => 'continue', 'session_number' => 1])->assertOk()->assertJsonPath('deck.goal_prompt', false);
        $this->assertFalse($this->props($path)['deck']['goal_prompt']);
        $this->postJson($path.'/session', ['action' => 'finish', 'session_number' => 1])->assertOk()->assertJsonPath('deck.finished', true)->assertJsonPath('deck.question', null);
        $this->assertTrue($this->props($path)['deck']['finished']);
        $this->postJson($path.'/session', ['action' => 'resume', 'session_number' => 1])->assertOk()->assertJsonPath('deck.session_number', 2)->assertJsonPath('deck.goal_prompt', true);
        $this->postJson($path.'/session', ['action' => 'finish', 'session_number' => 1])->assertConflict();
        $this->assertDatabaseMissing('earned_badges', ['user_id' => $u->id, 'badge' => 'memory_ready']);
        $other = User::where('username', 'hannahquintero')->first();
        $this->actingAs($other)->withSession(['auth_version' => $other->auth_version]);
        $other->update(['must_change_password' => false]);
        $this->postJson($path.'/session', ['action' => 'finish', 'session_number' => 2])->assertForbidden();
    }

    public function test_subject_rewards_and_pinned_badges_are_permanent_and_server_checked(): void
    {
        $u = $this->learner();
        $learning = app(Learning::class);
        foreach (Question::where('subject', 'Internal Medicine')->with('currentVersion')->limit(50)->get() as $q) {
            $learning->answer($u->id, 'subject:'.$q->id, $q->currentVersion->content, 'memory', true);
        }
        $this->props('/passport');
        $this->assertDatabaseHas('earned_badges', ['user_id' => $u->id, 'badge' => 'subject_im_50']);
        $this->post('/profile', ['display_name' => 'Miso', 'avatar' => 'im', 'pins' => ['subject_im_50']])->assertRedirect();
        $this->postJson('/profile', ['display_name' => 'Miso', 'avatar' => 'im-gold', 'pins' => []])->assertUnprocessable();
        $this->postJson('/profile', ['display_name' => 'Miso', 'avatar' => 'im', 'pins' => ['subject_im_200']])->assertUnprocessable();
        $this->props('/passport');
        $this->assertSame(1, DB::table('earned_badges')->where('user_id', $u->id)->where('badge', 'subject_im_50')->count());
        $this->get('/profiles/'.$u->id)->assertOk();
    }

    public function test_profiles_only_allow_characters_and_never_serve_old_photos(): void
    {
        $u = $this->learner();
        $this->postJson('/profile', ['display_name' => 'Chef', 'avatar' => 'photo', 'pins' => []])->assertUnprocessable();
        $this->postJson('/profile', ['display_name' => 'Chef', 'avatar' => 'rice', 'pins' => [], 'photo' => UploadedFile::fake()->image('portrait.png')])->assertUnprocessable();
        $u->update(['avatar' => 'photo', 'photo_path' => 'profiles/private-history.jpg']);
        $this->assertSame('rice', $this->props('/profile')['profile']['avatar']['id']);
        $this->get('/profiles/'.$u->id.'/photo')->assertNotFound();
        $this->assertSame('profiles/private-history.jpg', $u->fresh()->photo_path);
    }

    public function test_library_view_and_bookmark_achievements_require_recorded_actions(): void
    {
        $u = $this->learner();
        $ids = Question::limit(10)->pluck('id');
        $this->props('/library');
        $this->assertDatabaseCount('learning_views', 0);
        foreach ($ids as $id) {
            $this->post('/bookmarks/'.$id, ['bookmarked' => true])->assertRedirect();
        }
        $this->props('/passport');
        $this->assertDatabaseHas('earned_badges', ['user_id' => $u->id, 'badge' => 'library_notes']);
        $this->postJson('/questions/'.$ids[0].'/view', ['mode' => 'library'])->assertOk();
        $this->postJson('/questions/'.$ids[0].'/view', ['mode' => 'library'])->assertOk();
        $this->assertDatabaseCount('learning_views', 1);
        foreach ($ids as $id) {
            $this->post('/bookmarks/'.$id, ['bookmarked' => false]);
        }
        $this->props('/passport');
        $this->assertDatabaseHas('earned_badges', ['user_id' => $u->id, 'badge' => 'library_notes']);
    }

    public function test_push_preferences_validate_endpoints_and_timezone_changes(): void
    {
        $u = $this->learner();
        config(['sushi.push.public_key' => 'test', 'sushi.push.private_key' => 'test']);
        $this->postJson('/reminders/subscribe', ['endpoint' => 'https://127.0.0.1/private', 'keys' => ['p256dh' => base64_encode(str_repeat('a', 65)), 'auth' => base64_encode(str_repeat('b', 16))]])->assertUnprocessable();
        $this->post('/reminders/settings', ['timezone' => 'America/New_York', 'reminder_time' => '19:30', 'reminder_enabled' => false])->assertRedirect();
        $this->postJson('/reminders/settings', ['timezone' => 'UTC', 'reminder_time' => '19:30', 'reminder_enabled' => false])->assertUnprocessable();
        $this->postJson('/reminders/settings', ['timezone' => 'Invalid/Zone', 'reminder_time' => '25:99', 'reminder_enabled' => false])->assertUnprocessable();
        $this->post('/reminders/dismiss', ['action' => 'skip'])->assertRedirect();
        $this->assertFalse(app(CatchReminders::class)->incomplete($u->fresh()));
    }

    public function test_notifications_send_once_and_recheck_completion_before_sending(): void
    {
        $u = $this->learner();
        $this->travelTo(Carbon::parse('2026-10-14 20:00:00', 'Asia/Manila'));
        config(['sushi.push.public_key' => 'test', 'sushi.push.private_key' => 'test']);
        $u->update(['reminder_enabled' => true, 'last_active_at' => null]);
        DB::table('push_subscriptions')->insert(['user_id' => $u->id, 'endpoint_hash' => hash('sha256', 'test'), 'subscription' => Crypt::encryptString(json_encode(['endpoint' => 'https://fcm.googleapis.com/test'])), 'created_at' => now(), 'updated_at' => now()]);
        $transport = new class implements PushTransport
        {
            public array $sent = [];

            public function send(array $s, array $p): string
            {
                $this->sent[] = $p;

                return 'sent';
            }
        };
        $this->app->instance(PushTransport::class, $transport);
        $reminders = app(CatchReminders::class);
        // Queue faking isolates dispatch timing; the actual job executes below against the real database.
        Queue::fake();
        $this->assertSame(1, $reminders->dispatchDue());
        $this->assertSame(0, $reminders->dispatchDue());
        $id = DB::table('catch_reminders')->value('id');
        $reminders->send($id, $transport);
        $reminders->send($id, $transport);
        $this->assertCount(1, $transport->sent);
        $this->assertDatabaseHas('catch_reminders', ['id' => $id, 'status' => 'sent']);
        $this->travel(1)->days();
        $u->update(['last_active_at' => null]);
        $this->assertSame(1, $reminders->dispatchDue());
        $id2 = DB::table('catch_reminders')->max('id');
        $this->completeCatch($u);
        $reminders->send($id2, $transport);
        $this->assertCount(1, $transport->sent);
        $this->assertDatabaseHas('catch_reminders', ['id' => $id2, 'status' => 'cancelled']);
    }

    public function test_profile_has_starter_avatar_and_rejects_locked_portraits(): void
    {
        $user = $this->learner();
        $this->get('/profile')->assertOk();
        $this->post('/profile', ['display_name' => 'Chef Q', 'bio' => '', 'avatar' => 'rice', 'pins' => []])->assertRedirect();
        $this->assertSame('Chef Q', $user->fresh()->display_name);
        $this->postJson('/profile', ['display_name' => 'Chef Q', 'avatar' => 'im', 'pins' => []])->assertUnprocessable();
    }

    public function test_memory_award_persists_when_confidence_later_falls(): void
    {
        $u = $this->learner();
        $deck = FlashcardDeck::create(['user_id' => $u->id, 'title' => 'Twenty', 'settings' => []]);
        foreach (array_slice(app(Catalog::class)->items(), 0, 20) as $q) {
            $deck->items()->create(['question_id' => $q['id'], 'snapshot' => $q, 'confidence' => 5]);
        }
        $this->props('/passport');
        $this->assertDatabaseHas('earned_badges', ['user_id' => $u->id, 'badge' => 'memory_ready']);
        $this->assertDatabaseHas('earned_badges', ['user_id' => $u->id, 'badge' => 'memory_golden']);
        $deck->items()->update(['confidence' => 1]);
        $badges = $this->props('/passport')['badges'];
        $this->assertNotNull(collect($badges)->firstWhere('id', 'memory_golden')['earned_at']);
        $this->post('/profile', ['display_name' => 'Memo', 'avatar' => 'gunkan', 'pins' => ['memory_golden']])->assertRedirect();
    }

    public function test_tutor_timed_and_builder_awards_require_their_specific_work(): void
    {
        $u = $this->learner();
        $study = app(Study::class);
        foreach (['tutor' => 10, 'exam' => 40] as $mode => $count) {
            $attempt = $study->start($u, $this->settings(['mode' => $mode, 'count' => $count, 'subjects' => Catalog::CORE_SUBJECTS, 'balance' => $mode === 'exam']));
            foreach ($attempt->items as $item) {
                $attempt = $study->answer($attempt, $item->id, ['answer' => $item->snapshot['key'], 'revision' => $attempt->revision, 'check' => $mode === 'tutor']);
            }
            $attempt = $study->finish($attempt);
        }
        $items = $attempt->items->pluck('snapshot')->all();
        for ($i = 0; $i < 5; $i++) {
            $exam = Exam::create(['user_id' => $u->id, 'title' => 'Same set '.$i, 'code' => 'COMMUNITY-'.$i, 'items' => $items, 'settings' => []]);
            $exam->exports()->create(['user_id' => $u->id, 'status' => 'ready', 'explanations' => true]);
        }
        $badges = collect($this->props('/passport')['badges']);
        $this->assertCount(53, $badges);
        foreach (['tutor_first', 'rush_first', 'rush_cool', 'rush_four', 'bento_first', 'bento_four', 'bento_serve', 'bento_chef'] as $id) {
            $this->assertNotNull($badges->firstWhere('id', $id)['earned_at'], $id);
        }
        $this->assertSame(10, $badges->firstWhere('id', 'tutor_100')['progress']);
        $this->assertSame(1, $badges->firstWhere('id', 'bento_prep')['progress']);
        $this->assertNull($badges->firstWhere('id', 'bento_prep')['earned_at']);
        $this->assertNull($badges->firstWhere('id', 'rush_triple')['earned_at']);
    }

    public function test_backfill_is_idempotent_and_does_not_invent_feedback_views(): void
    {
        $u = $this->learner();
        $this->completeCatch($u);
        $this->artisan('sushi:backfill-community')->assertSuccessful();
        $this->artisan('sushi:backfill-community')->assertSuccessful();
        $this->assertDatabaseCount('study_events', 10);
        $this->assertDatabaseCount('daily_catches', 1);
        $this->assertDatabaseCount('learning_views', 0);
        $this->assertSame(0, DB::table('study_events')->where('bookmarked', true)->count());
    }

    public function test_snooze_and_disabled_accounts_prevent_delivery(): void
    {
        $u = $this->learner();
        $this->travelTo(Carbon::parse('2026-10-14 20:00:00', 'Asia/Manila'));
        config(['sushi.push.public_key' => 'test', 'sushi.push.private_key' => 'test']);
        $u->update(['reminder_enabled' => true, 'last_active_at' => null]);
        $this->postJson('/reminders/subscribe', ['endpoint' => 'https://fcm.googleapis.com/qa', 'keys' => ['p256dh' => base64_encode(str_repeat('a', 65)), 'auth' => base64_encode(str_repeat('b', 16))]])->assertOk();
        $this->assertStringNotContainsString('fcm.googleapis.com', DB::table('push_subscriptions')->value('subscription'));
        Queue::fake();
        $reminders = app(CatchReminders::class);
        $this->assertSame(1, $reminders->dispatchDue());
        $this->post('/reminders/dismiss', ['action' => 'snooze'])->assertRedirect();
        $this->assertSame(0, $reminders->dispatchDue());
        $this->travel(61)->minutes();
        $this->assertSame(1, $reminders->dispatchDue());
        $u->update(['disabled' => true]);
        $transport = new class implements PushTransport
        {
            public function send(array $s, array $p): string
            {
                throw new \LogicException('A disabled account must not receive push.');
            }
        };
        $reminders->send(DB::table('catch_reminders')->value('id'), $transport);
        $this->assertDatabaseHas('catch_reminders', ['user_id' => $u->id, 'status' => 'cancelled']);
    }

    public function test_study_activity_tracks_active_heartbeats_without_counting_idle_time(): void
    {
        $u = $this->learner();
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'Asia/Manila'));
        $a = app(Study::class)->start($u, $this->settings());
        $url = '/attempts/'.$a->id.'/heartbeat';
        $this->postJson($url, ['active' => true])->assertOk();
        $this->travel(30)->seconds();
        $this->postJson($url, ['active' => true])->assertOk();
        $this->assertSame(30, $a->fresh()->active_seconds);
        $lastActive = $u->fresh()->last_active_at->timestamp;
        $this->travel(30)->seconds();
        $this->postJson($url, ['active' => false])->assertOk();
        $this->assertSame(30, $a->fresh()->active_seconds);
        $this->assertSame($lastActive, $u->fresh()->last_active_at->timestamp);
    }
}
