<?php

// Usage: php tools/import-sushi-subjects.php <read-only CSV folder> [next|basic|final]
use App\Services\Catalog;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$directory = $argv[1] ?? throw new InvalidArgumentException('Supply the CSV folder.');
$bundle = $argv[2] ?? 'next';
if (! in_array($bundle, ['next', 'basic', 'final'], true)) {
    throw new InvalidArgumentException('Choose next, basic, or final.');
}
$output = match ($bundle) {
    'final' => 'physiology-microbiology-20261014-v1.0.json',
    'basic' => 'basic-sciences-20261014-v1.0.json',
    default => 'subjects-20261014-v1.1.json',
};
$files = match ($bundle) {
    'final' => [
        '20261014_EDU_PhysiologyQuestionBank_v1.0.csv' => ['Physiology', 165],
        '20261014_EDU_MicrobiologyParasitologyQuestionBank_v1.0.csv' => ['Microbiology-Parasitology', 126],
    ],
    'basic' => [
        '20261014_EDU_PathologyQuestionBank_v1.0.csv' => ['Pathology', 194],
        '20261014_EDU_PharmacologyQuestionBank_v1.0.csv' => ['Pharmacology', 166],
        '20261014_EDU_BiochemistryQuestionBank_v1.0.csv' => ['Biochemistry', 157],
    ],
    default => [
        '20261014_EDU_PreventiveMedicineQuestionBank_v1.1.csv' => ['Preventive Medicine', 247],
        '20261014_EDU_AnatomyQuestionBank_v1.1.csv' => ['Anatomy', 189],
        '20261014_EDU_LegalMedicineEthicsQuestionBank_v1.1.csv' => ['Legal Medicine-Ethics', 172],
    ],
};
$base = json_decode(file_get_contents(database_path('data/bank.json')), true, 512, JSON_THROW_ON_ERROR);
if (in_array($bundle, ['basic', 'final'], true)) {
    $prior = json_decode(file_get_contents(database_path('data/subjects-20261014-v1.1.json')), true, 512, JSON_THROW_ON_ERROR);
    $base['items'] = [...$base['items'], ...$prior['items']];
}
if ($bundle === 'final') {
    $prior = json_decode(file_get_contents(database_path('data/basic-sciences-20261014-v1.0.json')), true, 512, JSON_THROW_ON_ERROR);
    $base['items'] = [...$base['items'], ...$prior['items']];
}
$expectedHeaders = array_keys($base['items'][0]['raw']);
$existingIds = array_flip(array_column($base['items'], 'id'));
$bank = ['items' => [], 'subjects' => [], 'sources' => [], 'date' => '2026-10-14', 'counts' => []];
foreach ($files as $file => [$subject, $expectedCount]) {
    $path = $directory.DIRECTORY_SEPARATOR.$file;
    $handle = fopen($path, 'rb');
    $headers = fgetcsv($handle, escape: '');
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    if ($headers !== $expectedHeaders) {
        throw new RuntimeException('CSV columns differ: '.$file);
    }
    $count = 0;
    while (($values = fgetcsv($handle, escape: '')) !== false) {
        if (count($values) !== count($headers)) {
            throw new RuntimeException('Invalid CSV row: '.$file);
        }
        $row = array_combine($headers, $values);
        if ($row['Section Title'] !== $subject || isset($existingIds[$row['Question ID']])) {
            throw new RuntimeException('Unexpected subject or duplicate ID: '.$row['Question ID']);
        }
        $existingIds[$row['Question ID']] = true;
        $bank['items'][] = [
            'id' => $row['Question ID'], 'subject' => $subject, 'topic' => $row['Topic'],
            'tier' => $row['Difficulty'], 'stem' => $row['Question'],
            'options' => array_map(fn ($letter) => $row['Option '.$letter], ['A', 'B', 'C', 'D']),
            'key' => $row['Correct Answer'], 'rationale' => $row['Rationale'],
            'expl' => array_map(fn ($letter) => $row['Explanation '.$letter], ['A', 'B', 'C', 'D']),
            'status' => 'Released', 'raw' => $row,
        ];
        $count++;
    }
    fclose($handle);
    if ($count !== $expectedCount) {
        throw new RuntimeException('Unexpected question count: '.$file);
    }
    $bank['subjects'][] = $subject;
    $bank['counts'][$subject] = $count;
    $bank['sources'][] = ['file' => $file, 'sha256' => hash_file('sha256', $path), 'rows' => $count];
}
app(Catalog::class)->validate($bank);
$bank['duplicateStems'] = [];
$stems = [];
foreach ([...$base['items'], ...$bank['items']] as $q) {
    $stems[Catalog::stemHash($q['stem'])][] = $q['id'];
}
foreach ($stems as $ids) {
    if (count($ids) > 1) {
        $bank['duplicateStems'][] = $ids;
    }
}
$bank['hash'] = hash('sha256', json_encode($bank['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
file_put_contents(database_path('data/'.$output), json_encode($bank, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode(['imported' => count($bank['items']), 'counts' => $bank['counts'], 'duplicateStems' => $bank['duplicateStems'], 'baseBankUnchanged' => true], JSON_PRETTY_PRINT).PHP_EOL;
