<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InvitationController extends Controller
{
    private function invitation(string $token)
    {
        abort_unless(preg_match('/^[a-zA-Z0-9]{64}$/', $token), 404);
        $invite = DB::table('learner_invitations')->where('token_hash', hash('sha256', $token))->first();
        abort_unless($invite && ! $invite->accepted_at && ! $invite->revoked_at && now()->lt($invite->expires_at), 410, 'This invitation is unavailable. Ask Head Chef for a new link.');

        return $invite;
    }

    public function show(string $token)
    {
        $i = $this->invitation($token);
        $u = User::findOrFail($i->user_id);
        abort_unless($u->invitation_pending && ! $u->disabled && $u->role === 'student', 410);

        return Inertia::render('Invitation', ['username' => $u->username, 'token' => $token])->toResponse(request())
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function accept(Request $r, string $token)
    {
        $d = $r->validate(['password' => 'required|string|min:8|max:100|confirmed']);
        $i = $this->invitation($token);
        DB::transaction(function () use ($i, $d, $token) {
            $u = User::lockForUpdate()->findOrFail($i->user_id);
            $this->invitation($token); // Recheck after the account lock: revoke/reissue/claim may have won.
            abort_unless($u->invitation_pending && ! $u->disabled && $u->role === 'student', 410);
            $u->update(['password' => $d['password'], 'invitation_pending' => false, 'must_change_password' => false, 'auth_version' => $u->auth_version + 1]);
            DB::table('learner_invitations')->where('id', $i->id)->update(['accepted_at' => now(), 'updated_at' => now()]);
        }, 3);

        return redirect('/login')->with('message', 'Your account is ready. Sign in with your username and chosen password.');
    }
}
