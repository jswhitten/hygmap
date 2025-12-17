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

// --- Rendering Constants ---

// Star size and magnitude thresholds
const STAR_SIZE_BASE = 20;
const STAR_SIZE_FACTOR = 2;
const STAR_SIZE_MIN = 2;
const MAG_THRESHOLD_DIM = 8.0;        // Stars dimmer than this use minimum size
const MAG_THRESHOLD_MEDIUM = 6.0;     // Medium brightness stars
const MAG_THRESHOLD_BRIGHT = 3.0;     // Bright stars get larger labels
const MAG_THRESHOLD_FADE = 8.5;       // Threshold for fading catalog star labels
const MAG_THRESHOLD_DENSE_FIELD = 5.0;// Skip labels in dense fields above this mag

// Star label sizes
const LABEL_SIZE_SMALL = 1;
const LABEL_SIZE_MEDIUM = 2;
const LABEL_SIZE_LARGE = 4;

// Label overlap detection thresholds
const LABEL_OVERLAP_X_DIVISOR = 50;   // xy_zoom / 50
const LABEL_OVERLAP_Y_DIVISOR = 20;   // xy_zoom / 20

// Stereoscopic 3D rendering
const STEREO_OFFSET_MULTIPLIER = 4;   // Horizontal offset for 3D effect

// Signal visualization
const SIGNAL_ARC_OUTER = 18;          // Outer arc diameter
const SIGNAL_ARC_MIDDLE = 14;         // Middle arc diameter  
const SIGNAL_ARC_INNER = 10;          // Inner arc diameter
const SIGNAL_ARC_ANGLE = 45;          // Half-angle of arc (degrees)

// Selected star indicator
const SELECTED_STAR_BOX_SIZE = 20;    // Selection box half-width

// Color definitions (RGB)
const COLOR_WHITE = [255, 255, 255];
const COLOR_GREY = [204, 204, 204];
const COLOR_DARK_GREY = [102, 102, 102];
const COLOR_GREEN = [0, 150, 50];
const COLOR_RED = [255, 64, 0];
const COLOR_ORANGE = [255, 128, 0];
const COLOR_LIGHT_YELLOW = [255, 255, 160];
const COLOR_YELLOW = [255, 255, 0];
const COLOR_LIGHT_BLUE = [128, 204, 255];
const COLOR_BLUE = [64, 128, 255];
const COLOR_DARK_BLUE = [0, 64, 128];
const COLOR_BLACK = [0, 0, 0];

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
 * @param array $colors Color palette array (required when $with_color is true)
 * @return string|array Returns name string, or [name, color] if $with_color is true
 */
