<?php

namespace App\Http\Middleware;

use App\Services\Achievements;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request), 'user' => $request->user()?->only('id', 'username', 'role', 'must_change_password'),
            'achievement' => fn () => $request->user() ? Achievements::for($request->user()->id) : null,
            'flash' => fn () => $request->session()->get('message')];
    }
}
