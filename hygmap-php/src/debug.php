<?php
require 'db_inc.php';
require 'common_inc.php';

list($select_star, $select_center, $x_c, $y_c, $z_c, $zoom, $z_zoom, $mag_limit, $mag_limit_label, $image_size, $image_type, $max_line, $trek_names) = getVars();

while (@ob_end_clean());
Header("Content-type: image/jpeg");

$image = ImageCreate(400, 400);
$white = ImageColorAllocate($image,255,255,255);
$black = ImageColorAllocate($image,0,0,0);

ImageFill($image, 0, 0, $white);
ImageString($image, 5, 100, 100, "MINIMAL TEST", $black);

// Test if we can query and plot basic info
$rows = query_all();
$count = count($rows);
ImageString($image, 3, 100, 150, "Stars: $count", $black);

ImageJPEG($image);
ImageDestroy($image);
?>