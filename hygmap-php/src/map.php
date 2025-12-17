<?php
declare(strict_types=1);
error_reporting(E_ERROR | E_WARNING | E_PARSE);

require_once __DIR__ . '/Database.php';
require 'common_inc.php';
require_once 'config.inc.php';

$cfg = cfg_load();
$unit = $cfg['unit'];
$grid = (float)$cfg['grid'];
$fic_names = (int)$cfg['fic_names'];
$image_type = $cfg['image_type'];
$image_size = (int)$cfg['image_size'];
$max_line = (float)$cfg['max_line'];
$m_limit = (float)$cfg['m_limit'];
$m_limit_label = (float)$cfg['m_limit_label'];
$show_signals = (bool)$cfg['show_signals'];

// Extract variables from query string
$vars = getVars();
$select_star = $vars['select_star'];
$select_center = $vars['select_center'];
$x_c = $vars['x_c'];
$y_c = $vars['y_c'];
$z_c = $vars['z_c'];
$xy_zoom = $vars['xy_zoom'];
$z_zoom = $vars['z_zoom'];
$image_side = $vars['image_side'];

// Create image
while (@ob_end_clean());
ob_start(); 
header("Content-type: image/jpeg");
// header("Content-type: text/plain");
// error_reporting(E_ALL);
// ini_set('display_errors', '1');
// Don't output the image, just show any errors

if($image_type == "stereo") {
    $image = ImageCreate($image_size,$image_size);
} else {
    $image = ImageCreate($image_size*2,$image_size);
}

// Allocate colors
list($white, $grey, $darkgrey, $green, $red, $orange, $lightyellow, $yellow, $lightblue, $blue, $darkblue, $black) = allocateColors();

// Store colors in array for easy passing
$colors = compact('white', 'grey', 'darkgrey', 'green', 'red', 'orange', 'lightyellow', 'yellow', 'lightblue', 'blue', 'darkblue', 'black');


// Fill background
ImageFill($image,50,50,($image_type == "printable") ? $white : $black);

// build bbox in pc
$bbox = buildBoundingBox($x_c, $y_c, $z_c, $xy_zoom, $z_zoom, $unit, $image_type);

// same for connecting-line limit
$max_line_pc = to_pc($max_line, $unit);

// Query all stars
try {
    $rows = Database::queryAll($bbox, $m_limit, (int)$fic_names, 'absmag desc');
} catch (PDOException $e) {
    error_log("Map generation error: " . $e->getMessage());
    createErrorImage("Database error - unable to load stars");
}


// query for signals (if enabled)
try {
    $signal_rows = $show_signals ? Database::querySignals($bbox) : [];
} catch (PDOException $e) {
    error_log("Signal query error: " . $e->getMessage());
    $signal_rows = []; // Continue without signals rather than failing completely
}


// Draw grid
drawGrid($grid, $colors);

// Plot connecting lines
if($max_line > 0) {
    // Pre-filter stars by magnitude and pre-calculate coordinates
    $eligible_stars = [];
    foreach($rows as $idx => $row) {
        if($row["absmag"] < $m_limit) {
            $x_ui = from_pc((float)$row["x"], $unit);
            $y_ui = from_pc((float)$row["y"], $unit);
            $z_ui = from_pc((float)$row["z"], $unit);
            
            list($screen_x, $screen_y) = screenCoords($x_ui, $y_ui, $z_ui);
            
            $eligible_stars[] = [
                'x' => $x_ui,
                'y' => $y_ui,
                'z' => $z_ui,
                'screen_x' => $screen_x,
                'screen_y' => $screen_y,
            ];
        }
    }
    
    // Only check upper triangle to avoid duplicate pairs (A-B vs B-A)
    $count = count($eligible_stars);

    // Calculate the squared distance thresholds
    $max_far_line2 = $max_line * $max_line;
    $max_mid_line2 = (0.75 * $max_line) * (0.75 * $max_line);
    $max_close_line2 = (0.5 * $max_line) * (0.5 * $max_line);

    for($i = 0; $i < $count - 1; $i++) {
        $star_i = $eligible_stars[$i];
        
        for($j = $i + 1; $j < $count; $j++) {
            $star_j = $eligible_stars[$j];
            
            // Calculate 3D distance squared to compare with thresholds
            $x_diff = $star_i['x'] - $star_j['x'];
            $y_diff = $star_i['y'] - $star_j['y'];
            $z_diff = $star_i['z'] - $star_j['z'];
            $dist2 = $x_diff * $x_diff + $y_diff * $y_diff + $z_diff * $z_diff;
            
            // Draw line based on distance thresholds
            if($dist2 < $max_close_line2) {
                ImageLine($image, 
                    (int)$star_i['screen_x'], (int)$star_i['screen_y'],
                    (int)$star_j['screen_x'], (int)$star_j['screen_y'],
                    $lightblue);
            } elseif($dist2 < $max_mid_line2) {
                ImageLine($image, 
                    (int)$star_i['screen_x'], (int)$star_i['screen_y'],
                    (int)$star_j['screen_x'], (int)$star_j['screen_y'],
                    $blue);
            } elseif($dist2 < $max_far_line2) {
                ImageLine($image, 
                    (int)$star_i['screen_x'], (int)$star_i['screen_y'],
                    (int)$star_j['screen_x'], (int)$star_j['screen_y'],
                    $darkblue);
            }
        }
    }
}

