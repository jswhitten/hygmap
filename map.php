<?php
require 'db_inc.php';
require 'common_inc.php';

// Extract variables from query string
list($select_star, $select_center, $x_c, $y_c, $z_c, $zoom, $z_zoom, $mag_limit, $image_size, $image_type, $max_line, $trek_names) = getVars();

// Create image
while (@ob_end_clean());
Header("Content-type: image/jpeg");

$image = ImageCreate($image_size*2,$image_size);

// Allocate colors
list($white, $grey, $darkgrey, $green, $red, $orange, $lightyellow, $yellow, $lightblue, $blue, $darkblue, $black) = allocateColors();

// Fill background
if($image_type == "printable") {
    ImageFill($image,50,50,$white);
} else {
    ImageFill($image,50,50,$black);
}

// Connect, select, query database for stars within given coordinates
$link = open_db();

$result = query_all();
// XXX: WTF
$result_i = query_all();
$result_j = query_all();


// Draw grid
drawGrid();

// XXX: Plot connecting lines
if($max_line > 0) {
while ($row_i = mysqli_fetch_array($result_i, MYSQLI_ASSOC)) {
    $x_i = $row_i["X"];
    $y_i = $row_i["Y"];
    $z_i = $row_i["Z"];

    mysqli_data_seek($result_j, 0);
    while ($row_j = mysqli_fetch_array($result_j, MYSQLI_ASSOC)) {
        $x_j = $row_j["X"];
        $y_j = $row_j["Y"];
        $z_j = $row_j["Z"];

        $x_diff = $x_i - $x_j;
        $y_diff = $y_i - $y_j;
        $z_diff = $z_i - $z_j;
        $dist_sums = pow($x_diff,2) + pow($y_diff,2) + pow($z_diff,2);
        $dist_sqrt = sqrt($dist_sums);
        if(($row_i["AbsMag"] < $mag_limit) && ($row_j["AbsMag"] < $mag_limit)) {
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
while ($row = mysqli_fetch_array($result, MYSQLI_ASSOC)) {

    $id = $row["StarID"];
    $x = $row["X"];
    $y = $row["Y"];
    $z = $row["Z"];
    $mag = $row["AbsMag"];

    if($mag < $mag_limit) {

        list ($name, $labelcolor) = getLabel($trek_names);
        list ($screen_x, $screen_y) = screenCoords($x, $y, $z);
        $spec = getSpecClass($row["Spectrum"]); 
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

// Free resultset
mysqli_free_result($result);

// Close database connection */
mysqli_close($link);

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
    global $zoom, $z_zoom, $x_c, $y_c, $center_z, $image_size, $image_type;

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

function getLabel($trek_names) {
    global $row, $yellow, $white, $black, $grey, $darkgrey, $image_type;

    if($trek_names == "1" && isset($row["Name"]) && $row["Name"] != "") {
        $name = $row["Name"];
        $labelcolor = $yellow;
        $printcolor = $black;
    } elseif(isset($row["ProperName"]) && $row["ProperName"] != "") {
        $name = $row["ProperName"];
        $labelcolor = $white;
        $printcolor = $black;
    } elseif($row["BayerFlam"] != "" && $row["BayerFlam"] != "-") {
        $name = ltrim($row["BayerFlam"]);
        $labelcolor = $grey;
        $printcolor = $darkgrey;
    } elseif($row["Gliese"] != "") {
        $name = $row["Gliese"];
        $labelcolor = $grey;
        $printcolor = $darkgrey;
    } elseif($row["HD"] > 0) {
        $name = "HD".$row["HD"];
        $labelcolor = $grey;
        $printcolor = $darkgrey;
    } else {
        $name = $row["Spectrum"];
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

    global $y_c, $x_c, $zoom, $image, $green, $grey, $darkgrey, $image_size, $image_type;

    if($image_type == "printable") {
        $linecolor = $darkgrey;
    } else {
        $linecolor = $green;
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
        ImageLine($image,$g,0,$g,$image_size,$linecolor);
        ImageString($image,1,$g + 5,5,$gx_label,$grey);
        $gx_label -= 20;
    }
    for($g = $gys_first; $g < $image_size; $g += $gys_int) {
        ImageLine($image,0,$g,$image_size * 2,$g,$linecolor);
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
