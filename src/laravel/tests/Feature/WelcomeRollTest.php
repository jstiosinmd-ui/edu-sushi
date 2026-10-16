<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FeatureUpdates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WelcomeRollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function account(string $role = 'student', bool $passwordChange = false): User
    {
        return User::create(['name' => 'Demo '.$role, 'username' => 'demo-'.$role,
            'password' => 'password', 'role' => $role, 'must_change_password' => $passwordChange]);
    }

    public function test_admin_and_learner_login_land_at_counter_even_with_a_stale_destination(): void
    {
        foreach (['student', 'admin'] as $role) {
            $user = $this->account($role);
            $this->withSession(['url.intended' => '/admin'])->post('/login', [
                'username' => $user->username, 'password' => 'password',
            ])->assertRedirect('/')->assertSessionMissing('url.intended');
            $this->assertAuthenticatedAs($user);
            $this->get('/login')->assertRedirect('/');
            $this->post('/logout');
        }
    }

    public function test_required_password_change_precedes_counter_and_release_popup(): void
    {
        $user = $this->account('admin', true);
        $this->withSession(['url.intended' => '/admin'])->post('/login', [
            'username' => $user->username, 'password' => 'password',
        ])->assertRedirect('/password');
        $page = $this->get('/password')->assertOk()->viewData('page');
        $this->assertNull($page['props']['featureUpdate']);
        $this->post('/password', ['current_password' => 'password', 'password' => 'demo-new-password',
            'password_confirmation' => 'demo-new-password'])->assertRedirect('/')->assertSessionMissing('url.intended');
        $this->assertTrue(FeatureUpdates::for($user->fresh())['unread']);
    }

    public function test_popup_acknowledgment_is_per_user_and_per_release(): void
    {
        $student = $this->account();
        $admin = $this->account('admin');
        DB::table('feature_update_reads')->insert(['user_id' => $student->id, 'release' => 'sushi-2.9', 'read_at' => now()]);
        $this->actingAs($student)->withSession(['auth_version' => $student->auth_version]);
        $page = $this->get('/tour')->assertOk()->viewData('page')['props'];
        $this->assertSame(FeatureUpdates::id(), $page['featureUpdate']['release']);
        $this->assertTrue($page['featureUpdate']['unread']);
        $this->postJson('/feature-updates/read', ['release' => 'sushi-2.9'])->assertUnprocessable();
        $this->postJson('/feature-updates/read', ['release' => FeatureUpdates::id()])->assertOk();
        $this->postJson('/feature-updates/read', ['release' => FeatureUpdates::id()])->assertOk();
        $this->assertFalse(FeatureUpdates::for($student)['unread']);
        $this->assertTrue(FeatureUpdates::for($admin)['unread']);
        $this->assertSame(1, DB::table('feature_update_reads')->where('release', FeatureUpdates::id())->count());
        // Read notes remain available for the account-menu replay.
        $this->assertNotEmpty(FeatureUpdates::for($student)['features']);
    }

    public function test_themed_guide_and_notes_show_only_role_appropriate_stations(): void
    {
        foreach (['student' => 7, 'admin' => 8] as $role => $stops) {
            $user = $this->account($role);
            $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
            $page = $this->get('/tour')->assertOk()->viewData('page');
            $this->assertSame('Tour', $page['component']);
            $this->assertSame('Sushi Bar Hop', $page['props']['tour']['title']);
            $this->assertCount($stops, $page['props']['tour']['stops']);
            $this->assertSame($role === 'admin', in_array('Head Chef', array_column($page['props']['tour']['stops'], 'name')));
            $adminNotes = array_filter($page['props']['featureUpdate']['features'], fn ($note) => ($note['audience'] ?? 'all') === 'admin');
            $this->assertSame($role === 'admin', count($adminNotes) > 0);
            $this->assertSame('Welcome Roll', $page['props']['release']['name']);
        }
    }
}
