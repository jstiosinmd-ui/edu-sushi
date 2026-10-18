<?php

use App\Models\Question;
use App\Services\RetagQuestionsV3;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('local') || config('database.connections.pgsql.host') !== '127.0.0.1' || config('database.connections.pgsql.database') !== 'sushi_ui_preview') {
    throw new RuntimeException('This rehearsal is restricted to the synthetic local preview database.');
}
$baseline = base_path('.tmp/v3-baseline.json');
if (($argv[1] ?? '') === 'export-content') {
    $items = Question::with('currentVersion')->orderBy('id')->get()->map(fn ($q) => $q->currentVersion->content)->all();
    file_put_contents(base_path('.tmp/v3-content.json'), json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo 'Exported '.count($items).' existing question versions for schema validation.'.PHP_EOL;
    exit;
}
$digest = function ($query) {
    $rows = $query->get()->map(fn ($r) => (array) $r)->all();
    usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));

    return ['count' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))];
};
if (($argv[1] ?? '') === 'capture') {
    if (file_exists($baseline)) {
        throw new RuntimeException('Baseline already exists; do not overwrite the before state.');
    }
    $record = ['captured_at' => now()->toIso8601String(), 'tables' => [],
        'version_max_id' => DB::table('question_versions')->max('id')];
    foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $row) {
        if (! in_array($row->tablename, ['questions', 'migrations'])) {
            $record['tables'][$row->tablename] = $digest(DB::table($row->tablename));
        }
    }
    $record['identities'] = $digest(DB::table('questions')->select('id', 'position', 'stem_hash', 'active'));
    file_put_contents($baseline, json_encode($record, JSON_PRETTY_PRINT));
    echo "Saved local before-state hashes.\n";
    exit;
}
$record = json_decode(file_get_contents($baseline), true, 512, JSON_THROW_ON_ERROR);
$checks = [];
foreach ($record['tables'] as $table => $expected) {
    if ($table === 'bank_releases') {
        $query = DB::table($table)->where('id', '!=', RetagQuestionsV3::RELEASE);
    } else {
        $query = DB::table($table);
    }
    if ($table === 'question_versions') {
        $query->where('id', '<=', $record['version_max_id']);
    }
    $checks[$table] = $expected === $digest($query);
}
$checks['question_id_position_stem_active'] = $record['identities'] === $digest(DB::table('questions')->select('id', 'position', 'stem_hash', 'active'));
$result = ['checked_at' => now()->toIso8601String(), 'passed' => ! in_array(false, $checks, true), 'checks' => $checks,
    'question_count' => DB::table('questions')->count(), 'tagged_questions' => DB::table('questions')->whereNotNull('topic_code')->count(),
    'subjects' => DB::table('questions')->selectRaw('subject_code, count(*) AS count')->groupBy('subject_code')->orderBy('subject_code')->get()->all(),
    'retag_changes' => DB::table('question_taxonomy_changes')->count()];
echo json_encode($result, JSON_PRETTY_PRINT)."\n";
exit($result['passed'] ? 0 : 1);
