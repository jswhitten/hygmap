<?php
error_reporting(E_ERROR | E_WARNING | E_PARSE);

require_once __DIR__ . '/Database.php';
require 'common_inc.php';
require_once 'config.inc.php';

$cfg = cfg_load();
extract($cfg);               // gives $unit, $grid, $fic_names, $image_type, etc.

// Extract variables from query string
$vars = getVars();
extract($vars);

// Create image
// while (@ob_end_clean());
// Header("Content-type: image/jpeg");

if($image_type == "stereo") {
    $image = ImageCreate($image_size,$image_size);
} else {
    $image = ImageCreate($image_size*2,$image_size);
}

// Allocate colors
list($white, $grey, $darkgrey, $green, $red, $orange, $lightyellow, $yellow, $lightblue, $blue, $darkblue, $black) = allocateColors();

// Fill background
ImageFill($image,50,50,($image_type == "printable") ? $white : $black);

/* ---------- build bbox in pc ---------- */
$xy_zoom_pc = to_pc($xy_zoom, $unit);
$z_zoom_pc  = to_pc($z_zoom , $unit);

$bbox = [
    to_pc($x_c, $unit) - $xy_zoom_pc,
    to_pc($x_c, $unit) + $xy_zoom_pc,
    to_pc($y_c, $unit) - ($image_type==='left'||$image_type==='right' ? $xy_zoom_pc : 2*$xy_zoom_pc),
    to_pc($y_c, $unit) + ($image_type==='left'||$image_type==='right' ? $xy_zoom_pc : 2*$xy_zoom_pc),
    to_pc($z_c, $unit) - $z_zoom_pc,
    to_pc($z_c, $unit) + $z_zoom_pc,
];

/* same for connecting-line limit */
$max_line_pc = to_pc($max_line, $unit);

/* query */
$rows = Database::queryAll($bbox, $m_limit, 'absmag desc');

// Draw grid
drawGrid(to_pc($grid, $unit));

// Plot connecting lines
if($max_line > 0) {
    foreach($rows as $row_i) {
        $x_i = from_pc($row_i["x"], $unit);
        $y_i = from_pc($row_i["y"], $unit);
        $z_i = from_pc($row_i["z"], $unit);

        foreach($rows as $row_j) {
            $x_j = from_pc($row_j["x"], $unit);
            $y_j = from_pc($row_j["y"], $unit);
            $z_j = from_pc($row_j["z"], $unit);

            $x_diff = $x_i - $x_j;
            $y_diff = $y_i - $y_j;
            $z_diff = $z_i - $z_j;
            $dist_sums = pow($x_diff,2) + pow($y_diff,2) + pow($z_diff,2);
            $dist_sqrt = sqrt($dist_sums);
            if(($row_i["absmag"] < $m_limit) && ($row_j["absmag"] < $m_limit)) {
                if($dist_sqrt < $max_line/2) {
                    list ($screen_x_i, $screen_y_i) = screenCoords($x_i, $y_i, $z_i);
                    list ($screen_x_j, $screen_y_j) = screenCoords($x_j, $y_j, $z_j);
                    ImageLine($image,(int)$screen_x_i,(int)$screen_y_i,(int)$screen_x_j,(int)$screen_y_j,$lightblue);
                } elseif($dist_sqrt < 0.75 * $max_line) {
                    list ($screen_x_i, $screen_y_i) = screenCoords($x_i, $y_i, $z_i);
                    list ($screen_x_j, $screen_y_j) = screenCoords($x_j, $y_j, $z_j);
                    ImageLine($image,(int)$screen_x_i,(int)$screen_y_i,(int)$screen_x_j,(int)$screen_y_j,$blue);
                } elseif($dist_sqrt < $max_line) {
                    list ($screen_x_i, $screen_y_i) = screenCoords($x_i, $y_i, $z_i);
                    list ($screen_x_j, $screen_y_j) = screenCoords($x_j, $y_j, $z_j);
                    ImageLine($image,(int)$screen_x_i,(int)$screen_y_i,(int)$screen_x_j,(int)$screen_y_j,$darkblue);
                }
            }
        }
    }
}

// Plot each star
foreach($rows as $row) {

    $id = $row["id"];
    $x = $row["x"];
    $y = $row["y"];
    $z = $row["z"];
    $mag = $row["absmag"];

    if($mag < $m_limit) {
        list ($screen_x, $screen_y) = screenCoords(from_pc($x, $unit), from_pc($y, $unit), from_pc($z, $unit));
        $starcolor = specColor(getSpecClass($row["spect"]));
        list ($size, $labelsize) = starSize($mag);

        // plot star
        plotStar($screen_x, $screen_y, $size, $starcolor, $select_star == $id);

        // label
	    $skiplabel = false;
	    if($select_star != $id) {
            if($mag > $m_limit_label) {
                $skiplabel = true;
            } elseif(sizeof($rows) > 1000) {
                if($mag > 5 && $id > 0) {
                    $skiplabel = true;
                }
            } else {
                foreach($rows as $checkrow) {
                     // if a brighter star is at the same location don't label this one
                    if($checkrow['absmag'] < $mag) {
                        if(abs($checkrow['x']-$x) < $xy_zoom / 50 && abs($checkrow['y']-$y) < $xy_zoom / 20) {
                            $skiplabel = true;
                            break;
                        }
                    }
                }
	    }
	}
        if(!$skiplabel) {
            list ($name, $labelcolor) = getLabel($fic_names);
            labelStar($name, $labelsize, $labelcolor);
        }
    }
}
// draw it
ImageJPEG($image);
ImageDestroy($image);

