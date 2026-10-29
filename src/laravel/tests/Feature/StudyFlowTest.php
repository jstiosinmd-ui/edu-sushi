<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Exam;
use App\Models\Question;
use App\Models\User;
use App\Services\Catalog;
use App\Services\Draw;
use App\Services\ProgressScope;
use App\Services\Study;
use App\Services\StudySetup;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudyFlowTest extends TestCase
{
    use RefreshDatabase;

    private function bank(int $count = 24): array
    {
        Question::query()->update(['active' => false]);
        $items = [];
        for ($n = 1; $n <= $count; $n++) {
            $items[] = ['id' => 'FLOW-'.$n, 'subject' => $n <= 12 ? 'Anatomy' : 'Pathology', 'topic' => 'Topic '.($n % 3), 'tier' => 'Easy',
                'stem' => 'Synthetic study flow question '.$n, 'options' => ['One', 'Two', 'Three', 'Four'], 'key' => 'A',
                'rationale' => 'Synthetic rationale.', 'expl' => ['Yes', 'No', 'No', 'No'], 'raw' => ['Answer Source' => 'Test fixture']];
        }
        app(Catalog::class)->import(['items' => $items]);

        return $items;
    }

    private function learner(): User
    {
        $user = User::factory()->create(['role' => 'student', 'must_change_password' => false]);
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version])->withoutVite();

        return $user;
    }

    public function test_metadata_draw_preserves_selection_and_loads_the_selected_versions(): void
    {
        $items = $this->bank();
        $catalog = app(Catalog::class);
        $settings = ['subjects' => ['Anatomy'], 'topics' => [], 'difficulty' => 'All', 'seed' => 42, 'count' => 5];
        $candidates = $catalog->candidates();
        $this->assertArrayNotHasKey('rationale', $candidates[0]);
        $this->assertArrayNotHasKey('stem', $candidates[0]);
        $selected = app(Draw::class)->select($candidates, $settings);
        $this->assertSame(array_column(app(Draw::class)->select($catalog->items(), $settings), 'id'), array_column($selected, 'id'));
        $changed = $items[array_search($selected[0]['id'], array_column($items, 'id'))];
        $changed['rationale'] = 'A later correction.';
        $catalog->import(['items' => [$changed]], notifyCorrections: false);
        $snapshots = $catalog->hydrate($selected);
        $this->assertCount(5, $snapshots);
        $this->assertSame('Synthetic rationale.', $snapshots[0]['rationale']);
        $this->assertSame(1, $snapshots[0]['version']);
    }

    public function test_shared_setup_starts_each_mode_and_keeps_exam_feedback_hidden(): void
    {
        $this->bank();
        $user = $this->learner();
        $setup = ['mode' => 'practice', 'subjects' => ['Anatomy'], 'topics' => [], 'count' => 5, 'pool' => 'all', 'difficulty' => 'All', 'minutes' => 30];
        foreach (['practice', 'flashcards', 'exam'] as $mode) {
            $response = $this->post('/study/start', [...$setup, 'mode' => $mode])->assertRedirect();
            $this->assertSame($mode, $user->fresh()->study_preferences['mode']);
            $page = $this->get($response->headers->get('Location'))->assertOk()->viewData('page')['props'];
            if ($mode === 'flashcards') {
                $this->assertSame(5, $page['deck']['total']);
                $this->assertArrayNotHasKey('key', $page['deck']['question']);
            } else {
                $this->assertCount(5, $page['attempt']['items']);
                $this->assertArrayNotHasKey('key', $page['attempt']['items'][0]);
                if ($mode === 'exam') {
                    $a = $page['attempt'];
                    $this->postJson('/attempts/'.$a['id'].'/answer', ['item_id' => $a['items'][0]['item_id'], 'answer' => 'A', 'revision' => 0, 'check' => true])->assertUnprocessable();
                }
            }
        }
        $home = $this->get('/')->assertOk()->viewData('page')['props'];
        $this->assertCount(3, $home['resumable']);
        $this->assertSame('exam', $home['preferences']['mode']);
        $this->post('/study/start', [...$setup, 'count' => 100])->assertSessionHasErrors('count');
        $this->assertSame('exam', $user->fresh()->study_preferences['mode']);
        $this->post('/study/start', [...$setup, 'topics' => ['Gone']])->assertSessionHasErrors('topics');
    }

    public function test_remembered_subjects_and_topics_are_validated_against_the_current_bank(): void
    {
        $this->bank();
        $user = $this->learner();
        $user->update(['study_preferences' => ['mode' => 'gone', 'subjects' => ['Retired subject'], 'topics' => ['gone'], 'count' => 999, 'pool' => 'invalid']]);
        $p = app(StudySetup::class)->preferences($user);
        $this->assertSame(['Anatomy'], $p['subjects']);
        $this->assertSame([], $p['topics']);
        $this->assertSame('practice', $p['mode']);
        $this->assertSame(100, $p['count']);
    }

    public function test_catalog_revision_changes_on_bulk_retirement_and_rolls_back_with_it(): void
    {
        $this->bank();
        $catalog = app(Catalog::class);
        $before = $catalog->summary();
        DB::beginTransaction();
        Question::whereKey('FLOW-1')->update(['active' => false]);
        $after = $catalog->summary();
        $this->assertGreaterThan($before['revision'], $after['revision']);
        $this->assertSame(23, $after['total']);
        DB::rollBack();
        $this->assertSame($before, $catalog->summary());
    }

    private function answerAt(User $user, string $id, string $answer, string $day): void
    {
        $this->travelTo(Carbon::parse($day.' 10:00:00', 'Asia/Manila'));
        $study = app(Study::class);
        $q = app(Catalog::class)->items([$id])[0];
        $attempt = $study->start($user, ['mode' => 'tutor', 'subjects' => [$q['subject']], 'selected_ids' => [$id], 'count' => 1, 'seed' => 1, 'difficulty' => 'All']);
        $study->answer($attempt, $attempt->items()->first()->id, ['answer' => $answer, 'check' => true, 'revision' => 0]);
        $study->finish($attempt);
    }

    public function test_library_search_matches_literal_text_and_stays_paginated(): void
    {
        $items = $this->bank();
        $this->learner();
        $items[0]['stem'] = 'Synthetic match 50%_complete for library search.';
        app(Catalog::class)->import(['items' => [$items[0]]]);
        $page = $this->get('/library')->assertOk()->viewData('page')['props']['questions'];
        $this->assertSame(24, $page['total']);
        $this->assertCount(12, $page['data']);
        foreach (['50%_complete', 'FLOW-1', 'topic 1'] as $search) {
            $page = $this->get('/library?'.http_build_query(['search' => $search]))->assertOk()->viewData('page')['props']['questions'];
            $this->assertContains('FLOW-1', array_column($page['data'], 'id'));
        }
        $page = $this->get('/library?search=%25_')->assertOk()->viewData('page')['props']['questions'];
        $this->assertSame(['FLOW-1'], array_column($page['data'], 'id'));
        $this->get('/library?search[]=invalid')->assertSessionHasErrors('search');
    }

    public function test_unreleased_assigned_answers_cannot_enter_progress_review(): void
    {
        $this->bank();
        $user = $this->learner();
        $items = app(Catalog::class)->items(['FLOW-1']);
        $exam = Exam::create(['user_id' => $user->id, 'title' => 'Synthetic assigned exam', 'code' => 'FLOW-ASSIGNED', 'items' => $items, 'settings' => []]);
        $assignment = Assignment::create(['user_id' => $user->id, 'exam_id' => $exam->id, 'opens_at' => now()->subMinute(), 'closes_at' => now()->addHour(), 'minutes' => 5, 'max_attempts' => 1]);
        $study = app(Study::class);
        $attempt = $study->start($user, [], $assignment);
        $study->answer($attempt, $attempt->items()->first()->id, ['answer' => 'B', 'revision' => 0]);
        $study->finish($attempt);
        $scope = app(ProgressScope::class)->for($user->id);
        $this->assertSame(0, $scope['first_total']);
        $this->assertSame([], $scope['review_ids']);
        $this->post('/progress/review', [])->assertSessionHasErrors('review');
        $assignment->update(['release_answers' => true]);
        $scope = app(ProgressScope::class)->for($user->id);
        $this->assertSame(1, $scope['first_total']);
        $this->assertSame(['FLOW-1'], $scope['review_ids']);
    }

    public function test_progress_review_uses_latest_overall_answers_and_the_displayed_scope(): void
    {
        $this->bank();
        $user = $this->learner();
        $this->answerAt($user, 'FLOW-1', 'B', '2026-10-20');
        $this->answerAt($user, 'FLOW-1', 'A', '2026-10-29');
        $this->answerAt($user, 'FLOW-2', 'B', '2026-10-29');
        $this->answerAt($user, 'FLOW-4', 'B', '2026-10-29');
        $this->answerAt($user, 'FLOW-13', 'B', '2026-10-29');
        $this->answerAt($user, 'FLOW-2', 'A', '2026-10-30');
        $filters = ['period' => 'custom', 'subject' => 'Anatomy', 'from' => '2026-10-29', 'to' => '2026-10-29'];
        $scope = $this->get('/progress?'.http_build_query($filters))->assertOk()->viewData('page')['props']['stats']['scope'];
        $this->assertSame(2, $scope['first_total']);
        $this->assertSame(0, $scope['first_correct']);
        $this->assertSame(3, $scope['covered']);
        $this->assertSame(12, $scope['available']);
        $this->assertSame(1, $scope['need_review']);
        $this->assertSame('Topic 1', $scope['topics'][0]['topic']);
        $response = $this->post('/progress/review', [...$filters, 'review_subject' => 'Anatomy', 'review_topic' => "Anatomy\x1fTopic 1"])->assertRedirect();
        $page = $this->get($response->headers->get('Location'))->assertOk()->viewData('page')['props']['attempt'];
        $this->assertSame(['FLOW-4'], array_column($page['items'], 'id'));
        $this->post('/progress/review', [...$filters, 'review_subject' => 'Pathology'])->assertSessionHasErrors('review');
        $this->assertNull($user->fresh()->study_preferences);
    }
}
