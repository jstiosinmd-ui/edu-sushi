<?php

// Every application release carries a new identity and learner-facing popup notes.
function sushiReleaseErrors(array $current, ?array $previous = null, bool $applicationChanged = false): array
{
    $errors = [];
    foreach (['id', 'version', 'name', 'summary', 'released_on', 'tour_name'] as $key) {
        if (! is_string($current[$key] ?? null) || trim($current[$key]) === '') {
            $errors[] = "Release field {$key} is required.";
        }
    }
    if (! preg_match('/^\\d+\\.\\d+(?:\\.\\d+)?$/', $current['version'] ?? '') || ($current['id'] ?? '') !== 'sushi-'.($current['version'] ?? '')) {
        $errors[] = 'Release ID must match the version.';
    }
    if (! preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $current['released_on'] ?? '')) {
        $errors[] = 'Use a dated release.';
    }
    if (empty($current['features']) || ! is_array($current['features'])) {
        $errors[] = 'Each release needs visible feature notes.';
    } else {
        foreach ($current['features'] as $feature) {
            if (! is_array($feature) || empty($feature['title']) || empty($feature['text']) || ! preg_match('~^/(?!/)~', $feature['url'] ?? '') || ! in_array($feature['audience'] ?? 'all', ['all', 'admin'], true)) {
                $errors[] = 'Feature notes need a title, explanation, app link, and valid audience.';
            }
        }
        if (! array_filter($current['features'], fn ($f) => is_array($f) && ($f['audience'] ?? 'all') === 'all')) {
            $errors[] = 'Learners must have at least one visible release note.';
        }
    }
    if ($applicationChanged && $previous) {
        if (($current['id'] ?? '') === ($previous['id'] ?? '') || ! version_compare($current['version'] ?? '0', $previous['version'] ?? '0', '>')) {
            $errors[] = 'Application updates require a new higher version so the popup appears again.';
        }
        if (($current['summary'] ?? '') === ($previous['summary'] ?? '') && ($current['features'] ?? []) === ($previous['features'] ?? [])) {
            $errors[] = 'Describe what changed in this release; do not only bump its number.';
        }
    }

    return $errors;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

function releaseGit(array $args): array
{
    $process = proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Git release check.');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
}

try {
    $current = json_decode(file_get_contents(dirname(__DIR__).'/resources/release.json'), true, 512, JSON_THROW_ON_ERROR);
    $base = $argv[1] ?? null;
    if (! $base && getenv('GITHUB_EVENT_PATH')) {
        $event = json_decode(file_get_contents(getenv('GITHUB_EVENT_PATH')), true, 512, JSON_THROW_ON_ERROR);
        $base = $event['pull_request']['base']['sha'] ?? $event['before'] ?? null;
    }
    $previous = null;
    $changed = false;
    if ($base && $base !== str_repeat('0', 40)) {
        if (! preg_match('/^[a-f0-9]{40}$/', $base)) {
            throw new RuntimeException('Invalid comparison commit.');
        }
        [$code, $files] = releaseGit(['diff', '--name-only', $base, 'HEAD']);
        if ($code !== 0) {
            throw new RuntimeException('Cannot read the comparison commit. Fetch the Git history.');
        }
        $changed = (bool) preg_match('~^src/laravel/(?:(?:app|bootstrap|config|database|resources|routes|public)/|composer\\.|package|Dockerfile)~m', $files);
        [$code, $old] = releaseGit(['show', $base.':src/laravel/resources/release.json']);
        if ($code === 0) {
            $previous = json_decode($old, true, 512, JSON_THROW_ON_ERROR);
        }
    }
    $errors = sushiReleaseErrors($current, $previous, $changed);
    if ($errors) {
        throw new RuntimeException(implode("\n", $errors));
    }
    echo 'Release notes ready: SUSHI v'.$current['version'].' · '.$current['name']."\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