// Calculate the Sun's 2D screen position once
list($sun_sx, $sun_sy) = screenCoords(0, 0, 0);

// Plot each star
foreach($rows as $row) {
    $id = $row["id"];
    $x = (float)$row["x"];
    $y = (float)$row["y"];
    $z = (float)$row["z"];
    $mag = (float)$row["absmag"];

    if($mag < $m_limit) {
        list ($screen_x, $screen_y) = screenCoords(from_pc($x, $unit), from_pc($y, $unit), from_pc($z, $unit));
        $starcolor = specColor(getSpecClass($row["spect"]), $colors);
        list ($size, $labelsize) = starSize($mag);

        // plot star
        plotStar($screen_x, $screen_y, $size, $starcolor, $select_star == $id, $image_type, $colors);

        // label
	    $skiplabel = false;
	    if($select_star != $id) {
            if($mag > MAG_THRESHOLD_DENSE_FIELD && $id > 0) {
                $skiplabel = true;
            } elseif(sizeof($rows) > 1000) {
                if($mag > 5 && $id > 0) {
                    $skiplabel = true;
                }
            } else {
                foreach($rows as $checkrow) {
                    // if a brighter star is at the same location don't label this one
                    if((float)$checkrow['absmag'] < $mag) {  // Only check brighter stars
                        if(abs($checkrow['x']-$x) < $xy_zoom / LABEL_OVERLAP_X_DIVISOR && 
                            abs($checkrow['y']-$y) < $xy_zoom / LABEL_OVERLAP_Y_DIVISOR) {
                            $skiplabel = true;
                            break;
                        }
                    }
                }
	        }
	    }
        if(!$skiplabel) {
            list ($name, $labelcolor) = getLabel((int)$fic_names, $row, $image_type, $mag, $colors);
            labelStar($name, $labelsize, $labelcolor, $screen_x, $screen_y);
        }
    }
}

// Plot each signal
if($show_signals) {
    foreach ($signal_rows as $signal) {
        list ($screen_x, $screen_y) = screenCoords(
            from_pc((float)$signal['x'], $unit),
            from_pc((float)$signal['y'], $unit),
            from_pc((float)$signal['z'], $unit)
        );

        // Plot the signal's arcs, passing in the Sun's screen coordinates
        plotSignal($screen_x, $screen_y, $signal, $sun_sx, $sun_sy, $colors);


        // Add its label
        labelSignal($signal['name'], $screen_x, $screen_y, $colors);
    }
}

// draw it
ob_end_clean();  // Clear any stray output

ImageJPEG($image);
ImageDestroy($image);

function allocateColors(): array {

    global $image;

    $white = ImageColorAllocate($image, ...COLOR_WHITE);
    $grey = ImageColorAllocate($image, ...COLOR_GREY);
    $darkgrey = ImageColorAllocate($image, ...COLOR_DARK_GREY);
    $green = ImageColorAllocate($image, ...COLOR_GREEN);
    $red = ImageColorAllocate($image, ...COLOR_RED);
    $orange = ImageColorAllocate($image, ...COLOR_ORANGE);
    $lightyellow = ImageColorAllocate($image, ...COLOR_LIGHT_YELLOW);
    $yellow = ImageColorAllocate($image, ...COLOR_YELLOW);
    $lightblue = ImageColorAllocate($image, ...COLOR_LIGHT_BLUE);
    $blue = ImageColorAllocate($image, ...COLOR_BLUE);
    $darkblue = ImageColorAllocate($image, ...COLOR_DARK_BLUE);
    $black = ImageColorAllocate($image, ...COLOR_BLACK);

    return array($white, $grey, $darkgrey, $green, $red, $orange, $lightyellow, $yellow, $lightblue, $blue, $darkblue, $black);
}


