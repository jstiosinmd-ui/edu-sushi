<?php

// Preserve generated originals; make small transparent delivery assets.
$directory = dirname(__DIR__).'/public/art/subjects';
$versions = array_fill_keys(['preventive', 'anatomy', 'legal', 'pathology', 'pharmacology', 'biochemistry'], 'v2.5') + ['physiology' => 'v2.7', 'microbiology' => 'v2.7'];
foreach (array_slice($argv, 1) ?: array_keys($versions) as $id) {
    $version = $versions[$id] ?? throw new InvalidArgumentException('Unknown subject portrait: '.$id);
    $image = imagecreatefrompng($directory.'/'.$id.'-'.$version.'.png');
    if (! $image) {
        throw new RuntimeException('Could not read subject portrait: '.$id);
    }
    $size = 320;
    $target = imagecreatetruecolor($size, $size);
    imagealphablending($target, false);
    imagesavealpha($target, true);
    imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
    imagecopyresampled($target, $image, 0, 0, 0, 0, $size, $size, imagesx($image), imagesy($image));
    $output = $directory.'/'.$id.'-'.$version.'.webp';
    if (! imagewebp($target, $output, 90)) {
        throw new RuntimeException('Could not encode portrait: '.$id);
    }
    echo $id.': '.filesize($output).' bytes'.PHP_EOL;
}
