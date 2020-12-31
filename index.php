<?php
require('db_inc.php');
require('common_inc.php');

$profiling = false;
prof_flag("START");

// Extract variables from query string
list($select_star, $select_center, $center_x, $center_y, $center_z, $zoom, $z_zoom, $mag_limit, $image_size, $image_type, $max_line, $trek_names) = getVars();
$x_c = $center_x; 
$y_c = $center_y; 
$z_c = $center_z; 
$select_center_checked = "";

// Connecting, selecting database
$link = open_db();

if($select_star > 0) {
   // Find the center from the selected star
   prof_flag("Querying selected star");
   $selected_star = query_star($select_star);
   if($select_center == "1") {
      $x_c = $selected_star["x"]; 
      $y_c = $selected_star["y"]; 
      $z_c = $selected_star["z"]; 
      $select_center_checked = "CHECKED";
   }
}

// Image type selection
$sel_normal = ($image_type == "normal")?"SELECTED":"";
$sel_3d = ($image_type == "3d")?"SELECTED":"";
$sel_printable = ($image_type == "printable")?"SELECTED":"";

// Selected star
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
   if($trek_names && $selected_star["Name"] != "" && $selected_display_name != $selected_star["Name"]) {
      $selected_display_name .= " (" . $selected_star["Name"] . ")";
   }
}

// Retrieve list of stars with fictional names
prof_flag("Querying Star Trek stars");
$trek_checked = ($trek_names == "1") ? "CHECKED" : "";
$trek_rows = query_startrek();
$trek_options = "";
foreach ($trek_rows as $trek_row) {
      $trek_options .= "<option value=\"$trek_row[hyg_id]\">$trek_row[name]\n";
}

// Generate html for map
if($image_type == "3d") {
      $map = "<img src=\"map.php?x_c=$x_c&y_c=$y_c&z_c=$z_c&xy_zoom=$zoom&z_zoom=$z_zoom&m_limit=$mag_limit&select_star=$select_star&image_size=$image_size&image_type=left&max_line=$max_line&trek_names=$trek_names\" width=$image_size height=$image_size>&nbsp;";
      $map .= "<img src=\"map.php?x_c=$x_c&y_c=$y_c&z_c=$z_c&xy_zoom=$zoom&z_zoom=$z_zoom&m_limit=$mag_limit&select_star=$select_star&image_size=$image_size&image_type=right&max_line=$max_line&trek_names=$trek_names\" width=$image_size height=$image_size>";
} else {
      $map = "<img src=\"map.php?x_c=$x_c&y_c=$y_c&z_c=$z_c&xy_zoom=$zoom&z_zoom=$z_zoom&m_limit=$mag_limit&select_star=$select_star&image_size=$image_size&image_type=$image_type&max_line=$max_line&trek_names=$trek_names\" width=" . $image_size*2 . " height=$image_size>";
}

// Get data for star table
prof_flag("Querying all stars in map");
$rows = query_all();
prof_flag("Query complete");
$star_count = 0;
$star_table = "";
foreach ($rows as $row) {
   $star_count++;
   $display_name = getDisplayName($row, 0);
   $distance_from_center = number_format(sqrt(pow($row["x"] - $x_c, 2) + pow($row["y"] - $y_c, 2) + pow($row["z"] - $z_c, 2)),2);
   $star_table .= <<<END
         <tr>
            <td><a href="?select_star={$row['id']}&select_center=1">$display_name</a></td><td>{$row["con"]}</td><td>{$row["spect"]}</td><td>{$row["absmag"]}</td><td>{$row["dist"]}</td><td>$distance_from_center</td>
         </tr>\n
END;
}

// Build details for selected star
$selected_data = '';
if($select_star > 0) {
   $distance_ly = $selected_star["dist"];
   $selected_data = <<<END
   <h3>$selected_display_name</h3>
   <table width=100% cellpadding=1 cellspacing=1>
      <tr>
         <td width=50%>Absolute magnitude</td><td>{$selected_star["absmag"]}</td>
      </tr><tr>
         <td>Spectral type</td><td>{$selected_star["spect"]}</td>
      </tr><tr>
         <td>Distance from Sol</td><td>{$distance_ly} light years</td>
      </tr><tr>
         <td>Galactic coordinates</td><td>{$selected_star["x"]}, {$selected_star["y"]}, {$selected_star["z"]}</td>
      </tr><tr>
         <td>Apparent magnitude</td><td>{$selected_star["mag"]}</td> 
      </tr><tr>
         <td>Sky coordinates</td><td>{$selected_star["ra"]} h, {$selected_star["dec"]}°</td>
     </tr>
     <tr>
      <td colspan="2">
      <br/>
      [ <a href="http://www.stellar-database.com/Scripts/find_neighbors.exe?ly=2&X={$selected_star["x"]}&Y={$selected_star["y"]}&Z={$selected_star["z"]}" target="_blank">Look up this star at stellar-database.com</a> ]<br/>
      <br/>
      [ <a href="http://simbad.u-strasbg.fr/sim-id.pl?protocol=html&Ident={$selected_ra_deg}+{$selected_dec_simbad}&NbIdent=1&Radius=1&Radius.unit=arcmin&CooFrame=FK5&CooEpoch=2000&CooEqui=2000&output.max=all&o.catall=on&output.mesdisp=N&Bibyear1=1983&Bibyear2=2004&Frame1=FK5&Frame2=FK4&Frame3=G&Equi1=2000.0&Equi2=1950.0&Equi3=2000.0&Epoch1=2000.0&Epoch2=1950.0&Epoch3=2000.0" target="_blank">Look up this star in SIMBAD</a> ]<br/>
      <br/>
      [ <a href="http://www.fourmilab.ch/cgi-bin/uncgi/Yourtel?lon={$selected_star["ra"]}h&lat={$selected_dec_av}&ns={$selected_dec_ns}&date=0&fov=45�&coords=1&moonp=1&deep=1&deepm=7&consto=1&constn=1&constb=1&limag=6.5&starn=1&starnm=3.5&starb=1&starbm=4.5&imgsize=512&scheme=0" target="_blank">Plot a sky map centered on this star at fourmilab.ch</a> ]<br/>
      </td>
     <tr>
   </table>
END;

}

