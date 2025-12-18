<?php
declare(strict_types=1);

require 'bootstrap.php';
require_once 'IndexHelpers.php';

// =============================================================================
// DATA FETCHING AND TRANSFORMATION (Business Logic)
// =============================================================================

$profiler->flag("START");
header("X-Robots-Tag: noindex");

// Extract configuration
$unit = $cfg['unit'];
$fic_names = (int)$cfg['fic_names'];
$image_type = $cfg['image_type'];
$image_size = (int)$cfg['image_size'];
$m_limit = (float)$cfg['m_limit'];
$m_limit_label = (float)$cfg['m_limit_label'];
$profiling = (bool)$cfg['profiling'];

// Extract view parameters
$select_star = $vars['select_star'];
$select_center = $vars['select_center'];
$view_coords = [
    'x_c' => $vars['x_c'],
    'y_c' => $vars['y_c'],
    'z_c' => $vars['z_c'],
];
$xy_zoom = $vars['xy_zoom'];
$z_zoom = $vars['z_zoom'];

// Fetch selected star and update view center if requested
$profiler->flag("Querying selected star");
$selected_star = fetchSelectedStar($select_star, $select_center, $fic_names, $unit, $view_coords);

// Extract updated coordinates (may have been modified by fetchSelectedStar)
$x_c = $view_coords['x_c'];
$y_c = $view_coords['y_c'];
$z_c = $view_coords['z_c'];

// Build selected star display data
$star_info = buildSelectedStarData($selected_star, $fic_names, $unit);

// Build map image HTML
$baseParams = [
    'x_c' => $x_c,
    'y_c' => $y_c,
    'z_c' => $z_c,
    'xy_zoom' => $xy_zoom,
    'z_zoom' => $z_zoom,
    'm_limit' => $m_limit,
    'm_limit_label' => $m_limit_label,
    'select_star' => $select_star,
    'image_size' => $image_size,
    'max_line' => (float)$cfg['max_line'],
];
$map_html = buildMapHtml($image_type, $image_size, $baseParams);

// Fetch star search options
$profiler->flag("Querying star proper names");
$proper_options = fetchProperStarOptions();

$profiler->flag("Querying fictional star names");
$fictional_options = $fic_names > 0 ? fetchFictionalStarOptions($fic_names) : '';

// Fetch star table data
$profiler->flag("Querying all stars in map");
$bbox = buildBoundingBox($x_c, $y_c, $z_c, $xy_zoom, $z_zoom, $unit, $image_type);
$star_table_data = fetchStarTableData($bbox, $m_limit, $m_limit_label, $fic_names, $unit, $view_coords);

// Build interactive star data for map overlay (tooltips, click handling)
$profiler->flag("Building interactive star data");
$interactive_stars = buildInteractiveStarData(
    $bbox, $m_limit, $m_limit_label, $fic_names, $unit, $view_coords,
    $xy_zoom, $z_zoom, $image_size, $image_type
);
$profiler->flag("Query complete");

// Prepare view variables
$select_center_checked = ($selected_star && $select_center == 1) ? "CHECKED" : "";
$sel_normal = ($image_type == "normal") ? "SELECTED" : "";
$sel_3d = ($image_type == "stereo") ? "SELECTED" : "";
$sel_printable = ($image_type == "printable") ? "SELECTED" : "";

$profiler->flag('FINISH');

// =============================================================================
// PRESENTATION LAYER (HTML Template)
// =============================================================================
?>
<!DOCTYPE html>
<html>
<head>
   <title>HYGMap</title>
   <link href="css/styles.css" rel="stylesheet">
</head>
<body>
<span class="toplink"><a href="index.php">HYGMap</a></span>
<span class="toplink"><a href="https://github.com/jswhitten/hygmap">Source code</a></span>
<span class="toplink"><a href="configure.php">Configure</a></span>
<span class="toplink"><a href="changelog.html">Change log</a></span>

