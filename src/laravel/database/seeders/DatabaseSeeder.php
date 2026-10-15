<?php

namespace Database\Seeders;

use App\Models\Question;
use App\Models\User;
use App\Services\Catalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['powquanico' => 'student', 'hannahquintero' => 'student', 'rachirpunla' => 'student', 'jstiosin' => 'admin', 'jstiosin-student' => 'student'] as $username => $role) {
            User::firstOrCreate(['username' => $username], ['name' => $username, 'role' => $role, 'password' => 'password', 'must_change_password' => true]);
        }
        // Append missing bundled questions without replacing admin edits or learner history.
        foreach (['bank.json', 'subjects-20261014-v1.1.json', 'basic-sciences-20261014-v1.0.json', 'physiology-microbiology-20261014-v1.0.json', 'ple-top-up-20261016-v2.8.json'] as $file) {
            $bank = json_decode(file_get_contents(database_path('data/'.$file)), true, 512, JSON_THROW_ON_ERROR);
            $existing = Question::pluck('id')->flip();
            $bank['items'] = array_values(array_filter($bank['items'], fn ($q) => ! $existing->has($q['id'])));
            if ($bank['items']) {
                app(Catalog::class)->import($bank);
            }
        }

        // Activate this release once, so later Head Chef retirements survive reseeding.
        DB::transaction(function () {
            if (! DB::table('bank_releases')->insertOrIgnore(['id' => 'ple-top-up-20261016', 'applied_at' => now()])) {
                return;
            }
            Question::with('currentVersion')->chunkById(200, function ($questions) {
                $items = $questions->map(fn ($q) => $q->currentVersion->content)
                    ->filter(fn ($content) => $content !== Catalog::activeContent($content))->values()->all();
                if ($items) {
                    // Publication metadata changes do not send answer-correction notices.
                    app(Catalog::class)->import(['items' => $items], notifyCorrections: false);
                }
            });
            Question::where('active', false)->update(['active' => true]);
        });
    }
}
