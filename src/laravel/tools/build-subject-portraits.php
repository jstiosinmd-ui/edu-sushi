<?php

// Preserve generated originals; make small transparent delivery assets.
$directory = dirname(__DIR__).'/public/art/subjects';
foreach (['preventive', 'anatomy', 'legal', 'pathology', 'pharmacology', 'biochemistry'] as $id) {
    $image = imagecreatefrompng($directory.'/'.$id.'-v2.5.png');
    if (! $image) {
        throw new RuntimeException('Could not read subject portrait: '.$id);
    }
    $size = 320;
    $target = imagecreatetruecolor($size, $size);
    imagealphablending($target, false);
    imagesavealpha($target, true);
    imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
    imagecopyresampled($target, $image, 0, 0, 0, 0, $size, $size, imagesx($image), imagesy($image));
    $output = $directory.'/'.$id.'-v2.5.webp';
    if (! imagewebp($target, $output, 90)) {
        throw new RuntimeException('Could not encode portrait: '.$id);
    }
    echo $id.': '.filesize($output).' bytes'.PHP_EOL;
}
