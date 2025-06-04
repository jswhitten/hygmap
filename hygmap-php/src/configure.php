<?php
declare(strict_types=1);
require_once 'config.inc.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---------- validate -------------
    $unit  = ($_POST['unit'] ?? 'pc') === 'ly' ? 'ly' : 'pc';
    $grid  = max(1, min((int)($_POST['grid'] ?? 20), 100));

    $maxLine = max(0, min((int)($_POST['max_line'] ?? 0), 100));

    $allowedLayers = ['0','1','2'];
    $fic_names = in_array($_POST['fic_names'] ?? '0', $allowedLayers, true)
           ? $_POST['fic_names'] : '0';

    $allowedImg = ['normal','stereo','printable'];
    $imageType  = in_array($_POST['image_type'] ?? 'normal', $allowedImg, true)
                ? $_POST['image_type'] : 'normal';

    $imageSize  = max(64, min((int)($_POST['image_size'] ?? 600), 4096));

    $mLimit       = max(0, min((float)($_POST['m_limit'] ?? 20), 30));
    $mLimitLabel  = max(0, min((float)($_POST['m_limit_label'] ?? 8), 30));

    // ---------- save -------------
    cfg_set([
        'unit'            => $unit,
        'grid'            => $grid,
        'fic_names'       => $fic_names,
        'image_type'      => $imageType,
        'image_size'      => $imageSize,
        'max_line'        => $maxLine,
        'm_limit'         => $mLimit,
        'm_limit_label'   => $mLimitLabel,
    ]);

    // ---------- redirect back -------------
    $prevUnit = $_POST['unit_prev'] ?? 'pc';   // what the map was using
    if ($prevUnit !== 'pc' && $prevUnit !== 'ly') {
        $prevUnit = 'pc'; // fallback
    }

    $dest = $_POST['back'] ?? ($_SESSION['last_map'] ?? 'index.php');

    // ------------ inject / convert params -------------
    $parts = parse_url($dest);
    parse_str($parts['query'] ?? '', $q);

    // convert coords if unit changed
    if ($prevUnit !== $unit) {
        $factor = 3.26156;                     // pc → ly
        $mul = ($unit === 'ly') ? $factor : 1/$factor;

        foreach (['x_c','y_c','z_c','xy_zoom','z_zoom'] as $p) {
            if (isset($q[$p]) && is_numeric($q[$p])) {
                $q[$p] = $q[$p] * $mul;
            }
        }
    }

    // always attach u=
    $q['u'] = $unit;

    if( isset($select_star) && is_numeric($select_star)) {
        $q['select_star'] = $select_star;
    }

    /* rebuild querystring */
    $parts['query'] = http_build_query($q);
    $dest =
    ($parts['path'] ?? '') .
    ($parts['query'] ? '?'.$parts['query'] : '') .
    (isset($parts['fragment']) ? '#'.$parts['fragment'] : '');

    header("Location: $dest", true, 302);
    exit;

}


$cfg  = cfg_load();

$back = $_SERVER['HTTP_REFERER'] ?? ($_SESSION['last_map'] ?? 'index.php');
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<title>HYGMap – Settings</title>
<style>
 body{font-family:sans-serif;margin:2rem auto;max-width:520px}
 fieldset{margin-bottom:1.2rem} label{display:block;margin:.4rem 0}
 input[type=number]{width:6rem}
</style></head><body>
<h2>Map Settings</h2>
<form action="configure.php" method="post">
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
 <?php foreach(['0'=>'None','1'=>'Star Trek','2'=>'Babylon 5'] as $val=>$label): ?>
  <option value="<?= $val ?>" <?= (string)$cfg['fic_names']===(string)$val?'selected':'' ?>><?= $label ?></option>
 <?php endforeach; ?>
</select>
</fieldset>

<!---------------- Image render ---------------->
<fieldset><legend>Image render</legend>
<label>Type:
 <select name="image_type" onclick="if(this.value === 'stereo') { document.getElementById('image_size').value = '300'; } else { document.getElementById('image_size').value = '600'; }">
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
</script>
</body></html>