function getStarDisplayName(array $row, int $fic_names = 0, bool $with_color = false, string $image_type = 'normal', float $mag = 99.0, array $colors = []): string|array {
    $name = '';
    $labelcolor = $colors['darkgrey'] ?? 0;
    $printcolor = $colors['darkgrey'] ?? 0;

    // Priority order for name selection
    if($fic_names > 0 && !empty($row["name"])) {
        $name = $row["name"];
        $labelcolor = $colors['yellow'] ?? 0;
        $printcolor = $colors['black'] ?? 0;
    } elseif(!empty($row["proper"])) {
        $name = $row["proper"];
        $labelcolor = $colors['white'] ?? 0;
        $printcolor = $colors['black'] ?? 0;
    } elseif(!empty($row["bayer"])) {
        $name = ltrim($row["bayer"]) . " " . $row["con"];
        $labelcolor = $colors['grey'] ?? 0;
        $printcolor = $colors['darkgrey'] ?? 0;
    } elseif(!empty($row["flam"])) {
        $name = ltrim($row["flam"]) . " " . $row["con"];
        $labelcolor = $colors['grey'] ?? 0;
        $printcolor = $colors['darkgrey'] ?? 0;
    } elseif(!empty($row["gj"])) {
        $name = "GJ " . $row["gj"];
        $labelcolor = $mag < MAG_THRESHOLD_FADE ? ($colors['grey'] ?? 0) : ($colors['darkgrey'] ?? 0);
        $printcolor = $colors['darkgrey'] ?? 0;
    } elseif(!empty($row["hd"])) {
        $name = "HD " . $row["hd"];
        $labelcolor = $mag < MAG_THRESHOLD_FADE ? ($colors['grey'] ?? 0) : ($colors['darkgrey'] ?? 0);
        $printcolor = $colors['darkgrey'] ?? 0;
    } elseif(!empty($row["hip"])) {
        $name = "HIP " . $row["hip"];
        $labelcolor = $mag < MAG_THRESHOLD_FADE ? ($colors['grey'] ?? 0) : ($colors['darkgrey'] ?? 0);
        $printcolor = $colors['darkgrey'] ?? 0;
    } elseif(!empty($row["gaia"])) {
        $name = "Gaia " . $row["gaia"];
        $labelcolor = $colors['darkgrey'] ?? 0;
        $printcolor = $colors['darkgrey'] ?? 0;
    } elseif(!empty($row["spect"])) {
        $name = $row["spect"];
        $labelcolor = $colors['darkgrey'] ?? 0;
        $printcolor = $colors['darkgrey'] ?? 0;
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

/**
 * Calculate bounding box in parsecs for database queries
 * 
 * @param float $x_c Center X coordinate in UI units
 * @param float $y_c Center Y coordinate in UI units
 * @param float $z_c Center Z coordinate in UI units
 * @param float $xy_zoom X/Y zoom level in UI units
 * @param float $z_zoom Z zoom level in UI units
 * @param string $unit UI unit ('pc' or 'ly')
 * @param string $image_type Image type ('stereo', 'left', 'right', 'normal', 'printable')
 * @return array [xmin, xmax, ymin, ymax, zmin, zmax] in parsecs
 */
function buildBoundingBox(float $x_c, float $y_c, float $z_c, float $xy_zoom, float $z_zoom, string $unit, string $image_type = 'normal'): array {
    $xy_zoom_pc = to_pc($xy_zoom, $unit);
    $z_zoom_pc  = to_pc($z_zoom, $unit);
    
    // Stereo/3D modes use square aspect ratio, others use 2:1
    $is_stereo = in_array($image_type, ['stereo', 'left', 'right'], true);
    $y_multiplier = $is_stereo ? $xy_zoom_pc : 2 * $xy_zoom_pc;
    
    return [
        to_pc($x_c, $unit) - $xy_zoom_pc,      // xmin
        to_pc($x_c, $unit) + $xy_zoom_pc,      // xmax
        to_pc($y_c, $unit) - $y_multiplier,    // ymin
        to_pc($y_c, $unit) + $y_multiplier,    // ymax
        to_pc($z_c, $unit) - $z_zoom_pc,       // zmin
        to_pc($z_c, $unit) + $z_zoom_pc,       // zmax
    ];
}

/**
 * Display a user-friendly error message and log the technical details
 * 
 * @param string $userMessage User-friendly message
 * @param Exception $e The exception that occurred
 */
function handleError(string $userMessage, Exception $e): void {
    // Log the technical error
    error_log("HYGMap Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    
    // Display user-friendly message
    echo '<!DOCTYPE html><html><head><title>Error</title>';
    echo '<style>body{font-family:sans-serif;max-width:600px;margin:2rem auto;padding:1rem;}';
    echo '.error{background:#fee;border:2px solid #c00;padding:1rem;border-radius:4px;}';
    echo 'h1{color:#c00;}</style></head><body>';
    echo '<div class="error"><h1>⚠️ Error</h1>';
    echo '<p>' . htmlspecialchars($userMessage, ENT_QUOTES) . '</p>';
    echo '<p><a href="index.php">← Return to map</a></p>';
    echo '</div></body></html>';
    exit;
}

/**
 * Create an error image instead of crashing
 * 
 * @param string $message Error message to display
 */
function createErrorImage(string $message): void {
    $image = ImageCreate(400, 100);
    $bg = ImageColorAllocate($image, 255, 240, 240);
    $text = ImageColorAllocate($image, 200, 0, 0);
    ImageFill($image, 0, 0, $bg);
    ImageString($image, 3, 10, 40, $message, $text);
    
    header("Content-type: image/png");
    ImagePNG($image);
    ImageDestroy($image);
    exit;
}



