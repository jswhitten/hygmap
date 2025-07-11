<?php
require('common_inc.php');
require_once __DIR__ . '/Database.php';
require_once 'config.inc.php';

$cfg = cfg_load();
extract($cfg);               // gives $unit, $grid, $fic_names, $image_type, etc.

prof_flag("START");

header("X-Robots-Tag: noindex");

// Extract variables from query string
$vars = getVars();
extract($vars);
$select_center_checked = "";

$profiling = true;

if($select_star > 0) {
   // Find the center from the selected star
   prof_flag("Querying selected star");
   $selected_star = Database::queryStar((int)$select_star, (int)$fic_names);

   if($select_center == "1") {
      $x_c = from_pc($selected_star["x"], $unit); 
      $y_c = from_pc($selected_star["y"], $unit); 
      $z_c = from_pc($selected_star["z"], $unit); 
      $select_center_checked = "CHECKED";
   }
}

// Image type selection
$sel_normal = ($image_type == "normal")?"SELECTED":"";
$sel_3d = ($image_type == "stereo")?"SELECTED":"";
$sel_printable = ($image_type == "printable")?"SELECTED":"";

// Selected star
$memory_alpha = '';
if($select_star > 0) {
   $selected_ra_deg = $selected_star["ra"] * 360 / 24;
   $selected_dec_av = abs($selected_star["dec"]);
   if($selected_star["dec"] >= 0) { 
      $selected_dec_ns = 'North';
      $selected_dec_simbad = '%2B'.$selected_dec_av;
   } else {
      $selected_dec_ns = 'South';
      $selected_dec_simbad = $selected_star["dec"];
   }
   $select_star_name = getDisplayName($selected_star, 0);
   $selected_display_name = $select_star_name;
   if(!empty($selected_star["proper"]) || !empty($selected_star["bf"])) {
      $selected_display_name = '<a href="https://en.wikipedia.org/w/index.php?title=Special%3ASearch&search=' . $selected_display_name . '">' . $selected_display_name . '</a>';
   }
   if($fic_names && $selected_star["name"] != "" && $selected_display_name != $selected_star["name"]) {
      $selected_display_name .= " (" . $selected_star["name"] . ")";
      $memory_alpha = <<<END
<br/>
[ <a href="https://memory-alpha.fandom.com/wiki/Special:Search?query={$selected_star["name"]}&scope=internal&navigationSearch=true" target="_blank">Search Memory Alpha for this star system</a> ]<br/>
END;
   }
}

// Retrieve list of stars with fictional names
prof_flag("Querying fictional star names");
$fic_checked = ((int)$fic_names > 0) ? "CHECKED" : "";
$fic_rows = Database::queryFiction($fic_names);
$fic_options = "";
foreach ($fic_rows as $fic_row) {
      $fic_options .= "<option value=\"$fic_row[star_id]\">$fic_row[name]\n";
}

prof_flag("Querying star proper names");
$proper_options = '';
foreach (Database::queryProperNames() as $p) {   // small helper returning id,proper
    $proper_options .= "<option value=\"{$p['id']}\">{$p['proper']}</option>\n";
}


// Generate html for map
// ------------------------------------------------------------------
// Build the common query parameters once
// ------------------------------------------------------------------
$baseParams = [
   'x_c'            => $x_c,
   'y_c'            => $y_c,
   'z_c'            => $z_c,
   'xy_zoom'        => $xy_zoom,
   'z_zoom'         => $z_zoom,
   'm_limit'        => $m_limit,
   'm_limit_label'  => $m_limit_label,
   'select_star'    => $select_star,
   'image_size'     => $image_size,
   'max_line'       => $max_line,
];