<!-- PAGE WRAPPER -->
<div class="page">

  <!-- SIDEBAR (LEFT) -->
  <div class="sidebar">

    <!-- SELECTED STAR INFO -->
    <div class="info">
      <?php if ($star_info['has_star']): ?>
        <h3><?= $star_info['display_name'] ?></h3>
        <table width="100%" cellpadding="1" cellspacing="1">
          <tr>
            <td width="50%">Absolute magnitude</td>
            <td><?= htmlspecialchars((string)$star_info['absmag'], ENT_QUOTES) ?></td>
          </tr>
          <tr>
            <td>Spectral type</td>
            <td><?= htmlspecialchars($star_info['spect'], ENT_QUOTES) ?></td>
          </tr>
          <tr>
            <td>Distance from Sol</td>
            <td><?= htmlspecialchars($star_info['distance_ui'], ENT_QUOTES) ?> <?= htmlspecialchars($star_info['unit'], ENT_QUOTES) ?></td>
          </tr>
          <tr>
            <td>Galactic coordinates</td>
            <td><?= htmlspecialchars($star_info['x_ui'], ENT_QUOTES) ?>, <?= htmlspecialchars($star_info['y_ui'], ENT_QUOTES) ?>, <?= htmlspecialchars($star_info['z_ui'], ENT_QUOTES) ?></td>
          </tr>
          <tr>
            <td>Apparent magnitude</td>
            <td><?= htmlspecialchars((string)$star_info['mag'], ENT_QUOTES) ?></td>
          </tr>
          <tr>
            <td>Sky coordinates</td>
            <td><?= htmlspecialchars((string)$star_info['ra'], ENT_QUOTES) ?> h, <?= htmlspecialchars((string)$star_info['dec'], ENT_QUOTES) ?>°</td>
          </tr>
          <tr>
            <td colspan="2">
              <br/>
              [ <a href="http://www.stellar-database.com/Scripts/find_neighbors.exe?ly=2&X=<?= urlencode((string)$star_info['x_ly']) ?>&Y=<?= urlencode((string)$star_info['y_ly']) ?>&Z=<?= urlencode((string)$star_info['z_ly']) ?>" target="_blank">Look up this star at stellar-database.com</a> ]<br/>
              <br/>
              [ <a href="http://simbad.u-strasbg.fr/sim-id.pl?protocol=html&Ident=<?= urlencode((string)$star_info['selected_ra_deg']) ?>+<?= urlencode((string)$star_info['selected_dec_simbad']) ?>&NbIdent=1&Radius=1&Radius.unit=arcmin&CooFrame=FK5&CooEpoch=2000&CooEqui=2000&output.max=all&o.catall=on&output.mesdisp=N&Bibyear1=1983&Bibyear2=2004&Frame1=FK5&Frame2=FK4&Frame3=G&Equi1=2000.0&Equi2=1950.0&Equi3=2000.0&Epoch1=2000.0&Epoch2=1950.0&Epoch3=2000.0" target="_blank">Look up this star in SIMBAD</a> ]<br/>
              <br/>
              [ <a href="http://www.fourmilab.ch/cgi-bin/uncgi/Yourtel?lon=<?= urlencode((string)$star_info['ra']) ?>h&lat=<?= urlencode((string)$star_info['selected_dec_av']) ?>&ns=<?= urlencode($star_info['selected_dec_ns']) ?>&date=0&fov=45&coords=1&moonp=1&deep=1&deepm=7&consto=1&constn=1&constb=1&limag=6.5&starn=1&starnm=3.5&starb=1&starbm=4.5&imgsize=512&scheme=0" target="_blank">Plot a sky map centered on this star at fourmilab.ch</a> ]<br/>
              <?= $star_info['memory_alpha'] ?>
            </td>
          </tr>
        </table>
      <?php else: ?>
        <em>No star selected.</em>
      <?php endif; ?>
    </div>

    <!-- CENTER + ZOOM FORM -->
    <form method="GET" action="index.php">
      <fieldset class="menupanel">
        <legend>Center (<?= htmlspecialchars($unit, ENT_QUOTES) ?>)</legend>
        X <input name="x_c" size="6" value="<?= htmlspecialchars((string)$x_c, ENT_QUOTES) ?>"><br>
        Y <input name="y_c" size="6" value="<?= htmlspecialchars((string)$y_c, ENT_QUOTES) ?>"><br>
        Z <input name="z_c" size="6" value="<?= htmlspecialchars((string)$z_c, ENT_QUOTES) ?>">
      </fieldset>

      <fieldset class="menupanel">
        <legend>Zoom (<?= htmlspecialchars($unit, ENT_QUOTES) ?>)</legend>
        X <input name="xy_zoom" id="xy_zoom" size="4" value="<?= htmlspecialchars((string)$xy_zoom, ENT_QUOTES) ?>"
                 onkeyup="document.getElementById('y_zoom').value=this.value*2;"><br>
        Y <input id="y_zoom" size="4" value="<?= htmlspecialchars((string)($xy_zoom*2), ENT_QUOTES) ?>" disabled><br>
        Z <input name="z_zoom" size="4" value="<?= htmlspecialchars((string)$z_zoom, ENT_QUOTES) ?>">
      </fieldset>
      <input type="hidden" name="select_star" value="<?= (int)$select_star ?>">

      <button type="submit">Get map</button>
    </form>

   <!-- STAR SEARCH -->
   <fieldset class="menupanel">
     <legend>Search for star</legend>

     <!-- Proper names dropdown -->
     <form method="GET" action="index.php">
       <input type="hidden" name="select_center" value="1">
       <select name="select_star">
         <option value="">(Proper names)</option>
         <?= $proper_options ?>
       </select>
       <button type="submit">Go</button>
     </form>
     <br>

     <!-- Fictional names dropdown -->
     <?php if ($fic_names > 0): ?>
       <form method="GET" action="index.php">
         <input type="hidden" name="select_center" value="1">
         <select name="select_star">
           <option value="">(Fictional names)</option>
           <?= $fictional_options ?>
         </select>
         <button type="submit">Go</button>
       </form>
       <br>
     <?php endif; ?>

     <!-- Catalog search -->
     <form method="GET" action="search.php">
       <input type="text" name="q" size="20" placeholder="Alpha Cen / HD 48915">
       <button type="submit">Go</button>
     </form>
   </fieldset>

  </div><!-- /sidebar -->

  <!-- MAIN MAP (RIGHT) -->
  <div class="main">
    <div class="map-container" id="map-container">
      <?= $map_html ?>
      <div id="star-tooltip" class="star-tooltip"></div>
    </div>
    <div class="map-status">
      <small>Hover for info • Click to select • Arrows pan • +/- zoom • Home=Sol</small>
      <span id="cursor-coords" class="cursor-coords"></span>
    </div>
  </div>

