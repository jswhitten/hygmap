<?php declare(strict_types=1); error_reporting(E_ALL); ini_set('display_errors','1');

function getVars(): array
{
    // ---------- Default values ----------
    $defaults = [
        'select_star'   => 0,
        'select_center' => 0,          // bool as 0/1
        'x_c'           => 0.0,
        'y_c'           => 0.0,
        'z_c'           => 0.0,
        'xy_zoom'       => 10.0,
        'z_zoom'        => 10.0,
        'image_side'    => '',
    ];

    // ---------- Filter specification ----------
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

    // ---------- Merge with defaults ----------
    $vars = array_replace($defaults, array_filter($input, static fn($v) => $v !== null));
    $vars['image_side'] = in_array($vars['image_side'], ['left','right'], true)
    ? $vars['image_side']
    : '';

    // ---------- Post-validation / clamping ----------
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

