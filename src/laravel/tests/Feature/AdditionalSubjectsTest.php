<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\FlashcardDeck;
use App\Models\Progress;
use App\Models\Question;
use App\Models\User;
use App\Services\Analytics;
use App\Services\Catalog;
use App\Services\Closet;
use App\Services\Learning;
use App\Services\Passport;
use App\Services\Study;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdditionalSubjectsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const SUBJECTS = ['preventive' => 'Preventive Medicine', 'anatomy' => 'Anatomy', 'legal' => 'Legal Medicine-Ethics', 'pathology' => 'Pathology', 'pharmacology' => 'Pharmacology', 'biochemistry' => 'Biochemistry', 'physiology' => 'Physiology', 'microbiology' => 'Microbiology-Parasitology'];

    private function learner(): User
    {
        $u = User::where('username', 'powquanico')->firstOrFail();
        $u->update(['must_change_password' => false]);
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version]);
        $this->withoutVite();

        return $u;
    }

    private function settings(array $extra = []): array
    {
        return array_replace(['mode' => 'tutor', 'count' => 10, 'subjects' => Catalog::SUBJECTS, 'topics' => [], 'difficulty' => 'All', 'seed' => 12345, 'balance' => false, 'pool' => 'all', 'minutes' => 10], $extra);
    }

    public function test_additional_bank_matches_all_raw_fields_and_is_released(): void
    {
        $bank = json_decode(file_get_contents(database_path('data/subjects-20261014-v1.1.json')), true);
        $this->assertCount(608, $bank['items']);
        $basic = json_decode(file_get_contents(database_path('data/basic-sciences-20261014-v1.0.json')), true);
        $this->assertCount(517, $basic['items']);
        $this->assertSame(['Pathology' => 194, 'Pharmacology' => 166, 'Biochemistry' => 157], $basic['counts']);
        $this->assertSame(['Preventive Medicine' => 247, 'Anatomy' => 189, 'Legal Medicine-Ethics' => 172], $bank['counts']);
        $final = json_decode(file_get_contents(database_path('data/physiology-microbiology-20261014-v1.0.json')), true);
        $this->assertCount(291, $final['items']);
        $this->assertSame(['Physiology' => 165, 'Microbiology-Parasitology' => 126], $final['counts']);
        $this->assertSame(2463, Question::count());
        $this->assertSame(2462, Question::distinct()->count('stem_hash'));
        foreach ([...$bank['items'], ...$basic['items'], ...$final['items']] as $q) {
            $raw = $q['raw'];
            $this->assertSame($raw['Question ID'], $q['id']);
            $this->assertSame($raw['Section Title'], $q['subject']);
            $this->assertSame($raw['Question'], $q['stem']);
            $this->assertSame($raw['Correct Answer'], $q['key']);
            $this->assertSame($raw['Rationale'], $q['rationale']);
            $this->assertSame(array_map(fn ($x) => $raw['Option '.$x], ['A', 'B', 'C', 'D']), $q['options']);
            $this->assertSame(array_map(fn ($x) => $raw['Explanation '.$x], ['A', 'B', 'C', 'D']), $q['expl']);
            $this->assertSame('Released', $q['status']);
            $this->assertSame($q, Question::findOrFail($q['id'])->currentVersion->content);
        }
    }

    public function test_twelve_subjects_work_in_library_analytics_and_balanced_builder(): void
    {
        $u = $this->learner();
        $this->assertSame(Catalog::SUBJECTS, $this->get('/practice')->assertOk()->viewData('page')['props']['catalog']['subjects']);
        foreach (self::SUBJECTS as $subject) {
            $page = $this->get('/library?'.http_build_query(['subject' => $subject]))->assertOk()->viewData('page')['props']['questions'];
            $this->assertSame(Question::where('subject', $subject)->count(), $page['total']);
            $this->assertSame([$subject], array_values(array_unique(array_column($page['data'], 'subject'))));
        }
        $coverage = collect(app(Analytics::class)->for($u->id)['coverage']);
        $this->assertCount(12, $coverage);
        $this->assertSame(2463, $coverage->sum('total'));
        $allocation = array_fill_keys(Catalog::SUBJECTS, 2);
        $draft = $this->postJson('/exams/preview', $this->settings(['title' => 'Twelve subject bento', 'count' => 24, 'balance' => true, 'allocation' => $allocation]))->assertOk()->json();
        $this->assertEqualsCanonicalizing($allocation, collect($draft['items'])->countBy('subject')->all());
    }

    public function test_each_new_subject_works_in_fresh_tutor_timed_and_retry_pool(): void
    {
        $u = $this->learner();
        foreach (self::SUBJECTS as $subject) {
            foreach (['fresh', 'tutor', 'exam'] as $mode) {
                $this->post('/attempts', $this->settings(['subjects' => [$subject], 'mode' => $mode]))->assertRedirect()->assertSessionHasNoErrors();
                $a = Attempt::latest('id')->firstOrFail();
                $this->assertCount(10, $a->items);
                $this->assertSame([$subject], $a->items->map(fn ($i) => $i->snapshot['subject'])->unique()->values()->all());
                $item = $a->items->first();
                $wrong = $item->snapshot['key'] === 'A' ? 'B' : 'A';
                $a = app(Study::class)->answer($a, $item->id, ['answer' => $wrong, 'revision' => $a->revision, 'check' => $mode !== 'exam']);
                foreach ($a->items()->whereNull('answer')->get() as $remaining) {
                    $a = app(Study::class)->answer($a, $remaining->id, ['answer' => $remaining->snapshot['key'], 'revision' => $a->revision, 'check' => $mode !== 'exam']);
                }
                app(Study::class)->finish($a);
                $pool = app(Study::class)->eligible($u->id, $this->settings(['mode' => 'fresh', 'subjects' => [$subject]]));
                $this->assertContains($item->question_id, array_column($pool, 'id'));
            }
        }
    }

    public function test_memory_accepts_twelve_subjects_and_rates_each_new_subject(): void
    {
        $this->learner();
        $filters = ['subjects' => Catalog::SUBJECTS, 'topics' => [], 'difficulty' => 'All', 'range_start' => 1, 'range_end' => 1];
        $this->postJson('/flashcards/preview', $filters)->assertOk()->assertJsonPath('total', 2463);
        foreach (self::SUBJECTS as $subject) {
            $s = [...$filters, 'subjects' => [$subject]];
            $p = $this->postJson('/flashcards/preview', $s)->assertOk()->json();
            $id = $p['questions'][0]['id'];
            $this->post('/flashcards/decks', [...$s, 'title' => $subject, 'selected_ids' => [$id]])->assertRedirect()->assertSessionHasNoErrors();
            $deck = FlashcardDeck::latest('id')->firstOrFail();
            $q = $this->get('/flashcards/decks/'.$deck->id)->assertOk()->viewData('page')['props']['deck']['question'];
            $this->assertSame($subject, $q['subject']);
            $this->assertArrayNotHasKey('key', $q);
            $key = Question::findOrFail($id)->currentVersion->content['key'];
            $this->postJson('/flashcards/decks/'.$deck->id.'/answer', ['question_id' => $id, 'answer' => $key, 'token' => $q['token']])->assertOk()->assertJsonPath('deck.question.correct', true);
            $this->postJson('/flashcards/decks/'.$deck->id.'/rate', ['question_id' => $id, 'confidence' => 5, 'token' => $q['token']])->assertOk()->assertJsonPath('deck.counts.5', 1)->assertJsonPath('deck.goal_prompt', true);
        }
    }

    public function test_subject_avatars_unlock_only_at_fifty_distinct_correct_answers(): void
    {
        $u = $this->learner();
        foreach (self::SUBJECTS as $id => $subject) {
            $this->postJson('/profile/equip', ['avatar' => $id])->assertUnprocessable();
            $questions = Question::where('subject', $subject)->orderBy('position')->limit(50)->with('currentVersion')->get();
            foreach ($questions->take(49) as $q) {
                app(Learning::class)->answer($u->id, 'subject-test-'.$q->id, $q->currentVersion->content, 'tutor', true);
            }
            app(Learning::class)->answer($u->id, 'duplicate-'.$id, $questions[0]->currentVersion->content, 'tutor', true);
            app(Learning::class)->answer($u->id, 'wrong-'.$id, $questions[49]->currentVersion->content, 'tutor', false);
            app(Learning::class)->answer($u->id, 'assigned-'.$id, $questions[49]->currentVersion->content, 'assigned', true);
            $badge = collect(app(Passport::class)->for($u))->firstWhere('id', 'subject_'.$id.'_50');
            $this->assertSame(49, $badge['progress']);
            $this->assertNull($badge['earned_at']);
            $this->postJson('/profile/equip', ['avatar' => $id])->assertUnprocessable();
            app(Learning::class)->answer($u->id, 'fiftieth-'.$id, $questions[49]->currentVersion->content, 'fresh', true);
            $badge = collect(app(Passport::class)->for($u))->firstWhere('id', 'subject_'.$id.'_50');
            $this->assertNotNull($badge['earned_at']);
            $this->assertSame($id, $badge['rewards'][0]['id']);
            $this->post('/profile/equip', ['avatar' => $id])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($id, Closet::profile($u->fresh())['avatar']['id']);
            $this->assertSame('bronze', Closet::profile($u->fresh())['avatar']['frame']);
        }
        $other = User::where('username', 'hannahquintero')->firstOrFail();
        foreach (self::SUBJECTS as $id => $subject) {
            $this->assertFalse(collect(Closet::all($other))->firstWhere('id', $id)['unlocked']);
        }
    }

    public function test_reseed_preserves_new_subject_edits_and_learner_history(): void
    {
        $u = $this->learner();
        $q = Question::where('subject', 'Anatomy')->firstOrFail();
        $original = $q->currentVersion->content;
        $a = app(Study::class)->start($u, $this->settings(['selected_ids' => [$q->id], 'count' => 1, 'subjects' => ['Anatomy']]));
        Progress::create(['user_id' => $u->id, 'question_id' => $q->id, 'bookmarked' => true, 'completed_at' => now()]);
        app(Catalog::class)->import(['items' => [[...$original, 'stem' => $original['stem'].' Updated by admin.']]]);
        $this->seed();
        $this->seed();
        $this->assertSame(2463, Question::count());
        $this->assertSame(2, $q->fresh()->currentVersion->version);
        $this->assertSame($original['stem'].' Updated by admin.', $q->fresh()->currentVersion->content['stem']);
        $this->assertSame($original['stem'], $a->items()->first()->snapshot['stem']);
        $this->assertTrue(Progress::where('user_id', $u->id)->where('question_id', $q->id)->firstOrFail()->bookmarked);
    }
}