</div><!-- /page -->

<!-- DATA TABLE -->
<div class="datatable">
  <b>Stars in current map</b><br>
  <small>Click column heading to sort</small><br>
  <table id="star-table" cellspacing="2" cellpadding="5">
    <tr>
      <th>Name</th><th>Con</th><th>Spectral</th><th>Abs Mag</th>
      <th>Dist (<?= htmlspecialchars($unit, ENT_QUOTES) ?>)</th><th>Δ Center</th><th>X</th><th>Y</th><th>Z</th>
    </tr>
    <?php foreach ($star_table_data['rows'] as $star): ?>
    <tr>
      <td><a href="?select_star=<?= (int)$star['id'] ?>&select_center=1"><?= htmlspecialchars($star['name'], ENT_QUOTES) ?></a></td>
      <td><?= htmlspecialchars($star['con'], ENT_QUOTES) ?></td>
      <td><?= htmlspecialchars($star['spect'], ENT_QUOTES) ?></td>
      <td><?= htmlspecialchars((string)$star['absmag'], ENT_QUOTES) ?></td>
      <td><?= htmlspecialchars($star['distance'], ENT_QUOTES) ?></td>
      <td><?= htmlspecialchars($star['distance_from_center'], ENT_QUOTES) ?></td>
      <td><?= htmlspecialchars($star['x'], ENT_QUOTES) ?></td>
      <td><?= htmlspecialchars($star['y'], ENT_QUOTES) ?></td>
      <td><?= htmlspecialchars($star['z'], ENT_QUOTES) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?= (int)$star_table_data['displayed_count'] ?> of <?= (int)$star_table_data['total_count'] ?> stars displayed.
</div>

<?php if ($profiling): ?>
<div style="margin:1rem auto;max-width:95%;font-size:small;text-align:left">
  <?= $profiler->getReport() ?>
</div>
<?php endif; ?>

<script src="js/table-sort.js"></script>

<!-- Interactive map data and script -->
<script>
window.HYGMapData = {
  stars: <?= json_encode($interactive_stars, JSON_NUMERIC_CHECK) ?>,
  selectedStarId: <?= (int)$select_star ?>,
  unit: <?= json_encode($unit) ?>,
  imageType: <?= json_encode($image_type) ?>,
  view: {
    x_c: <?= json_encode($x_c) ?>,
    y_c: <?= json_encode($y_c) ?>,
    z_c: <?= json_encode($z_c) ?>,
    xy_zoom: <?= json_encode($xy_zoom) ?>,
    z_zoom: <?= json_encode($z_zoom) ?>,
    imageSize: <?= json_encode($image_size) ?>
  }
};
</script>
<script src="js/map-interactive.js"></script>

</body>
</html>
