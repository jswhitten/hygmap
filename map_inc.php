<?php

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
