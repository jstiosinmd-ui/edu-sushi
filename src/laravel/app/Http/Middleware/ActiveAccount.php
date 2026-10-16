<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if ($u->disabled || $u->archived_at || $u->invitation_pending || $request->session()->get('auth_version') !== $u->auth_version) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->withErrors(['username' => 'Please sign in again. Contact your administrator if your account is disabled.']);
        }
        if ($u->must_change_password && ! $request->is('password', 'logout')) {
            return redirect('/password');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