function screenCoords(float $x, float $y, float $z): array {
    global $xy_zoom, $z_zoom, $x_c, $y_c, $z_c, $image_size, $image_side, $unit;

    if($image_side == "left" || $image_side == "right") {
        return screenCoords3d($x, $y, $z);
    }

    $screen_x = ($image_size) - (($image_size / (2 * $xy_zoom)) * ($y-$y_c));
    $screen_y = ($image_size / 2) - (($image_size / (2 * $xy_zoom)) * ($x-$x_c));

    return array($screen_x, $screen_y);
}

function screenCoords3d(float $x, float $y, float $z): array {
    global $xy_zoom, $z_zoom, $x_c, $y_c, $z_c, $image_size, $image_side;

    $screen_x = ($image_size / 2) - (($image_size / (2 * $xy_zoom)) * ($y-$y_c));
    $screen_y = ($image_size / 2) - (($image_size / (2 * $xy_zoom)) * ($x-$x_c));

    if($image_side == "left") {
        $screen_x += STEREO_OFFSET_MULTIPLIER * (($z - $z_c) / $z_zoom);
    }
    if($image_side == "right") {
        $screen_x -= STEREO_OFFSET_MULTIPLIER * (($z - $z_c) / $z_zoom);
    }

    return array($screen_x, $screen_y);
}

function getLabel(int $fic_names, array $row, string $image_type, float $mag, array $colors): array {
    return getStarDisplayName($row, $fic_names, true, $image_type, $mag, $colors);
}

function getSpecClass(?string $specdata): string {
    if(empty($specdata)) {
        return "";
    }
    $spec = substr($specdata,0,1);
    if($spec == " " || $spec == "s") {
        $spec = strtoupper(substr($specdata,2,1));
    }

    return $spec;
}

