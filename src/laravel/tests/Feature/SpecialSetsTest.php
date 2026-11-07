<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\SpecialSets;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SpecialSetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null,
            'app.key' => 'base64:'.base64_encode(str_repeat('s', 32))]);
        DB::purge('sqlite');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username');
            $table->string('password');
            $table->string('role')->default('student');
            $table->integer('auth_version')->default(1);
            $table->boolean('must_change_password')->default(false);
            $table->boolean('disabled')->default(false);
            $table->boolean('invitation_pending')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_11_08_000001_create_special_set_progress.php'))->up();
        // Exclude unrelated community aggregates; keep authentication and active-account checks.
        $this->withoutMiddleware(HandleInertiaRequests::class)->withoutVite();
    }

    private function learner(string $name = 'fixture'): User
    {
        $user = User::create(['username' => $name, 'name' => $name, 'password' => 'local-test-only']);
        $user->refresh();
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);

        return $user;
    }

    public function test_all_five_banks_are_complete_and_valid(): void
    {
        $ids = [];
        $review = 0;
        $five = 0;
        foreach (SpecialSets::SLUGS as $slug) {
            $set = app(SpecialSets::class)->load($slug);
            $this->assertCount(100, $set['items']);
            $this->assertSame(range(1, 100), array_column($set['items'], 'number'));
            foreach ($set['items'] as $q) {
                $this->assertNotContains($q['id'], $ids);
                $ids[] = $q['id'];
                $this->assertNotEmpty($q['stem']);
                $this->assertGreaterThan(60, strlen($q['rationale']));
                $this->assertSame(array_slice(['A', 'B', 'C', 'D', 'E'], 0, count($q['options'])), array_keys($q['options']));
                if ($q['review_only']) {
                    $this->assertNull($q['key']);
                    $review++;
                } else {
                    $this->assertArrayHasKey($q['key'], $q['options']);
                }
                if (count($q['options']) === 5) {
                    $five++;
                }
                if (isset($q['image'])) {
                    $this->assertFileExists(database_path('data/special-sets/'.$q['image']));
                }
            }
        }
        $this->assertCount(500, $ids);
        $this->assertSame(62, $review);
        $this->assertSame(18, $five);
    }

    public function test_authentication_and_unknown_paths_are_enforced(): void
    {
        $this->get('/special-sets')->assertRedirect('/login');
        $this->get('/special-set-assets/physiology-2027-002.png')->assertRedirect('/login');
        $user = $this->learner();
        $this->get('/special-sets/missing')->assertNotFound();
        $this->get('/special-set-assets/missing.png')->assertNotFound();
        $this->get('/special-set-assets/physiology-2027-002.png')->assertOk();
        $user->update(['disabled' => true]);
        $this->get('/special-sets')->assertRedirect('/login');
    }

    public function test_unanswered_keys_are_hidden_and_first_response_persists_per_user(): void
    {
        $this->learner();
        $set = app(SpecialSets::class)->load('pathology-2027');
        $page = $this->get('/special-sets/pathology-2027')->assertOk()->viewData('page')['props'];
        $this->assertCount(100, $page['questions']);
        $this->assertArrayNotHasKey('key', $page['questions'][0]);
        $this->assertArrayNotHasKey('rationale', $page['questions'][0]);
        $payload = ['number' => 1, 'hash' => $set['hash'], 'answer' => $set['items'][0]['key']];
        $this->postJson('/special-sets/pathology-2027/answers', $payload)->assertOk()->assertJsonPath('summary.correct', 1);
        $other = $payload['answer'] === 'A' ? 'B' : 'A';
        $this->postJson('/special-sets/pathology-2027/answers', [...$payload, 'answer' => $other])->assertOk()->assertJsonPath('feedback.answer', $payload['answer'])->assertJsonPath('summary.scored', 1);
        $page = $this->get('/special-sets/pathology-2027')->assertOk()->viewData('page')['props'];
        $this->assertSame($payload['answer'], ((array) $page['saved'])[1]['answer']);
        $this->learner('other-learner');
        $page = $this->get('/special-sets/pathology-2027')->assertOk()->viewData('page')['props'];
        $this->assertSame(0, $page['summary']['completed']);
        $this->assertCount(0, (array) $page['saved']);
    }

    public function test_review_only_items_do_not_change_score_and_invalid_requests_do_not_save(): void
    {
        $this->learner();
        $set = app(SpecialSets::class)->load('pathology-2027');
        $url = '/special-sets/pathology-2027/answers';
        $base = ['hash' => $set['hash']];
        $this->postJson($url, [...$base, 'number' => 2, 'answer' => null])->assertOk()->assertJsonPath('summary.scored', 0)->assertJsonPath('summary.reviewed', 1)->assertJsonPath('feedback.correct', null);
        $this->postJson($url, [...$base, 'number' => 2, 'answer' => 'A'])->assertUnprocessable();
        $this->postJson($url, [...$base, 'number' => 1, 'answer' => 'E'])->assertUnprocessable();
        $this->postJson($url, [...$base, 'number' => 1])->assertUnprocessable();
        $this->postJson($url, ['hash' => str_repeat('0', 64), 'number' => 1, 'answer' => 'A'])->assertStatus(409);
        $this->postJson($url, [...$base, 'number' => 101, 'answer' => 'A'])->assertUnprocessable();
        $q = $set['items'][45];
        $this->assertCount(5, $q['options']);
        $this->postJson($url, [...$base, 'number' => 46, 'answer' => 'E'])->assertOk()->assertJsonPath('summary.scored', 1)->assertJsonPath('summary.completed', 2);
        $this->assertSame(1, DB::table('special_set_progress')->count());
        $this->get('/special-sets')->assertOk();
    }
}
