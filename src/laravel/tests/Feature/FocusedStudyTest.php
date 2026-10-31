<?php

namespace Tests\Feature;

use App\Models\FlashcardDeck;
use App\Models\User;
use App\Services\Catalog;
use App\Services\Study;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FocusedStudyTest extends TestCase
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

    public function test_fresh_finish_rejects_missing_answers_and_returns_saved_results_idempotently(): void
    {
        $user = $this->learner();
        $study = app(Study::class);
        $attempt = $study->start($user, ['mode' => 'fresh', 'count' => 10, 'subjects' => Catalog::SUBJECTS,
            'topics' => [], 'difficulty' => 'All', 'seed' => 123, 'pool' => 'all', 'minutes' => 30]);
        $url = '/attempts/'.$attempt->id.'/finish';
        $this->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('answers');
        $this->assertSame('active', $attempt->fresh()->status);
        foreach ($attempt->items as $item) {
            $attempt = $study->answer($attempt, $item->id, ['answer' => $item->snapshot['key'], 'revision' => $attempt->revision]);
        }
        $result = $this->postJson($url)->assertOk()->assertJsonPath('attempt.status', 'finished')
            ->assertJsonPath('attempt.score', 10)->assertJsonPath('community.habits.completed_today', true)->json();
        $events = DB::table('study_events')->where('user_id', $user->id)->count();
        $this->postJson($url)->assertOk()->assertJsonPath('attempt.revision', $result['attempt']['revision']);
        $this->assertSame($events, DB::table('study_events')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('daily_catches')->where('user_id', $user->id)->count());
        $other = User::where('username', 'hannahquintero')->firstOrFail();
        $other->update(['must_change_password' => false]);
        $this->actingAs($other)->withSession(['auth_version' => $other->auth_version]);
        $this->postJson($url)->assertForbidden();
    }

    public function test_memory_entry_resumes_owned_active_deck_but_new_and_saved_views_stay_available(): void
    {
        $user = $this->learner();
        $settings = ['subjects' => ['Pediatrics'], 'topic_scope' => 'all', 'topics' => [], 'difficulty' => 'All', 'range_start' => 1, 'range_end' => 20];
        $preview = $this->postJson('/flashcards/preview', $settings)->assertOk()->json();
        $this->assertCount(20, $preview['questions']);
        $this->assertArrayNotHasKey('key', $preview['questions'][0]);
        $this->post('/flashcards/decks', [...$settings, 'title' => 'Focused deck', 'selected_ids' => array_column($preview['questions'], 'id')])->assertRedirect();
        $deck = FlashcardDeck::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->get('/flashcards')->assertRedirect('/flashcards/decks/'.$deck->id);
        $this->get('/flashcards?new=1')->assertOk();
        $props = $this->get('/flashcards?decks=1')->assertOk()->viewData('page')['props'];
        $this->assertSame(20, $props['decks'][0]['items_count']);
        $this->assertSame(0, $props['decks'][0]['confident_count']);
        FlashcardDeck::create(['user_id' => $user->id, 'title' => 'Finished deck', 'settings' => $settings,
            'session_finished_at' => now(), 'updated_at' => now()->addMinute()]);
        $resumable = $this->get('/')->assertOk()->viewData('page')['props']['resumable'];
        $this->assertCount(1, $resumable);
        $this->assertSame('deck-'.$deck->id, $resumable[0]['id']);
        $this->assertSame('/flashcards/decks/'.$deck->id, $resumable[0]['url']);
        $this->assertSame('flashcards', $resumable[0]['mode']);
        $deck->update(['session_finished_at' => now()]);
        $this->get('/flashcards')->assertOk();
        $this->postJson('/flashcards/preview', [...$settings, 'topic_scope' => 'custom', 'topics' => []])->assertUnprocessable();
        $this->postJson('/flashcards/preview', [...$settings, 'range_end' => 501])->assertUnprocessable();
    }
}
