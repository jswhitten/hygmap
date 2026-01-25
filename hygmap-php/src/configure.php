<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Units.php';
require_once __DIR__ . '/Database.php';

session_start();
Csrf::init();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate CSRF token
    $csrf_token = filter_input(INPUT_POST, 'csrf_token', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
    if (!Csrf::validate($csrf_token)) {
        http_response_code(403);
        die('Invalid or missing CSRF token. Please <a href="configure.php">try again</a>.');
    }

    // validate using filter_input
    $unit_input = filter_input(INPUT_POST, 'unit', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'pc';
    $unit = $unit_input === 'ly' ? 'ly' : 'pc';

    $grid = max(1, min(filter_input(INPUT_POST, 'grid', FILTER_VALIDATE_INT) ?? 20, 100));
    $maxLine = max(0, min(filter_input(INPUT_POST, 'max_line', FILTER_VALIDATE_INT) ?? 0, 100));

    // Build allowed layers: '0' (None) plus all world IDs from database
    try {
        $allowedLayers = array_merge(
            ['0'],
            array_map(fn($w) => (string)$w['id'], Database::queryWorlds())
        );
    } catch (PDOException $e) {
        error_log("Configure error: " . $e->getMessage());
        $allowedLayers = ['0']; // Fallback to None only when DB unavailable
    }
    $fic_input = filter_input(INPUT_POST, 'fic_names', FILTER_SANITIZE_SPECIAL_CHARS) ?? '0';
    $fic_names = in_array($fic_input, $allowedLayers, true) ? $fic_input : '0';

    $allowedImg = ['normal','stereo','printable'];
    $img_input = filter_input(INPUT_POST, 'image_type', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'normal';
    $imageType = in_array($img_input, $allowedImg, true) ? $img_input : 'normal';

    $imageSize = max(64, min(filter_input(INPUT_POST, 'image_size', FILTER_VALIDATE_INT) ?? 600, 4096));

    $mLimit = max(0, min(filter_input(INPUT_POST, 'm_limit', FILTER_VALIDATE_FLOAT) ?? 20, 30));
    $mLimitLabel = max(0, min(filter_input(INPUT_POST, 'm_limit_label', FILTER_VALIDATE_FLOAT) ?? 8, 30));

    $showSignals = filter_input(INPUT_POST, 'show_signals', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

    // save
    Config::save([
        'unit'            => $unit,
        'grid'            => $grid,
        'fic_names'       => $fic_names,
        'image_type'      => $imageType,
        'image_size'      => $imageSize,
        'max_line'        => $maxLine,
        'm_limit'         => $mLimit,
        'm_limit_label'   => $mLimitLabel,
        'show_signals'    => $showSignals,
    ]);

    // redirect back
    $prevUnit = filter_input(INPUT_POST, 'unit_prev', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'pc';
    if ($prevUnit !== 'pc' && $prevUnit !== 'ly') {
        $prevUnit = 'pc'; // fallback
    }

    $dest = filter_input(INPUT_POST, 'back', FILTER_SANITIZE_URL) ?? ($_SESSION['last_map'] ?? 'index.php');

    // Validate redirect destination is same-origin (prevent open redirect)
    $destParts = parse_url($dest);
    if (isset($destParts['host']) && $destParts['host'] !== ($_SERVER['HTTP_HOST'] ?? '')) {
        // External URL detected, fallback to safe default
        $dest = 'index.php';
    }

    // inject / convert params
    $parts = parse_url($dest);
    parse_str($parts['query'] ?? '', $q);

    // convert coords if unit changed
    if ($prevUnit !== $unit) {
        $factor = Units::LY_PER_PC;
        $mul = ($unit === 'ly') ? $factor : 1/$factor;

        foreach (['x_c','y_c','z_c','xy_zoom','z_zoom'] as $p) {
            if (isset($q[$p]) && is_numeric($q[$p])) {
                $q[$p] = $q[$p] * $mul;
            }
        }
    }

    // always attach u=
    $q['u'] = $unit;

    /* rebuild querystring */
    $parts['query'] = http_build_query($q);
    $dest =
    ($parts['path'] ?? '') .
    ($parts['query'] ? '?'.$parts['query'] : '') .
    (isset($parts['fragment']) ? '#'.$parts['fragment'] : '');

    header("Location: $dest", true, 302);
    exit;

}


$cfg  = Config::load();

// Validate referer is from same domain before using it
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$referer_host = $referer ? parse_url($referer, PHP_URL_HOST) : '';
$current_host = $_SERVER['HTTP_HOST'] ?? '';
$is_valid_referer = $referer_host && $referer_host === $current_host;
$back = $is_valid_referer ? $referer : ($_SESSION['last_map'] ?? 'index.php');
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HYGMap – Settings</title>
<link href="css/styles.css" rel="stylesheet">
</head><body class="configure-page">
<h2>Map Settings</h2>
<form action="configure.php" method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">

<!---------------- Units & Grid ---------------->
<fieldset><legend>Units &amp; Grid</legend>
<label><input type="radio" name="unit" value="pc" <?= $cfg['unit']==='pc'?'checked':'' ?>> Parsecs</label>
<label><input type="radio" name="unit" value="ly" <?= $cfg['unit']==='ly'?'checked':'' ?>> Light-years</label>
<label>Grid spacing (<span class="unitLabel"><?= htmlspecialchars($cfg['unit']) ?></span>):
 <input type="number" name="grid" min="1" max="100" value="<?= (int)$cfg['grid'] ?>"></label>
<label>Connecting-line max dist (<span class="unitLabel"><?= htmlspecialchars($cfg['unit']) ?></span>):
 <input type="number" name="max_line" min="0" max="100" value="<?= (int)$cfg['max_line'] ?>"></label>
</fieldset>

<!---------------- Fiction layer ---------------->
<fieldset><legend>Fictional names</legend>
<select name="fic_names">
 <option value="0" <?= $cfg['fic_names']==='0'?'selected':'' ?>>None</option>
 <?php 
 // Query worlds for dropdown
try {
    foreach(Database::queryWorlds() as $world) {
        echo '<option value="' . $world['id'] . '" ' . ((string)$cfg['fic_names']===(string)$world['id']?'selected':'') . '>' . htmlspecialchars($world['name']) . '</option>';
    }
} catch (PDOException $e) {
    error_log("Configure worlds error: " . $e->getMessage());
    echo '<option disabled>(Database unavailable)</option>';
}
?>
</select>
</fieldset>

<!---------------- Signals ---------------->
<fieldset><legend>Signals</legend>
<label>
  <input type="checkbox" name="show_signals" <?= !empty($cfg['show_signals']) ? 'checked' : '' ?>>
  Show SETI signals
</label>
</fieldset>

<!---------------- Image render ---------------->
<fieldset><legend>Image render</legend>
<label>Type:
 <select name="image_type" id="image_type">
  <?php foreach(['normal','stereo','printable'] as $it): ?>
   <option value="<?= $it ?>" <?= $cfg['image_type']===$it?'selected':'' ?>><?= ucfirst($it) ?></option>
  <?php endforeach; ?>
 </select></label>
<label>Size (px):
 <input type="number" name="image_size" id="image_size" min="64" max="4096" value="<?= (int)$cfg['image_size'] ?>"></label>
</fieldset>

<!---------------- Magnitude ------------>
<fieldset><legend>Filters</legend>
<label>Star mag limit:
 <input type="number" step=".1" name="m_limit" min="0" max="30" value="<?= $cfg['m_limit'] ?>"></label>
<label>Label mag limit:
 <input type="number" step=".1" name="m_limit_label" min="0" max="30" value="<?= $cfg['m_limit_label'] ?>"></label>
</fieldset>

<input type="hidden" name="back" value="<?= htmlspecialchars($back, ENT_QUOTES) ?>">
<button type="submit">Save &amp; Return</button>
<input type="hidden" name="unit_prev" value="<?= htmlspecialchars($cfg['unit']) ?>">
</form>

<script>
/* live-update unit labels when user toggles radio */
document.querySelectorAll('input[name="unit"]').forEach(r=>{
  r.addEventListener('change',()=>{
    document.querySelectorAll('.unitLabel').forEach(label=>{
      label.textContent=r.value;
    });
  });
});

/* auto-adjust image size when changing image type */
document.getElementById('image_type').addEventListener('change', function() {
  const imageSize = document.getElementById('image_size');
  if (this.value === 'stereo') {
    imageSize.value = '300';
  } else {
    imageSize.value = '600';
  }
});
</script>
</body></html>