function drawGrid(float $distance, array $colors): void {
 
    global $y_c, $x_c, $xy_zoom, $image, $image_size, $image_type, $unit;
 
    if($image_type == "printable") {
        $linecolor = $colors['darkgrey'];
        $zerolinecolor = $colors['darkblue'];
    } else {
        $linecolor = $colors['green'];
        $zerolinecolor = $colors['blue'];
        if($image_type == "stereo") {
            drawGrid3d($distance, $colors);
            return;
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
        ImageString($image, 1, (int)$g + 5, 5, (string)round($gx_label, 2), $colors['grey']);
        $gx_label -= $distance;
    }
 
    for($g = $gys_first; $g < $image_size; $g += $gys_int) {
        ImageLine($image, 0, (int)$g, $image_size * 2, (int)$g, $gy_label == 0 ? $zerolinecolor : $linecolor);
        ImageString($image, 1, 5, (int)$g + 5, (string)round($gy_label, 2), $colors['grey']);
        $gy_label -= $distance;
    }
}

function drawGrid3d(float $distance, array $colors) : void {
 
    global $y_c, $x_c, $xy_zoom, $image, $image_size, $image_type, $unit;
 
    if($image_type == "printable") {
       $linecolor = $colors['darkgrey'];
    } else {
       $linecolor = $colors['green'];
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
        ImageString($image, 1, (int)$g + 5, 5, (string)round(from_pc($gx_label, $unit), 2), $colors['grey']);
        $gx_label -= $distance;
    }
 
    for($g = $gys_first; $g < $image_size; $g += $gys_int) {
        ImageLine($image, 0, (int)$g, $image_size, (int)$g, $linecolor);
        ImageString($image, 1, 5, (int)$g + 5, (string)round(from_pc($gy_label, $unit), 2), $colors['grey']);
        $gy_label -= $distance;
    }
}

function specColor(string $spec, array $colors): int {
    if($spec == "O") {
        $color = $colors['blue'];
    } elseif($spec == "B") {
        $color = $colors['lightblue'];
    } elseif($spec == "F") {
        $color = $colors['lightyellow'];
    } elseif($spec == "G") {
        $color = $colors['yellow'];
    } elseif($spec == "K" || $spec == "R") {
        $color = $colors['orange'];
    } elseif($spec == "M" || $spec == "C" || $spec == "N" || $spec == "S") {
        $color = $colors['red'];
    } else {
        $color = $colors['white'];
    }
    return $color;
}

function starSize(float $mag): array {
    if($mag > MAG_THRESHOLD_DIM) {
        $size = STAR_SIZE_MIN;
        $labelsize = LABEL_SIZE_SMALL;
    } elseif($mag > MAG_THRESHOLD_MEDIUM) {
        $size = (int)(STAR_SIZE_BASE - STAR_SIZE_FACTOR * $mag);
        $labelsize = LABEL_SIZE_SMALL;
    } elseif($mag > MAG_THRESHOLD_BRIGHT) {
        $size = (int)(STAR_SIZE_BASE - STAR_SIZE_FACTOR * $mag);
        $labelsize = LABEL_SIZE_MEDIUM;        
    } else {
        $size = (int)(STAR_SIZE_BASE - STAR_SIZE_FACTOR * $mag);
        $labelsize = LABEL_SIZE_LARGE;
    }

    return array($size, $labelsize);
}

function plotStar(float $screen_x, float $screen_y, int $size, int $starcolor, bool $selected, string $image_type, array $colors): void {
    global $image;
 
    if($image_type == "printable") {
        $starcolor = $colors['black'];
        $boxcolor = $colors['darkgrey'];
    } else {
        $boxcolor = $colors['blue'];
    }
    
    ImageFilledEllipse($image,(int)$screen_x,(int)$screen_y,(int)$size,(int)$size,$starcolor);

    // selected star
    if($selected) {
        ImageRectangle($image,
            (int)$screen_x - SELECTED_STAR_BOX_SIZE,
            (int)$screen_y - SELECTED_STAR_BOX_SIZE,
            (int)$screen_x + SELECTED_STAR_BOX_SIZE,
            (int)$screen_y + SELECTED_STAR_BOX_SIZE,
            $boxcolor);
    }

}

function labelStar(string $name, int $labelsize, int $labelcolor, float $screen_x, float $screen_y): void {
    global $image;

    ImageString($image,$labelsize,(int)$screen_x + 5,(int)$screen_y + 5,$name,$labelcolor);
}

/**
 * Plots a signal on the map as three directional, concentric arcs.
 * The arcs will "point" away from the Sun's projected position on the screen.
 */
function plotSignal(float $screen_x, float $screen_y, array $signal, float $sun_sx, float $sun_sy, array $colors): void {
    global $image;

    // Calculate direction
    $angle_rad = atan2($screen_y - $sun_sy, $screen_x - $sun_sx);
    $angle_deg = rad2deg($angle_rad);
    $startAngle = $angle_deg - SIGNAL_ARC_ANGLE;
    $endAngle   = $angle_deg + SIGNAL_ARC_ANGLE;

    // Select colors based on signal type
    if ($signal['type'] === 'transmit') {
        $c1 = $colors['orange'];
        $c2 = $colors['red'];
        $c3 = $colors['darkblue'];
    } else {
        $c1 = $colors['lightblue'];
        $c2 = $colors['blue'];
        $c3 = $colors['darkblue'];
    }

    // Draw arcs (unchanged)
    ImageArc($image, (int)$screen_x, (int)$screen_y, SIGNAL_ARC_OUTER, SIGNAL_ARC_OUTER, (int)$startAngle, (int)$endAngle, $c3);
    ImageArc($image, (int)$screen_x, (int)$screen_y, SIGNAL_ARC_MIDDLE, SIGNAL_ARC_MIDDLE, (int)$startAngle, (int)$endAngle, $c2);
    ImageArc($image, (int)$screen_x, (int)$screen_y, SIGNAL_ARC_INNER, SIGNAL_ARC_INNER, (int)$startAngle, (int)$endAngle, $c1);
}


/**
 * Labels a signal on the map.
 */
function labelSignal(string $name, float $screen_x, float $screen_y, array $colors): void {
    global $image;
    ImageString($image, 2, (int)$screen_x + 12, (int)$screen_y - 8, $name, $colors['lightblue']);
}

?>
