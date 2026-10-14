<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'sushi_community_preview') {
    fwrite(STDERR, "Preview database only.\n");
    exit(1);
}
use App\Models\Attempt;
use App\Models\FlashcardDeck;
use App\Models\User;
use App\Services\Catalog;
use App\Services\Passport;
use App\Services\Study;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

User::query()->update(['must_change_password' => false]);
$u = User::where('username', 'powquanico')->firstOrFail();
$settings = ['mode' => 'fresh', 'count' => 10, 'subjects' => Catalog::SUBJECTS, 'topics' => [], 'difficulty' => 'All', 'seed' => 12345, 'balance' => false, 'pool' => 'all', 'minutes' => 10];
if (! Attempt::where('user_id', $u->id)->exists()) {
    $today = Carbon::now('Asia/Manila');
    for ($n = 7; $n >= 1; $n--) {
        Carbon::setTestNow($today->copy()->subDays($n)->setTime(18, 0));
        $a = app(Study::class)->start($u, $settings);
        foreach ($a->items as $item) {
            $a = app(Study::class)->answer($a, $item->id, ['answer' => $item->snapshot['key'], 'revision' => $a->revision, 'check' => true]);
        }
        app(Study::class)->finish($a);
    }
    Carbon::setTestNow();
    app(Passport::class)->for($u);
    $u->update(['avatar' => 'catch', 'pinned_badges' => ['fresh_first', 'streak_7']]);
}
if (! FlashcardDeck::where('user_id', $u->id)->exists()) {
    $deck = FlashcardDeck::create(['user_id' => $u->id, 'title' => 'Clinical warm-up', 'settings' => [], 'reviews' => 20]);
    $items = array_slice(app(Catalog::class)->items(), 0, 20);
    foreach ($items as $i => $q) {
        $deck->items()->create(['question_id' => $q['id'], 'snapshot' => $q, 'confidence' => $i < 15 ? 5 : 2, 'reviews' => 1]);
    }
    $active = $deck->items()->where('confidence', 2)->first();
    $active->update(['review_token' => (string) Str::uuid()]);
    $deck->update(['active_question_id' => $active->question_id]);
}
echo "Isolated community preview prepared.\n";
