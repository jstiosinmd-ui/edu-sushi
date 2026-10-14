<?php

// Build a smaller transparent delivery asset; preserve the generated PNG source.
$root = dirname(__DIR__);
$source = $root.'/public/art/20261014_EDU_SushiLoginBanner_v2.4.png';
$destination = $root.'/public/art/20261014_EDU_SushiLoginBanner_v2.4.webp';
$image = imagecreatefrompng($source);
if (! $image) {
    throw new RuntimeException('Could not read the login banner.');
}
$width = 1440;
$height = (int) round(imagesy($image) * $width / imagesx($image));
$target = imagecreatetruecolor($width, $height);
imagealphablending($target, false);
imagesavealpha($target, true);
imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
imagecopyresampled($target, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
if (! imagewebp($target, $destination, 90)) {
    throw new RuntimeException('Could not encode the login banner.');
}
echo $width.'x'.$height.'; '.filesize($destination).' bytes'.PHP_EOL;