function allocateColors() {
    global $image;

    $white = ImageColorAllocate($image,255,255,255);
    $grey = ImageColorAllocate($image,204,204,204);
    $darkgrey = ImageColorAllocate($image,102,102,102);
    $green = ImageColorAllocate($image,0,150,50);
    $red = ImageColorAllocate($image,255,64,0);
    $orange = ImageColorAllocate($image,255,128,0);
    $lightyellow = ImageColorAllocate($image,255,255,160);
    $yellow = ImageColorAllocate($image,255,255,0);
    $lightblue = ImageColorAllocate($image,128,204,255);
    $blue = ImageColorAllocate($image,64,128,255);
    $darkblue = ImageColorAllocate($image,0,64,128);
    $black = ImageColorAllocate($image,0,0,0);

    return array($white, $grey, $darkgrey, $green, $red, $orange, $lightyellow, $yellow, $lightblue, $blue, $darkblue, $black);

}

function screenCoords($x, $y, $z) {
    global $xy_zoom, $z_zoom, $x_c, $y_c, $z_c, $image_size, $image_side, $unit;

    if($image_side == "left" || $image_side == "right") {
        return screenCoords3d($x, $y, $z);
    }

    $screen_x = ($image_size) - (($image_size / (2 * $xy_zoom)) * ($y-$y_c));
    $screen_y = ($image_size / 2) - (($image_size / (2 * $xy_zoom)) * ($x-$x_c));

    if($image_side == "left") {
        $screen_x += 4 * (($z - $z_c) / $z_zoom);
    }
    if($image_side == "right") {
        $screen_x -= 4 * (($z - $z_c) / $z_zoom);
    }

    return array($screen_x, $screen_y);
}

function screenCoords3d($x, $y, $z) {
    global $xy_zoom, $z_zoom, $x_c, $y_c, $z_c, $image_size, $image_side;

    $screen_x = ($image_size / 2) - (($image_size / (2 * $xy_zoom)) * ($y-$y_c));
    $screen_y = ($image_size / 2) - (($image_size / (2 * $xy_zoom)) * ($x-$x_c));

    if($image_side == "left") {
        $screen_x += 4 * (($z - $z_c) / $z_zoom);
    }
    if($image_side == "right") {
        $screen_x -= 4 * (($z - $z_c) / $z_zoom);
    }

    return array($screen_x, $screen_y);
}

function getLabel($fic_names) {
    global $row, $yellow, $white, $black, $grey, $darkgrey, $image_type, $mag;

    if($fic_names == "1" && !empty($row["name"])) {
        $name = $row["name"];
        $labelcolor = $yellow;
        $printcolor = $black;
    } elseif(!empty($row["proper"])) {
        $name = $row["proper"];
        $labelcolor = $white;
        $printcolor = $black;
    } elseif(!empty($row["bayer"])) {
        $name = ltrim($row["bayer"]) . " " . $row["con"];
        $labelcolor = $grey;
        $printcolor = $darkgrey;
    } elseif(!empty($row["flam"])) {
        $name = ltrim($row["flam"]) . " " . $row["con"];
        $labelcolor = $grey;
        $printcolor = $darkgrey;
    } elseif(!empty($row["gl"])) {
        $name = $row["gl"];
        $labelcolor = $mag < 8.5 ? $grey : $darkgrey;
        $printcolor = $darkgrey;
    } elseif(!empty($row["hd"])) {
        $name = "hd".$row["hd"];
        $labelcolor = $mag < 8.5 ? $grey : $darkgrey;
        $printcolor = $darkgrey;
    } elseif(!empty($row["spect"])) {
        $name = $row["spect"];
        $labelcolor = $darkgrey;
        $printcolor = $darkgrey;
    } else {
	$name = '';
        $labelcolor = $darkgrey;
	$printcolor = $darkgrey;
    }

    if($image_type == "printable") {
        $labelcolor = $printcolor;
    }

    return array($name, $labelcolor);
}

function getSpecClass($specdata) {
    if(empty($specdata)) {
        return "";
    }
    $spec = substr($specdata,0,1);
    if($spec == " " || $spec == "s") {
        $spec = strtoupper(substr($specdata,2,1));
    }

    return $spec;
}

