<?php

use App\Jobs\BuildExamPdfs;
use App\Models\Attempt;
use App\Models\Exam;
use App\Models\PdfExport;
use App\Models\Question;
use App\Models\User;
use App\Services\Catalog;
use App\Services\Study;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

try {
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    // This fixture must never create study records in a real learner database.
    check(config('database.connections.pgsql.database') === 'sushi_container_smoke', 'Use the isolated container smoke database.');
    check(Question::count() === 1047 && User::count() === 4, 'Bootstrap data mismatch.');
    check(User::where('must_change_password', true)->count() === 4, 'Bootstrap credentials changed.');

    if (($argv[1] ?? '') === 'create') {
        $user = User::where('username', 'jstiosin')->firstOrFail();
        $items = array_slice(app(Catalog::class)->items(), 0, 12);
        $exam = Exam::create(['user_id' => $user->id, 'title' => 'Container smoke test', 'code' => 'SUSHI-CONTAINER', 'settings' => ['seed' => 12345], 'items' => $items]);
        $export = PdfExport::create(['exam_id' => $exam->id, 'user_id' => $user->id, 'explanations' => true]);
        BuildExamPdfs::dispatch($export->id);
        $attempt = app(Study::class)->start($user, ['mode' => 'exam', 'count' => 1, 'subjects' => Catalog::SUBJECTS, 'topics' => [], 'difficulty' => 'All', 'seed' => 12345, 'balance' => false, 'pool' => 'all', 'minutes' => 1]);
        $attempt->update(['deadline' => now()->subMinute()]);
        for ($i = 0; $i < 90; $i++) {
            if ($export->fresh()->status === 'ready' && $attempt->fresh()->status === 'timed_out') {
                break;
            }
            sleep(1);
        }
    }

    $export = PdfExport::whereHas('exam', fn ($q) => $q->where('code', 'SUSHI-CONTAINER'))->firstOrFail();
    check($export->status === 'ready', 'The background worker did not finish the export.');
    check(Attempt::where('status', 'timed_out')->exists(), 'The scheduler did not finalize the expired attempt.');
    check(DB::table('failed_jobs')->count() === 0, 'A queued job failed.');
    foreach (['paper', 'sheet', 'key'] as $kind) {
        $bytes = Storage::disk('local')->get('exams/'.$export->id.'/'.$kind.'.pdf');
        check(str_starts_with($bytes ?? '', '%PDF-') && strlen($bytes) > 1000, 'Missing or invalid '.$kind.' PDF.');
    }
    echo "PASS: seed data, queue, scheduler, and three persistent PDFs.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, (string) $exception."\n");
    exit(1);
}
