<?php

use App\Models\Question;
use App\Models\User;
use App\Services\Catalog;
use App\Services\Draw;
use App\Services\StudyGuide;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// A reproducible local benchmark. This fixture must never run against an application database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'sushi_studyflow_benchmark'
    || config('database.connections.pgsql.host') !== '127.0.0.1') {
    throw new RuntimeException('The isolated local sushi_studyflow_benchmark database is required.');
}
$mode = $argv[1] ?? 'select';
if ($mode === 'cache') {
    $catalog = app(Catalog::class);
    $id = Question::where('active', true)->value('id');
    $before = $catalog->summary();
    $cached = Cache::has('catalog:summary:v1:'.$before['revision']);
    try {
        Question::whereKey($id)->update(['active' => false]);
        $after = $catalog->summary();
        if (! $cached || $after['total'] !== $before['total'] - 1 || $after['revision'] <= $before['revision']) {
            throw new RuntimeException('Committed catalog change did not invalidate cached counts.');
        }
    } finally {
        Question::whereKey($id)->update(['active' => true]);
    }
    $restored = $catalog->summary();
    if ($restored['total'] !== $before['total'] || $restored['revision'] <= $after['revision']) {
        throw new RuntimeException('Catalog restoration did not invalidate cached counts.');
    }
    echo json_encode(['cache_invalidation' => 'passed', 'before' => $before['total'], 'retired' => $after['total'], 'restored' => $restored['total'],
        'revisions' => [$before['revision'], $after['revision'], $restored['revision']]], JSON_PRETTY_PRINT)."\n";
    exit;
}
if ($mode === 'prepare') {
    Artisan::call('migrate', ['--force' => true]);
    if (Question::count()) {
        throw new RuntimeException('Benchmark fixtures already exist. Reuse them.');
    }
    $text = str_repeat('Synthetic software benchmark explanation. This is not clinical teaching. ', 24);
    DB::transaction(function () use ($text) {
        for ($batch = 0; $batch < 180; $batch++) {
            $questions = $versions = [];
            for ($offset = 1; $offset <= 100; $offset++) {
                $n = $batch * 100 + $offset;
                $subject = Catalog::SUBJECTS[($n - 1) % 12];
                $q = ['id' => 'BENCH-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT), 'subject' => $subject, 'topic' => 'Synthetic topic '.($n % 7), 'tier' => 'Easy',
                    'stem' => 'Synthetic performance fixture '.$n.'. Which token is recorded in the test key?', 'options' => ['Alpha', 'Beta', 'Gamma', 'Delta'], 'key' => 'A',
                    'rationale' => $text, 'expl' => [$text, $text, $text, $text], 'raw' => ['Answer Source' => 'Synthetic benchmark fixture']];
                $content = json_encode($q);
                $questions[] = ['id' => $q['id'], 'subject' => $subject, 'topic' => $q['topic'], 'difficulty' => 'Easy', 'stem_hash' => Catalog::stemHash($q['stem']),
                    'position' => $n, 'active' => true, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()];
                $versions[] = ['question_id' => $q['id'], 'version' => 1, 'content' => $content, 'content_hash' => hash('sha256', $content), 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('questions')->insert($questions);
            DB::table('question_versions')->insert($versions);
        }
        DB::statement('UPDATE questions SET current_version_id = v.id FROM question_versions v WHERE v.question_id = questions.id');
        User::factory()->create(['username' => 'benchmark', 'role' => 'student']);
    });
    echo "Prepared 18,000 synthetic questions.\n";
    exit;
}
$start = hrtime(true);
$catalog = app(Catalog::class);
$settings = ['subjects' => Catalog::SUBJECTS, 'topics' => [], 'difficulty' => 'All', 'seed' => 42, 'count' => 10, 'balance' => false];
if ($mode === 'full') {
    // The pre-change loading strategy, applied to the same frozen synthetic bank.
    $pool = Question::where('active', true)->with('currentVersion')->orderBy('position')->orderBy('id')->get()
        ->map(fn ($q) => [...$q->currentVersion->content, 'version' => $q->currentVersion->version, 'version_id' => $q->current_version_id])->all();
    $items = app(Draw::class)->select($pool, $settings);
} elseif ($mode === 'guide') {
    $plan = app(StudyGuide::class)->plan(User::where('username', 'benchmark')->firstOrFail(), $settings['subjects'], 15);
    $items = $catalog->items($plan['selected_ids']);
} elseif ($mode === 'select') {
    $items = $catalog->hydrate(app(Draw::class)->select($catalog->candidates($settings), $settings));
} else {
    throw new RuntimeException('Use prepare, full, select, or guide.');
}
echo json_encode(['mode' => $mode, 'questions' => 18000, 'selected' => count($items), 'milliseconds' => round((hrtime(true) - $start) / 1e6, 2),
    'peak_bytes' => memory_get_peak_usage(true), 'ids' => array_column($items, 'id')], JSON_PRETTY_PRINT)."\n";
