<?php declare(strict_types=1); error_reporting(E_ALL); ini_set('display_errors','1');

function getVars(): array
{
    // Default values
    $defaults = [
        'select_star'   => 0,
        'select_center' => 0,
        'x_c'           => 0.0,
        'y_c'           => 0.0,
        'z_c'           => 0.0,
        'xy_zoom'       => 25.0,
        'z_zoom'        => 25.0,
        'image_side'    => '',
    ];

    // Filter specification
    $spec = [
        'select_star'   => ['filter'=>FILTER_VALIDATE_INT,   'flags'=>FILTER_NULL_ON_FAILURE],
        'select_center' => ['filter'=>FILTER_VALIDATE_BOOLEAN, 'flags'=>FILTER_NULL_ON_FAILURE],
        'x_c'           => ['filter'=>FILTER_VALIDATE_FLOAT, 'flags'=>FILTER_NULL_ON_FAILURE],
        'y_c'           => ['filter'=>FILTER_VALIDATE_FLOAT, 'flags'=>FILTER_NULL_ON_FAILURE],
        'z_c'           => ['filter'=>FILTER_VALIDATE_FLOAT, 'flags'=>FILTER_NULL_ON_FAILURE],
        'xy_zoom'       => ['filter'=>FILTER_VALIDATE_FLOAT, 'flags'=>FILTER_NULL_ON_FAILURE],
        'z_zoom'        => ['filter'=>FILTER_VALIDATE_FLOAT, 'flags'=>FILTER_NULL_ON_FAILURE],
        'image_side'    => ['filter'=>FILTER_UNSAFE_RAW],
    ];

    $input = filter_input_array(INPUT_GET, $spec, true) ?: [];

    // Merge with defaults
    $vars = array_replace($defaults, array_filter($input, static fn($v) => $v !== null));
    $vars['image_side'] = in_array($vars['image_side'], ['left','right'], true)
    ? $vars['image_side']
    : '';

    // Post-validation / clamping
    $vars['xy_zoom']  = max(0.1, $vars['xy_zoom']);
    $vars['z_zoom']   = max(0.1, $vars['z_zoom']);

    return $vars;
}

// Call this at each point of interest, passing a descriptive string
function prof_flag($str)
{
    global $prof_timing, $prof_names;
    $prof_timing[] = microtime(true);
    $prof_names[] = $str;
}

// Call this when you're done and want to see the results
function prof_print()
{
    global $prof_timing, $prof_names;
    $size = count($prof_timing);
    for($i=0;$i<$size - 1; $i++)
    {
        echo "<b>{$prof_names[$i]}</b><br>";
        echo sprintf("&nbsp;&nbsp;&nbsp;%f<br>", $prof_timing[$i+1]-$prof_timing[$i]);
    }
    echo "<b>{$prof_names[$size-1]}</b><br>";
    echo "<b>Total time:</b> " . $prof_timing[$size-1]-$prof_timing[0];
}

const LY_PER_PC = 3.26156;

/* convert UI-units ➜ pc  (for queries, maths) */
function to_pc(float $v, string $unit): float {
    return $unit === 'ly' ? $v / LY_PER_PC : $v;
}
/* convert pc ➜ UI-units (for labels, tables) */
function from_pc(float $v, string $unit): float {
    return $unit === 'ly' ? $v * LY_PER_PC : $v;
}
/* convert UI-units ➜ ly (for ISDB) */
function to_ly(float $v, string $unit): float {
    return $unit === 'pc' ? $v * LY_PER_PC : $v;
}

/**
 * Get the display name for a star based on available identifiers
 * 
 * @param array $row Star data from database
 * @param int $fic_names Fiction world ID (0 = none, 1 = Star Trek, 2 = Babylon 5)
 * @param bool $with_color Whether to return color information (for map rendering)
 * @param string $image_type Image type ('printable', 'normal', etc.)
 * @param float $mag Star magnitude (for color determination)
 * @return string|array Returns name string, or [name, color] if $with_color is true
 */
function getStarDisplayName($row, $fic_names = 0, $with_color = false, $image_type = 'normal', $mag = 99) {
    global $yellow, $white, $black, $grey, $darkgrey;
    
    $name = '';
    $labelcolor = $darkgrey;
    $printcolor = $darkgrey;
    
    // Priority order for name selection
    if($fic_names > 0 && !empty($row["name"])) {
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
    } elseif(!empty($row["gj"])) {
        $name = "GJ " . $row["gj"];
        $labelcolor = $mag < 8.5 ? $grey : $darkgrey;
        $printcolor = $darkgrey;
    } elseif(!empty($row["hd"])) {
        $name = "HD " . $row["hd"];
        $labelcolor = $mag < 8.5 ? $grey : $darkgrey;
        $printcolor = $darkgrey;
    } elseif(!empty($row["hip"])) {
        $name = "HIP " . $row["hip"];
        $labelcolor = $mag < 8.5 ? $grey : $darkgrey;
        $printcolor = $darkgrey;
    } elseif(!empty($row["gaia"])) {
        $name = "Gaia " . $row["gaia"];
        $labelcolor = $darkgrey;
        $printcolor = $darkgrey;
    } elseif(!empty($row["spect"])) {
        $name = $row["spect"];
        $labelcolor = $darkgrey;
        $printcolor = $darkgrey;
    }
    
    if(!$with_color) {
        return $name;
    }
    
    // Apply printable mode color override
    if($image_type == "printable") {
        $labelcolor = $printcolor;
    }
    
    return [$name, $labelcolor];
}

