<?php

namespace Tests\Feature;

use App\Models\Progress;
use App\Models\Question;
use App\Models\QuestionVersion;
use App\Models\User;
use App\Services\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TopUpTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function signIn(string $username): User
    {
        $user = User::where('username', $username)->firstOrFail();
        $user->update(['must_change_password' => false]);
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
        $this->withoutVite();

        return $user;
    }

    public function test_complete_bank_has_five_hundred_active_questions_per_subject(): void
    {
        $this->assertSame(6000, Question::where('active', true)->count());
        $this->assertEqualsCanonicalizing(array_fill_keys(Catalog::SUBJECTS, 500),
            Question::selectRaw('subject, count(*) as total')->groupBy('subject')->pluck('total', 'subject')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(0, QuestionVersion::where('content->status', '!=', 'Active')->count());
        $this->assertSame(0, QuestionVersion::where('content->raw->Review Status', '!=', 'Active')->count());
        $bundle = json_decode(file_get_contents(database_path('data/ple-top-up-20261016-v2.8.json')), true);
        $this->assertCount(3537, $bundle['items']);
        $this->assertCount(12, $bundle['sources']);
        foreach ($bundle['items'] as $item) {
            $this->assertSame($item, Question::findOrFail($item['id'])->currentVersion->content);
        }
    }

    public function test_activation_preserves_versions_history_edits_and_later_retirements(): void
    {
        $user = User::where('username', 'powquanico')->firstOrFail();
        $user->update(['password' => 'existing-changed-password']);
        $passwordHash = $user->password;
        $question = Question::firstOrFail();
        $old = $question->currentVersion;
        $content = $old->content;
        $content['stem'] .= ' Existing local edit.';
        $content['status'] = 'Draft';
        $content['raw']['Review Status'] = 'Draft';
        unset($content['source_review_status']);
        $old->update(['content' => $content, 'content_hash' => hash('sha256', json_encode($content))]);
        $question->update(['active' => false]);
        $progress = Progress::create(['user_id' => $user->id, 'question_id' => $question->id, 'bookmarked' => true, 'completed_at' => now(), 'latest_answer' => 'A']);
        $beforeProgress = $progress->fresh()->getAttributes();
        $noticeCount = DB::table('learning_notices')->count();
        DB::table('bank_releases')->where('id', 'ple-top-up-20261016')->delete();

        $this->seed();
        $this->assertTrue($question->fresh()->active);
        $this->assertSame('Draft', $old->fresh()->content['status']);
        $current = $question->fresh()->currentVersion;
        $this->assertSame($old->version + 1, $current->version);
        $this->assertSame('Active', $current->content['status']);
        $this->assertSame('Active', $current->content['raw']['Review Status']);
        $this->assertSame('Draft', $current->content['source_review_status']);
        $this->assertSame($content['stem'], $current->content['stem']);
        $this->assertSame($beforeProgress, $progress->fresh()->getAttributes());
        $this->assertSame($noticeCount, DB::table('learning_notices')->count());
        $this->assertSame($passwordHash, $user->fresh()->password);

        $question->fresh()->update(['active' => false]);
        $this->seed();
        $this->assertFalse($question->fresh()->active);
        $this->assertSame($current->id, $question->fresh()->current_version_id);
        $this->assertSame(6000, Question::count());
        $this->assertSame(1, User::where('username', 'powquanico')->count());
    }

    public function test_head_chef_can_add_hyphenated_learner_without_granting_admin_access(): void
    {
        $this->signIn('jstiosin');
        $this->post('/admin/users', ['username' => ' New-Learner ', 'password' => 'password', 'cohort' => 'Clerks 2026', 'role' => 'admin', 'must_change_password' => false])
            ->assertRedirect()->assertSessionHasNoErrors();
        $created = User::where('username', 'new-learner')->firstOrFail();
        $this->assertSame('student', $created->role);
        $this->assertSame('Clerks 2026', $created->cohort);
        $this->assertTrue($created->must_change_password);
        $this->assertTrue(Hash::check('password', $created->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'target' => (string) $created->id]);
        $this->post('/admin/users', ['username' => 'NEW-LEARNER', 'password' => 'password'])->assertSessionHasErrors('username');
        $this->post('/admin/users', ['username' => 'bad name', 'password' => 'password'])->assertSessionHasErrors('username');
        $this->post('/admin/users', ['username' => 'valid-name', 'password' => 'short'])->assertSessionHasErrors('password');
        $this->assertSame(5, User::count());
    }

    public function test_new_learner_cannot_create_accounts_and_can_login_with_initial_password(): void
    {
        $this->post('/admin/users', ['username' => 'guest-added', 'password' => 'password'])->assertRedirect('/login');
        $this->post('/login', ['username' => 'powquanico', 'password' => 'password'])->assertRedirect('/password');
        $this->assertAuthenticatedAs(User::where('username', 'powquanico')->firstOrFail());
        $this->post('/logout');
        $this->signIn('powquanico');
        $this->get('/admin')->assertForbidden();
        $this->post('/admin/users', ['username' => 'student-added', 'password' => 'password'])->assertForbidden();
        $this->assertSame(4, User::count());
    }
}
