<?php

namespace App\Http\Middleware;

use App\Http\Controllers\CommunityController;
use App\Services\Achievements;
use App\Services\FeatureUpdates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request), 'user' => $request->user()?->only('id', 'username', 'role', 'must_change_password'),
            'release' => fn () => FeatureUpdates::label(),
            'achievement' => fn () => $request->user() ? Achievements::for($request->user()->id) : null,
            'community' => fn () => $request->user() ? CommunityController::summary($request->user()) : null,
            'featureUpdate' => fn () => $request->user() && ! $request->user()->must_change_password ? FeatureUpdates::for($request->user()) : null,
            'unreadUpdates' => fn () => $request->user() ? DB::table('learning_notices')->where('user_id', $request->user()->id)->whereNull('read_at')->count() : 0,
            'flash' => fn () => $request->session()->get('message')];
    }
}