// Generate <img> tags
if ($image_type === 'stereo') {
   // LEFT
   $leftSrc  = 'map.php?' . http_build_query($baseParams + ['image_side' => 'left']);
   // RIGHT
   $rightSrc = 'map.php?' . http_build_query($baseParams + ['image_side' => 'right']);

   $map  = '<img src="'  . htmlspecialchars($leftSrc)  . '" ';
   $map .= 'width="'    . (int)$image_size . '" height="' . (int)$image_size . '">&nbsp;';
   $map .= '<img src="' . htmlspecialchars($rightSrc) . '" ';
   $map .= 'width="'    . (int)$image_size . '" height="' . (int)$image_size . '">';

} else {
   $src = 'map.php?' . http_build_query($baseParams);

   // For the single-image case you double the width
   $map  = '<img src="' . htmlspecialchars($src) . '" ';
   $map .= 'width="'   . (int)($image_size * 2) . '" height="' . (int)$image_size . '">';
}

// Get data for star table
prof_flag("Querying all stars in map");
$xy_zoom_pc = to_pc($xy_zoom, $unit);
$z_zoom_pc  = to_pc($z_zoom , $unit);

$bbox = [
    to_pc($x_c, $unit) - $xy_zoom_pc,
    to_pc($x_c, $unit) + $xy_zoom_pc,
    to_pc($y_c, $unit) - ($image_type==='stereo' ? $xy_zoom_pc : 2*$xy_zoom_pc),
    to_pc($y_c, $unit) + ($image_type==='stereo' ? $xy_zoom_pc : 2*$xy_zoom_pc),
    to_pc($z_c, $unit) - $z_zoom_pc,
    to_pc($z_c, $unit) + $z_zoom_pc,
];


$rows = Database::queryAll($bbox, $m_limit, $fic_names, 'absmag asc');
prof_flag("Query complete");
$star_count = 0;
$star_count_displayed = 0;
$star_table = "";
foreach ($rows as $row) {
   $star_count++;
   $display_name = getDisplayName($row, 0);
   $distance_from_center = number_format(sqrt(pow(from_pc($row["x"], $unit) - $x_c, 2) + pow(from_pc($row["y"], $unit) - $y_c, 2) + pow(from_pc($row["z"], $unit) - $z_c, 2)), 3);
   $distance_ui = number_format(from_pc($row["dist"], $unit), 3);
   $x_ui = number_format(from_pc($row["x"], $unit), 3);
   $y_ui = number_format(from_pc($row["y"], $unit), 3); 
   $z_ui = number_format(from_pc($row["z"], $unit), 3);

   if($row['absmag'] < $m_limit_label) {
      $star_count_displayed++;
      $star_table .= <<<END
         <tr>
		 <td><a href="?select_star={$row['id']}&select_center=1">$display_name</a></td><td>{$row["con"]}</td><td>{$row["spect"]}</td><td>{$row["absmag"]}</td><td>{$distance_ui}</td><td>$distance_from_center</td><td>{$x_ui}</td><td>{$y_ui}</td><td>{$z_ui}</td>
         </tr>\n
END;
   }
}

