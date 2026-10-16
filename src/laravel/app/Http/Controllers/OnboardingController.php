<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    public function index()
    {
        return Inertia::render('Onboarding', ['invitations' => DB::table('learner_invitations as i')->join('users as u', 'u.id', '=', 'i.user_id')
            ->select('i.id', 'u.username', 'u.cohort', 'i.expires_at', 'i.accepted_at', 'i.revoked_at')->orderByDesc('i.id')->paginate(30)]);
    }

    public function preview(Request $r)
    {
        $r->validate(['roster' => 'required|file|max:512']);
        $handle = fopen($r->file('roster')->getRealPath(), 'r');
        try {
            $headers = fgetcsv($handle, null, ',', '"', '');
            if (! $headers) {
                throw ValidationException::withMessages(['roster' => 'The CSV is empty.']);
            }
            $headers = array_map(fn ($h) => trim(ltrim((string) $h, "\xEF\xBB\xBF")), $headers);
            if (! in_array('username', $headers, true) || array_diff($headers, ['username', 'display_name', 'cohort']) || count($headers) !== count(array_unique($headers))) {
                throw ValidationException::withMessages(['roster' => 'Use username, display_name, and cohort headers. Username is required; the other columns are optional.']);
            }
            $rows = [];
            $errors = [];
            $seen = [];
            $line = 1;
            while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $line++;
                if ($cells === [null]) {
                    continue;
                }
                if (count($rows) >= 500) {
                    throw ValidationException::withMessages(['roster' => 'Import at most 500 learners per batch.']);
                }
                if (count($cells) !== count($headers)) {
                    $errors[] = 'Row '.$line.': column count does not match the headers.';
                    $rows[] = ['username' => '', 'display_name' => '', 'cohort' => ''];

                    continue;
                }
                $row = array_map(fn ($v) => trim((string) $v), array_combine($headers, $cells));
                $row['username'] = mb_strtolower($row['username']);
                $row += ['display_name' => '', 'cohort' => ''];
                $v = Validator::make($row, ['username' => 'required|string|regex:/^[a-z0-9_-]{3,40}$/', 'display_name' => 'nullable|string|max:50', 'cohort' => 'nullable|string|max:100']);
                foreach ($v->errors()->all() as $message) {
                    $errors[] = 'Row '.$line.': '.$message;
                }
                if (isset($seen[$row['username']])) {
                    $errors[] = 'Row '.$line.': repeated username '.$row['username'].'.';
                }
                $seen[$row['username']] = true;
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['roster' => 'Add at least one learner.']);
        }
        foreach (User::whereIn('username', array_keys($seen))->pluck('username') as $name) {
            $errors[] = $name.' already has an account. Existing learners will not be overwritten.';
        }
        $id = null;
        if (! $errors) {
            $id = (string) Str::uuid();
            DB::table('onboarding_batches')->insert(['id' => $id, 'user_id' => $r->user()->id, 'rows' => json_encode($rows), 'expires_at' => now()->addHours(2), 'created_at' => now()]);
        }

        return response()->json(['id' => $id, 'rows' => $rows, 'errors' => $errors]);
    }

    private function issue(int $userId, int $actor): string
    {
        $token = Str::random(64);
        DB::table('learner_invitations')->updateOrInsert(['user_id' => $userId], ['created_by' => $actor, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7), 'accepted_at' => null, 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now()]);

        return url('/join/'.$token);
    }

    public function create(Request $r, string $batch)
    {
        try {
            $links = DB::transaction(function () use ($r, $batch) {
                $b = DB::table('onboarding_batches')->where('id', $batch)->where('user_id', $r->user()->id)->lockForUpdate()->first();
                abort_unless($b, 404);
                abort_if($b->completed_at, 409, 'This batch was already created. Use the invitation list to replace a link if needed.');
                abort_if(now()->gte($b->expires_at), 410, 'This preview expired. Upload the roster again.');
                $rows = json_decode($b->rows, true);
                if (User::whereIn('username', array_column($rows, 'username'))->exists()) {
                    throw ValidationException::withMessages(['roster' => 'An account in this preview now exists. Preview the roster again.']);
                }
                $links = [];
                foreach ($rows as $row) {
                    $u = User::create(['username' => $row['username'], 'name' => $row['display_name'] ?: $row['username'], 'display_name' => $row['display_name'] ?: null,
                        'cohort' => $row['cohort'] ?: null, 'role' => 'student', 'password' => Str::random(64), 'must_change_password' => true, 'invitation_pending' => true]);
                    $links[] = ['username' => $u->username, 'cohort' => $u->cohort, 'url' => $this->issue($u->id, $r->user()->id)];
                }
                DB::table('onboarding_batches')->where('id', $batch)->update(['completed_at' => now()]);
                AdminController::audit($r, 'learners.invited', $batch, ['count' => count($links)]);

                return $links;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['roster' => 'An account was created while processing this roster. Nothing in this batch was saved. Preview again.']);
        }

        return response()->json(['links' => $links])->header('Cache-Control', 'private, no-store');
    }

    public function reissue(Request $r, int $invitation)
    {
        return DB::transaction(function () use ($r, $invitation) {
            $i = DB::table('learner_invitations')->where('id', $invitation)->first();
            abort_unless($i, 404);
            $u = User::lockForUpdate()->findOrFail($i->user_id);
            abort_unless($u->invitation_pending && ! $u->disabled && $u->role === 'student', 422, 'Only pending, enabled learners can receive a replacement invitation.');
            $url = $this->issue($u->id, $r->user()->id);
            AdminController::audit($r, 'invitation.reissued', (string) $i->id);

            return response()->json(['links' => [['username' => $u->username, 'cohort' => $u->cohort, 'url' => $url]]])->header('Cache-Control', 'private, no-store');
        });
    }

    public function revoke(Request $r, int $invitation)
    {
        DB::transaction(function () use ($r, $invitation) {
            $i = DB::table('learner_invitations')->where('id', $invitation)->first();
            abort_unless($i, 404);
            User::whereKey($i->user_id)->lockForUpdate()->firstOrFail();
            DB::table('learner_invitations')->where('id', $invitation)->whereNull('accepted_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            AdminController::audit($r, 'invitation.revoked', (string) $i->id);
        });

        return back()->with('message', 'Unused invitation revoked.');
    }
}
