<?php
require 'db_inc.php';
require 'common_inc.php';

// Extract variables from query string
list($select_star, $select_center, $x_c, $y_c, $z_c, $zoom, $z_zoom, $mag_limit, $image_size, $image_type, $max_line, $trek_names) = getVars();

// Create image
while (@ob_end_clean());
Header("Content-type: image/jpeg");

if($image_type == "left" || $image_type == "right") {
    $image = ImageCreate($image_size,$image_size);
} else {
    $image = ImageCreate($image_size*2,$image_size);
}

// Allocate colors
list($white, $grey, $darkgrey, $green, $red, $orange, $lightyellow, $yellow, $lightblue, $blue, $darkblue, $black) = allocateColors();

// Fill background
ImageFill($image,50,50,($image_type == "printable") ? $white : $black);

// Connect, select, query database for stars within given coordinates
$link = open_db();
$rows = query_all(($image_type == "left" || $image_type == "right"), $max_line);
mysqli_close($link);

// Draw grid
drawGrid();

// Plot connecting lines
if($max_line > 0) {
    foreach($rows as $row_i) {
        $x_i = $row_i["x"];
        $y_i = $row_i["y"];
        $z_i = $row_i["z"];

        foreach($rows as $row_j) {
            $x_j = $row_j["x"];
            $y_j = $row_j["y"];
            $z_j = $row_j["z"];

            $x_diff = $x_i - $x_j;
            $y_diff = $y_i - $y_j;
            $z_diff = $z_i - $z_j;
            $dist_sums = pow($x_diff,2) + pow($y_diff,2) + pow($z_diff,2);
            $dist_sqrt = sqrt($dist_sums);
            if(($row_i["absmag"] < $mag_limit) && ($row_j["absmag"] < $mag_limit)) {
                if($dist_sqrt < $max_line/2) {
                    list ($screen_x_i, $screen_y_i) = screenCoords($x_i, $y_i, $z_i);
                    list ($screen_x_j, $screen_y_j) = screenCoords($x_j, $y_j, $z_j);
                    ImageLine($image,$screen_x_i,$screen_y_i,$screen_x_j,$screen_y_j,$lightblue);
                } elseif($dist_sqrt < 0.75 * $max_line) {
                    list ($screen_x_i, $screen_y_i) = screenCoords($x_i, $y_i, $z_i);
                    list ($screen_x_j, $screen_y_j) = screenCoords($x_j, $y_j, $z_j);
                    ImageLine($image,$screen_x_i,$screen_y_i,$screen_x_j,$screen_y_j,$blue);
                } elseif($dist_sqrt < $max_line) {
                    list ($screen_x_i, $screen_y_i) = screenCoords($x_i, $y_i, $z_i);
                    list ($screen_x_j, $screen_y_j) = screenCoords($x_j, $y_j, $z_j);
                    ImageLine($image,$screen_x_i,$screen_y_i,$screen_x_j,$screen_y_j,$darkblue);
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

    if($mag < $mag_limit) {

        list ($name, $labelcolor) = getLabel($trek_names);
        list ($screen_x, $screen_y) = screenCoords($x, $y, $z);
        $spec = getSpecClass($row["spect"]); 
        $starcolor = specColor();
        list ($size, $labelsize) = starSize();

        // plot star
        plotStar($select_star, $id);

        // label
        labelStar($name, $labelsize, $labelcolor);
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
    global $zoom, $z_zoom, $x_c, $y_c, $z_c, $image_size, $image_type;

    if($image_type == "left" || $image_type == "right") {
        return screenCoords3d($x, $y, $z);
    }

    $screen_x = ($image_size) - (($image_size / (2 * $zoom)) * ($y-$y_c));
    $screen_y = ($image_size / 2) - (($image_size / (2 * $zoom)) * ($x-$x_c));

    if($image_type == "left") {
        $screen_x += 4 * (($z - $z_c) / $z_zoom);
    }
    if($image_type == "right") {
        $screen_x -= 4 * (($z - $z_c) / $z_zoom);
    }

    return array($screen_x, $screen_y);
}

function screenCoords3d($x, $y, $z) {
    global $zoom, $z_zoom, $x_c, $y_c, $z_c, $image_size, $image_type;

    $screen_x = ($image_size / 2) - (($image_size / (2 * $zoom)) * ($y-$y_c));
    $screen_y = ($image_size / 2) - (($image_size / (2 * $zoom)) * ($x-$x_c));

    if($image_type == "left") {
        $screen_x += 4 * (($z - $z_c) / $z_zoom);
    }
    if($image_type == "right") {
        $screen_x -= 4 * (($z - $z_c) / $z_zoom);
    }

    return array($screen_x, $screen_y);
}

function getLabel($trek_names) {
    global $row, $yellow, $white, $black, $grey, $darkgrey, $image_type;

    if($trek_names == "1" && isset($row["name"]) && $row["name"] != "") {
        $name = $row["name"];
        $labelcolor = $yellow;
        $printcolor = $black;
    } elseif(isset($row["proper"]) && $row["proper"] != "") {
        $name = $row["proper"];
        $labelcolor = $white;
        $printcolor = $black;
    } elseif($row["bf"] != "" && $row["bf"] != "-") {
        $name = ltrim($row["bf"]);
        $labelcolor = $grey;
        $printcolor = $darkgrey;
    } elseif($row["gl"] != "") {
        $name = $row["gl"];
        $labelcolor = $grey;
        $printcolor = $darkgrey;
    } elseif($row["hd"] > 0) {
        $name = "hd".$row["hd"];
        $labelcolor = $grey;
        $printcolor = $darkgrey;
    } else {
        $name = $row["spect"];
        $labelcolor = $darkgrey;
        $printcolor = $darkgrey;
    }

    if($image_type == "printable") {
        $labelcolor = $printcolor;
    }

    return array($name, $labelcolor);
}

function getSpecClass($specdata) {
    $spec = substr($specdata,0,1);
    if($spec == " " || $spec == "s") {
        $spec = strtoupper(substr($specdata,2,1));
    }

    return $spec;
}

function drawGrid() {

    global $y_c, $x_c, $zoom, $image, $green, $grey, $blue, $darkblue, $darkgrey, $image_size, $image_type;

    if($image_type == "printable") {
        $linecolor = $darkgrey;
        $zerolinecolor = $darkblue;
    } else {
        $linecolor = $green;
        $zerolinecolor = $blue;
        if($image_type == "left" || $image_type == "right") {
            return drawGrid3d();
        }
    }

    $gx_first = fmod(($y_c + $zoom * 2),20);
    $gx_label = ($y_c + $zoom * 2) - $gx_first;
    $gxs_int = ($image_size / 2)*(20 / $zoom);
    $gxs_first = ($gx_first/20) * $gxs_int;
    $gy_first = fmod(($x_c + $zoom),20);
    $gy_label = ($x_c + $zoom) - $gy_first;
    $gys_int = ($image_size / 2)*(20 / $zoom);
    $gys_first = ($gy_first/20) * $gxs_int;
    for($g = $gxs_first; $g < $image_size * 2; $g += $gxs_int) {
        ImageLine($image,$g,0,$g,$image_size,$gx_label == 0 ? $zerolinecolor : $linecolor);
        ImageString($image,1,$g + 5,5,$gx_label,$grey);
        $gx_label -= 20;
    }
    for($g = $gys_first; $g < $image_size; $g += $gys_int) {
        ImageLine($image,0,$g,$image_size * 2,$g,$gy_label == 0 ? $zerolinecolor : $linecolor);
        ImageString($image,1,5,$g + 5,$gy_label,$grey);
        $gy_label -= 20;
    }
}

function drawGrid3d() {

    global $y_c, $x_c, $zoom, $image, $green, $grey, $darkgrey, $image_size, $image_type;

    if($image_type == "printable") {
       $linecolor = $darkgrey;
    } else {
       $linecolor = $green;
    }

    $gx_first = fmod(($y_c + $zoom),20);
    $gx_label = ($y_c + $zoom) - $gx_first;
    $gxs_int = ($image_size / 2)*(20 / $zoom);
    $gxs_first = ($gx_first/20) * $gxs_int;
    $gy_first = fmod(($x_c + $zoom),20);
    $gy_label = ($x_c + $zoom) - $gy_first;
    $gys_int = ($image_size / 2)*(20 / $zoom);
    $gys_first = ($gy_first/20) * $gxs_int;
    for($g = $gxs_first; $g < $image_size; $g += $gxs_int) {
        ImageLine($image,$g,0,$g,$image_size,$linecolor);
        ImageString($image,1,$g + 5,5,$gx_label,$grey);
        $gx_label -= 20;
    }
    for($g = $gys_first; $g < $image_size; $g += $gys_int) {
        ImageLine($image,0,$g,$image_size,$g,$linecolor);
        ImageString($image,1,5,$g + 5,$gy_label,$grey);
        $gy_label -= 20;
    }

}

function specColor() {
    global $spec, $lightblue, $blue, $lightyellow, $yellow, $orange, $red, $white;

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

function starSize() {

    global $mag;

    if($mag > 8) {
        $size = 2;
        $labelsize = 1;
    } elseif($mag > 6) {
        $size = 20 - 2 * $mag;
        $labelsize = 1;
    } else {
        $size = 20 - 2 * $mag;
        $labelsize = 2;
    }

    return array($size, $labelsize);
}

function plotStar($select_star, $id) {
    global $image, $screen_x, $screen_y, $size, $starcolor, $black, $darkgrey, $red, $blue, $image_type;

    if($image_type == "printable") {
        $starcolor = $black;
        $boxcolor = $darkgrey;
    } else {
        $boxcolor = $blue;
    }
    
    ImageFilledEllipse($image,$screen_x,$screen_y,$size,$size,$starcolor);

    // selected star
    if($select_star == $id) {
        ImageRectangle($image,$screen_x-20,$screen_y-20,$screen_x+20,$screen_y+20,$boxcolor);
    }
}

function labelStar($name, $labelsize, $labelcolor) {
    global $image, $screen_x, $screen_y;

    ImageString($image,$labelsize,$screen_x + 5,$screen_y + 5,$name,$labelcolor);
}

?>