// Build details for selected star
$selected_data = '';
if($select_star > 0) {
   $distance_pc = $selected_star["dist"];
   $distance_ui = number_format(from_pc($distance_pc, $unit), 3);
   $x_ui = number_format(from_pc($selected_star["x"], $unit), 3);
   $y_ui = number_format(from_pc($selected_star["y"], $unit), 3);
   $z_ui = number_format(from_pc($selected_star["z"], $unit), 3);

   $x_ly = to_ly($selected_star["x"], "pc");
   $y_ly = to_ly($selected_star["y"], "pc");
   $z_ly = to_ly($selected_star["z"], "pc");

   $selected_data = <<<END
   <h3>$selected_display_name</h3>
   <table width=100% cellpadding=1 cellspacing=1>
      <tr>
         <td width=50%>Absolute magnitude</td><td>{$selected_star["absmag"]}</td>
      </tr><tr>
         <td>Spectral type</td><td>{$selected_star["spect"]}</td>
      </tr><tr>
         <td>Distance from Sol</td><td>{$distance_ui} $unit</td>
      </tr><tr>
         <td>Galactic coordinates</td><td>{$x_ui}, {$y_ui}, {$z_ui}</td>
      </tr><tr>
         <td>Apparent magnitude</td><td>{$selected_star["mag"]}</td> 
      </tr><tr>
         <td>Sky coordinates</td><td>{$selected_star["ra"]} h, {$selected_star["dec"]}°</td>
     </tr>
     <tr>
      <td colspan="2">
      <br/>
      [ <a href="http://www.stellar-database.com/Scripts/find_neighbors.exe?ly=2&X={$x_ly}&Y={$y_ly}&Z={$z_ly}" target="_blank">Look up this star at stellar-database.com</a> ]<br/>
      <br/>
      [ <a href="http://simbad.u-strasbg.fr/sim-id.pl?protocol=html&Ident={$selected_ra_deg}+{$selected_dec_simbad}&NbIdent=1&Radius=1&Radius.unit=arcmin&CooFrame=FK5&CooEpoch=2000&CooEqui=2000&output.max=all&o.catall=on&output.mesdisp=N&Bibyear1=1983&Bibyear2=2004&Frame1=FK5&Frame2=FK4&Frame3=G&Equi1=2000.0&Equi2=1950.0&Equi3=2000.0&Epoch1=2000.0&Epoch2=1950.0&Epoch3=2000.0" target="_blank">Look up this star in SIMBAD</a> ]<br/>
      <br/>
      [ <a href="http://www.fourmilab.ch/cgi-bin/uncgi/Yourtel?lon={$selected_star["ra"]}h&lat={$selected_dec_av}&ns={$selected_dec_ns}&date=0&fov=45�&coords=1&moonp=1&deep=1&deepm=7&consto=1&constn=1&constb=1&limag=6.5&starn=1&starnm=3.5&starb=1&starbm=4.5&imgsize=512&scheme=0" target="_blank">Plot a sky map centered on this star at fourmilab.ch</a> ]<br/>
      $memory_alpha
      </td>
     <tr>
   </table>
END;

} else {
   $selected_data = '<em>No star selected.</em>';
}


?>

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
<!-- existing header links stay as-is … -->

<!-- ───────── PAGE WRAPPER ───────── -->
<div class="page">

  <!-- ───── SIDEBAR (LEFT) ───── -->
  <div class="sidebar">

    <!-- SELECTED STAR INFO (unchanged) -->
    <div class="info">
      <?= $selected_data ?>
    </div>

    <!-- CENTER + ZOOM form (moved) -->
    <form method="GET" action="index.php">
      <fieldset class="menupanel">
        <legend>Center (<?= $unit ?>)</legend>
        X <input name="x_c" size="6" value="<?= $x_c ?>"><br>
        Y <input name="y_c" size="6" value="<?= $y_c ?>"><br>
        Z <input name="z_c" size="6" value="<?= $z_c ?>">
      </fieldset>

      <fieldset class="menupanel">
        <legend>Zoom (<?= $unit ?>)</legend>
        X <input name="xy_zoom" id="xy_zoom" size="4" value="<?= $xy_zoom ?>"
                 onkeyup="document.getElementById('y_zoom').value=this.value*2;"><br>
        Y <input id="y_zoom" size="4" value="<?= $xy_zoom*2 ?>" disabled><br>
        Z <input name="z_zoom" size="4" value="<?= $z_zoom ?>">
      </fieldset>
      <input type="hidden" name="select_star" value="<?= $select_star ?>">

      <button type="submit">Get map</button>
    </form>

   <!-- STAR SEARCH -->
   <fieldset class="menupanel">
   <legend>Search for star</legend>
   <!-- Proper-name drop-down -->
   <form method="GET" action="index.php">
      <input type="hidden" name="select_center" value="1">
      <select name="select_star">
         <option value="">(Proper names)</option>
         <?= $proper_options /* build once from DB on page load */ ?>
      </select>
      <button type="submit">Go</button>
   </form>
   <br>
   <!-- FICTIONAL NAME SEARCH -->
   <?php if ($fic_names > 0): ?>
      <form method="GET" action="index.php">
        <input type="hidden" name="select_center" value="1">
        <select name="select_star">
          <option value="">(Fictional names)</option>)
          <?= $fic_options ?>
        </select>
        <button type="submit">Go</button>
      </form>
   <?php endif; ?>
   <br>
   <!-- text field to search any catalog -->
   <form method="GET" action="search.php">
      <input type="text" name="q" size="20" placeholder="Alpha Cen / HD 48915">
      <button type="submit">Go</button>
   </form>