function drawGrid($distance) {

    global $y_c, $x_c, $xy_zoom, $image, $green, $grey, $blue, $darkblue, $darkgrey, $image_size, $image_type, $unit;

    if($image_type == "printable") {
        $linecolor = $darkgrey;
        $zerolinecolor = $darkblue;
    } else {
        $linecolor = $green;
        $zerolinecolor = $blue;
        if($image_type == "stereo") {
            return drawGrid3d($distance); // Modify the drawGrid3d function to also accept $distance if needed
        }
    }

    $gx_first = fmod(($y_c + $xy_zoom * 2), $distance);
    $gx_label = ($y_c + $xy_zoom * 2) - $gx_first;
    $gxs_int = ($image_size / 2) * ($distance / $xy_zoom);
    $gxs_first = ($gx_first / $distance) * $gxs_int;
    $gy_first = fmod(($x_c + $xy_zoom), $distance);
    $gy_label = ($x_c + $xy_zoom) - $gy_first;
    $gys_int = ($image_size / 2) * ($distance / $xy_zoom);
    $gys_first = ($gy_first / $distance) * $gxs_int;

    for($g = $gxs_first; $g < $image_size * 2; $g += $gxs_int) {
        ImageLine($image, (int)$g, 0, (int)$g, $image_size, $gx_label == 0 ? $zerolinecolor : $linecolor);
        ImageString($image, 1, (int)$g + 5, 5, round(from_pc($gx_label, $unit), 2), $grey);
        $gx_label -= $distance;
    }

    for($g = $gys_first; $g < $image_size; $g += $gys_int) {
        ImageLine($image, 0, (int)$g, $image_size * 2, (int)$g, $gy_label == 0 ? $zerolinecolor : $linecolor);
        ImageString($image, 1, 5, (int)$g + 5, round(from_pc($gx_label, $unit), 2), $grey);
        $gy_label -= $distance;
    }
}

function drawGrid3d($distance = 20) {

    global $y_c, $x_c, $xy_zoom, $image, $green, $grey, $darkgrey, $image_size, $image_type, $unit;

    if($image_type == "printable") {
       $linecolor = $darkgrey;
    } else {
       $linecolor = $green;
    }

    $gx_first = fmod(($y_c + $xy_zoom), $distance);
    $gx_label = ($y_c + $xy_zoom) - $gx_first;
    $gxs_int = ($image_size / 2) * ($distance / $xy_zoom);
    $gxs_first = ($gx_first / $distance) * $gxs_int;
    $gy_first = fmod(($x_c + $xy_zoom), $distance);
    $gy_label = ($x_c + $xy_zoom) - $gy_first;
    $gys_int = ($image_size / 2) * ($distance / $xy_zoom);
    $gys_first = ($gy_first / $distance) * $gxs_int;

    for($g = $gxs_first; $g < $image_size; $g += $gxs_int) {
        ImageLine($image, (int)$g, 0, (int)$g, $image_size, $linecolor);
        ImageString($image, 1, (int)$g + 5, 5, round(from_pc($gx_label, $unit), 2), $grey);
        $gx_label -= $distance;
    }

    for($g = $gys_first; $g < $image_size; $g += $gys_int) {
        ImageLine($image, 0, (int)$g, $image_size, (int)$g, $linecolor);
        ImageString($image, 1, 5, (int)$g + 5, round(from_pc($gx_label, $unit), 2), $grey);
        $gy_label -= $distance;
    }
}


function specColor($spec) {
    global $lightblue, $blue, $lightyellow, $yellow, $orange, $red, $white;

    if($spec == "O") {
        $color = $blue;
    } elseif($spec == "B") {
        $color = $lightblue;
    } elseif($spec == "F") {
        $color = $lightyellow;
    } elseif($spec == "G") {
        $color = $yellow;
    } elseif($spec == "K" || $spec == "R") {
        $color = $orange;
    } elseif($spec == "M" || $spec == "C" || $spec == "N" || $spec == "S") {
        $color = $red;
    } else {
        $color = $white;
    }
    return $color;
}

function starSize($mag) {
    if($mag > 8) {
        $size = 2;
        $labelsize = 1;
    } elseif($mag > 6) {
        $size = 20 - 2 * $mag;
        $labelsize = 1;
    } elseif($mag > 3) {
        $size = 20 - 2 * $mag;
        $labelsize = 2;        
    } else {
        $size = 20 - 2 * $mag;
        $labelsize = 4;
    }

    return array($size, $labelsize);
}

function plotStar($screen_x, $screen_y, $size, $starcolor, $selected) {
    global $image, $black, $darkgrey, $red, $blue, $image_type;

    if($image_type == "printable") {
        $starcolor = $black;
        $boxcolor = $darkgrey;
    } else {
        $boxcolor = $blue;
    }
    
    ImageFilledEllipse($image,(int)$screen_x,(int)$screen_y,(int)$size,(int)$size,$starcolor);

    // selected star
    if($selected) {
        ImageRectangle($image,(int)$screen_x-20,(int)$screen_y-20,(int)$screen_x+20,(int)$screen_y+20,$boxcolor);
    }
}

function labelStar($name, $labelsize, $labelcolor) {
    global $image, $screen_x, $screen_y;

    ImageString($image,$labelsize,(int)$screen_x + 5,(int)$screen_y + 5,$name,$labelcolor);
}

?>
