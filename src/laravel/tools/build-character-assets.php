<?php

// Derive small transparent web assets. Generated originals remain unchanged.
$root = dirname(__DIR__);
$catalog = json_decode(file_get_contents($root.'/resources/characters.json'), true);
$dir = $root.'/public/art/portraits';
if (! is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$total = 0;
foreach ($catalog as &$a) {
    $source = $a['source_image'] ?? $a['image'];
    $image = imagecreatefrompng($root.'/public'.$source);
    if (! $image) {
        throw new RuntimeException('Unreadable source: '.$source);
    }
    $target = imagecreatetruecolor(256, 256);
    imagealphablending($target, false);
    imagesavealpha($target, true);
    imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
    $w = imagesx($image);
    $h = imagesy($image);
    $scale = min(256 / $w, 256 / $h);
    $dw = (int) round($w * $scale);
    $dh = (int) round($h * $scale);
    imagecopyresampled($target, $image, (int) ((256 - $dw) / 2), (int) ((256 - $dh) / 2), 0, 0, $dw, $dh, $w, $h);
    $path = '/art/portraits/'.$a['id'].'-v2.2.webp';
    if (! imagewebp($target, $root.'/public'.$path, 88)) {
        throw new RuntimeException('Could not encode '.$path);
    }
    $a['source_image'] = $source;
    $a['image'] = $path;
    $total += filesize($root.'/public'.$path);
}
file_put_contents($root.'/resources/characters.json', json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
echo count($catalog).' portraits; '.round($total / 1024).' KiB combined'.PHP_EOL;
