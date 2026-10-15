<?php

// Build the append-only application bundle from the complete read-only CSV banks.
// Usage: php tools/import-ple-top-up.php <CSV folder>
use App\Services\Catalog;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$directory = $argv[1] ?? throw new InvalidArgumentException('Supply the CSV folder.');
$files = [
    '20261015_EDU_InternalMedicineQuestionBank_v2.0.csv' => 'Internal Medicine',
    '20261015_EDU_PediatricsQuestionBank_v2.0.csv' => 'Pediatrics',
    '20261015_EDU_SurgeryQuestionBank_v2.0.csv' => 'Surgery',
    '20261015_EDU_OBGYNQuestionBank_v2.0.csv' => 'OB-GYN',
    '20261015_EDU_PreventiveMedicineQuestionBank_v2.0.csv' => 'Preventive Medicine',
    '20261015_EDU_AnatomyQuestionBank_v2.0.csv' => 'Anatomy',
    '20261016_EDU_LegalMedicineEthicsQuestionBank_v2.1.csv' => 'Legal Medicine-Ethics',
    '20261015_EDU_PathologyQuestionBank_v2.0.csv' => 'Pathology',
    '20261016_EDU_PharmacologyQuestionBank_v2.1.csv' => 'Pharmacology',
    '20261016_EDU_BiochemistryQuestionBank_v2.1.csv' => 'Biochemistry',
    '20261016_EDU_PhysiologyQuestionBank_v2.1.csv' => 'Physiology',
    '20261016_EDU_MicrobiologyParasitologyQuestionBank_v2.1.csv' => 'Microbiology-Parasitology',
];
$existing = [];
foreach (['bank.json', 'subjects-20261014-v1.1.json', 'basic-sciences-20261014-v1.0.json', 'physiology-microbiology-20261014-v1.0.json'] as $file) {
    $prior = json_decode(file_get_contents(database_path('data/'.$file)), true, 512, JSON_THROW_ON_ERROR);
    foreach ($prior['items'] as $q) {
        $existing[$q['id']] = $q;
    }
}
$headersExpected = array_keys(reset($existing)['raw']);
$bank = ['date' => '2026-10-16', 'items' => [], 'counts' => [], 'newCounts' => [], 'sources' => [], 'sourceReviewStatuses' => []];
$seen = $stems = [];
foreach ($files as $file => $subject) {
    $path = $directory.DIRECTORY_SEPARATOR.$file;
    $handle = fopen($path, 'rb');
    if (! $handle) {
        throw new RuntimeException('Cannot read '.$file);
    }
    $headers = fgetcsv($handle, escape: '');
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    if ($headers !== $headersExpected) {
        throw new RuntimeException('CSV columns differ: '.$file);
    }
    $count = $new = 0;
    while (($values = fgetcsv($handle, escape: '')) !== false) {
        if (count($values) !== count($headers)) {
            throw new RuntimeException('Malformed row in '.$file);
        }
        $raw = array_combine($headers, $values);
        $id = $raw['Question ID'];
        if ($raw['Section Title'] !== $subject || isset($seen[$id])) {
            throw new RuntimeException('Unexpected subject or duplicate ID: '.$id);
        }
        $seen[$id] = true;
        $stems[Catalog::stemHash($raw['Question'])][] = $id;
        $bank['sourceReviewStatuses'][$raw['Review Status']] = ($bank['sourceReviewStatuses'][$raw['Review Status']] ?? 0) + 1;
        $count++;
        if (isset($existing[$id])) {
            if ($existing[$id]['raw'] !== $raw) {
                throw new RuntimeException('Existing content changed; explicit reconciliation needed: '.$id);
            }

            continue;
        }
        $bank['items'][] = Catalog::activeContent([
            'id' => $id, 'subject' => $subject, 'topic' => $raw['Topic'], 'tier' => $raw['Difficulty'],
            'stem' => $raw['Question'], 'options' => array_map(fn ($letter) => $raw['Option '.$letter], ['A', 'B', 'C', 'D']),
            'key' => $raw['Correct Answer'], 'rationale' => $raw['Rationale'],
            'expl' => array_map(fn ($letter) => $raw['Explanation '.$letter], ['A', 'B', 'C', 'D']),
            'raw' => $raw,
        ]);
        $new++;
    }
    fclose($handle);
    if ($count !== 500) {
        throw new RuntimeException('Expected 500 questions in '.$file);
    }
    $bank['counts'][$subject] = $count;
    $bank['newCounts'][$subject] = $new;
    $bank['sources'][] = ['file' => $file, 'sha256' => hash_file('sha256', $path), 'rows' => $count, 'added' => $new];
}
if (array_diff_key($existing, $seen) || count($seen) !== 6000 || count($bank['items']) !== 3537) {
    throw new RuntimeException('The complete bank does not match the expected existing and new question counts.');
}
app(Catalog::class)->validate($bank);
$bank['duplicateStems'] = array_values(array_filter($stems, fn ($ids) => count($ids) > 1));
$bank['hash'] = hash('sha256', json_encode($bank['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$destination = database_path('data/ple-top-up-20261016-v2.8.json');
file_put_contents($destination, json_encode($bank, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode(['total' => count($seen), 'added' => count($bank['items']), 'counts' => $bank['counts'], 'duplicateStems' => $bank['duplicateStems']], JSON_PRETTY_PRINT).PHP_EOL;
