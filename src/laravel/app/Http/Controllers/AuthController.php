<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $data = $r->validate(['username' => 'required|string|max:80', 'password' => 'required|string|max:255']);
        $username = mb_strtolower(trim($data['username']));
        $key = hash('sha256', $username.'|'.$r->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        if (! Auth::attempt(['username' => $username, 'password' => $data['password'], 'disabled' => false, 'invitation_pending' => false])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['username' => 'The username or password is incorrect.']);
        }
        RateLimiter::clear($key);
        $r->session()->regenerate();
        $r->session()->put('auth_version', $r->user()->auth_version);

        if ($r->user()->must_change_password) {
            return redirect('/password');
        }

        $r->session()->forget('url.intended');

        return redirect('/');
    }

    public function password(Request $r)
    {
        $d = $r->validate(['current_password' => 'required|current_password', 'password' => 'required|string|min:8|max:100|confirmed|different:current_password']);
        $u = $r->user();
        $u->update(['password' => $d['password'], 'must_change_password' => false, 'auth_version' => $u->auth_version + 1]);
        DB::table('sessions')->where('user_id', $u->id)->delete();
        $r->session()->regenerate();
        $r->session()->put('auth_version', $u->auth_version);

        $r->session()->forget('url.intended');

        return redirect('/')->with('message', 'Your password has been updated.');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }
}