// Close database connection
mysqli_close($link);

?>

<html>
<head>
   <title>HYGMap</title>
   <link href="css/styles.css" rel="stylesheet">
</head>
<body>
<span class="toplink"><a href="index.php">HYGMap</a></span>
<span class="toplink"><a href="about.html">About HYGMap</a></span>
<span class="toplink"><a href="treknotes.html">Star Trek notes</a></span>
<!-- TOP MENU -->
<form method="GET" action="index.php">
<div class="topmenu">
   <!-- CENTER COORDINATES -->
   <span class="menupanel">
      <h4>Center</h4>
      <table>
         <tr><td>X</td><td><input type="text" name="x_c" size="6" value="<?=$x_c?>"></td></tr>
         <tr><td>Y</td><td><input type="text" name="y_c" size="6" value="<?=$y_c?>"></td></tr>         
         <tr><td>Z</td><td><input type="text" name="z_c" size="6" value="<?=$z_c?>"></td></tr>
      </table>
   </span>
   <!-- ZOOM -->
   <span class="menupanel">
      <h4>Zoom</h4>
      <table>
         <tr><td>X</td><td><input type="text" name="xy_zoom" id="xy_zoom" size="4" value="<?=$zoom?>" onKeyUp="document.getElementById('y_zoom').value = this.value * 2;"> ly</td></tr> 
         <tr><td>Y</td><td><input type="text" name="y_zoom" id="y_zoom" size="4" value="<?=$zoom*2?>" DISABLED> ly</td></tr> 
         <tr><td>Z</td><td><input type="text" name="z_zoom" size="4" value="<?=$z_zoom?>"> ly</td></tr>
      </table>
   </span>
   <!-- MAP OPTIONS -->
   <span class="menupanel">
      <h4>Map Options</h4>
      <table>
         <tr><td>Image Type/Size</td>
         <td>
            <SELECT NAME="image_type" id="image_type" onClick="if(this.value === '3d') { document.getElementById('image_size').value = '300'; } else { document.getElementById('image_size').value = '600'; }">
               <OPTION VALUE="normal" <?=$sel_normal?> >Normal
               <OPTION VALUE="3d" <?=$sel_3d?> >3-D
               <OPTION VALUE="printable" <?=$sel_printable?> >Black & white
            </SELECT>
            <INPUT TYPE="text" NAME="image_size" id="image_size" size="4" VALUE="<?=$image_size?>"> pixels
         </td></tr>
         <tr><td>Connecting Lines</td><td><INPUT TYPE="text" NAME="max_line" size="4" VALUE="<?=$max_line?>"> ly</td></tr>
         <tr><td>Show fictional names</td><td><INPUT TYPE="checkbox" NAME="trek_names" VALUE="1" <?=$trek_checked?> ></td></tr>
      </table>
   </span>
   <!-- MAP OPTIONS -->
   <span class="menupanel">
      <h4>Filters</h4>
      <table>
         <tr><td>Absolute Magnitude Limit</td><td><INPUT TYPE="text" name="m_limit" size="4" value="<?=$mag_limit?>" maxlength="4" size="4"></td></tr> 
      </table>
   </span>
   <span id="submit">
      <input type="submit" value="Get Map"/>
   </span>
</div>
</form>
<div class="mapcontainer">
   <!-- SELECTED STAR DATA -->
   <span class="info">
      <?= $selected_data ?>
   </span>
   <!-- MAP -->
   <span class="map">
      <?= $map ?>
   </span>
   <br/>
</div>
<div>
   <!-- JUMP TO STAR BY FICTIONAL NAME -->
   <span class="info">
      <b>Jump to star by fictional name</b><br>
      <form method="GET" action="index.php">
         <INPUT TYPE="hidden" NAME="trek_names" VALUE="1">
         <INPUT TYPE="hidden" NAME="select_center" VALUE="1">
         <SELECT NAME="select_star">
            <option value="">(None)
            <?=$trek_options?>
         </SELECT>
         <input type="submit" value="Go">
      </form>
   </span>
   <!-- TABLE OF STARS IN MAP -->
   <span class="datatable">
      <table cellspacing="2" cellpadding="5">
         <tr>
            <th>Name</th><th>Constellation</th><th>Spectral Type</th><th>Absolute Magnitude</th><th>Distance from Sun (ly)</th><th>Distance from map center (ly)</th>
         </tr>
         <?=$star_table?>
      </table>
      <?=$star_count?> stars displayed.
   </span>
</div>
</body>
</html>
<?php
prof_flag("FINISH");
if($profiling) {
   prof_print();
}

function getDisplayName($row, $trek_names) {
   $fields = array("proper","bayer","flam","gl","hd","hip");
   if($trek_names == "1") {
      array_unshift($fields, "Name");
   }
   foreach($fields as $field) {
      if(isset($row[$field]) && !empty($row[$field])) {
         $name = $row[$field];
         if(in_array($field, array("bayer", "flam"))) {
            $name .= " " . $row["con"];
         } elseif(is_numeric($name)) {
            $name = $field . $name;
         }
         return $name;
      }
   }
   return $row["Spectrum"];
}