</fieldset>



  </div><!-- /sidebar -->


  <!-- ───── MAIN MAP (RIGHT) ───── -->
  <div class="main">
    <?= $map ?>
  </div>

</div><!-- /page -->


<!-- DATA TABLE -->
<div class="datatable">
<b>Stars in current map</b><br>
<small>Click column heading to sort</small><br>
<table id="star-table" cellspacing="2" cellpadding="5">
   <tr>
      <th>Name</th><th>Con</th><th>Spectral</th><th>Abs Mag</th>
      <th>Dist (<?= $unit ?>)</th><th>Δ Center</th><th>X</th><th>Y</th><th>Z</th>
   </tr>
   <?= $star_table ?>
</table>
<?= $star_count_displayed ?> of <?= $star_count ?> stars displayed.
</div>
<?php
prof_flag('FINISH');
if ($profiling) {
    echo '<div style="margin:1rem auto;max-width:95%;font-size:small;text-align:left">';
    prof_print();                 // echoes its own <br>s
    echo '</div>';
}
?>

<script>
document.addEventListener("DOMContentLoaded", function () {
  const table = document.getElementById("star-table");
  const headers = table.querySelectorAll("th");
  let currentSortCol = null;
  let sortDir = 1; // 1 = ascending, -1 = descending

  headers.forEach((header, i) => {
    header.style.cursor = "pointer";
    header.addEventListener("click", () => {
      const rows = Array.from(table.querySelectorAll("tr:nth-child(n+2)")); // skip header row

      // Determine sort direction
      if (currentSortCol === i) {
        sortDir = -sortDir; // toggle direction
      } else {
        sortDir = 1;
        if (currentSortCol !== null) {
          headers[currentSortCol].textContent = headers[currentSortCol].textContent.replace(/[\u2191\u2193]$/, '');
        }
        currentSortCol = i;
      }

      // Sort rows
      rows.sort((a, b) => {
        const cellA = a.children[i].textContent.trim();
        const cellB = b.children[i].textContent.trim();

        const numA = parseFloat(cellA.replace(/[^-.\d]/g, ''));
        const numB = parseFloat(cellB.replace(/[^-.\d]/g, ''));

        const valA = isNaN(numA) ? cellA.toLowerCase() : numA;
        const valB = isNaN(numB) ? cellB.toLowerCase() : numB;

        return valA > valB ? sortDir : valA < valB ? -sortDir : 0;
      });

      // Apply sorted rows
      rows.forEach(row => table.appendChild(row));

      // Add sort arrow to current header
      const arrow = sortDir === 1 ? ' ↑' : ' ↓';
      headers[i].textContent = headers[i].textContent.replace(/[\u2191\u2193]$/, '') + arrow;
    });
  });
});
</script>

</body>
</html>

<?php
function getDisplayName($row, $fic_names) {
   $fields = array("proper","bayer","flam","gj","hd","hip","gaia");
   if($fic_names == "1" && isset($row["name"]) && !empty($row["name"])) {
      array_unshift($fields, "name");
   }
   foreach($fields as $field) {
      if(isset($row[$field]) && !empty($row[$field])) {
         $name = $row[$field];
         if(in_array($field, array("bayer", "flam"))) {
            $name .= " " . $row["con"];
         } elseif(in_array($field, array("gj", "hd", "hip"))) {
            $name = strtoupper($field) . " " . $name;
         }
         return $name;
      }
   }
   return $row["spect"];
}
