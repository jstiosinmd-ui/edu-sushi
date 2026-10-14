<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\User;
use App\Services\Achievements;
use App\Services\Closet;
use App\Services\Habits;
use App\Services\Leaderboard;
use App\Services\Learning;
use App\Services\Passport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CommunityController extends Controller
{
    public static function summary(User $u): array
    {
        $badges = app(Passport::class)->for($u);

        return ['profile' => Closet::profile($u), 'habits' => app(Habits::class)->for($u), 'badge_count' => count(array_filter($badges, fn ($b) => $b['earned_at'])),
            'badge_total' => count($badges), 'recent_badges' => array_slice(array_values(array_filter($badges, fn ($b) => $b['earned_at'] && strtotime($b['earned_at']) >= now()->subMinutes(2)->timestamp)), 0, 3)];
    }

    public function passport(Request $r)
    {
        return Inertia::render('Passport', ['badges' => app(Passport::class)->for($r->user()), 'closet' => Closet::all($r->user())]);
    }

    public function leaderboard(Request $r)
    {
        $d = $r->validate(['view' => 'nullable|in:weekly,coverage,streak']);

        return Inertia::render('Leaderboard', ['board' => app(Leaderboard::class)->for($r->user(), $d['view'] ?? 'weekly'), 'definitions' => Passport::definitions()]);
    }

    public function profile(User $user)
    {
        abort_if($user->disabled, 404);
        $earned = DB::table('earned_badges')->where('user_id', $user->id)->pluck('earned_at', 'badge');

        return Inertia::render('PublicProfile', ['profile' => Closet::profile($user), 'badges' => array_values(array_filter(array_map(fn ($b) => [...$b, 'earned_at' => $earned[$b['id']] ?? null], Passport::definitions()), fn ($b) => $b['earned_at'])),
            'tier' => Achievements::for($user->id), 'habits' => app(Habits::class)->for($user)]);
    }

    public function view(Request $r, Question $question)
    {
        $d = $r->validate(['mode' => 'required|in:library']);
        abort_unless($question->active, 404);
        app(Learning::class)->view($r->user()->id, $question->currentVersion->content, $d['mode']);

        return response()->json(['community' => self::summary($r->user())]);
    }
}
