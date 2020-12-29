<?php
include_once('fix_mysql.inc.php');
require('db_inc.php');
require('common_inc.php');

prof_flag("START");

// Extract variables from query string
list($select_star, $select_center, $center_x, $center_y, $center_z, $zoom, $z_zoom, $mag_limit, $image_size, $image_type, $max_line, $trek_names) = getVars();
$x_c = $center_x; 
$y_c = $center_y; 
$z_c = $center_z; 
$select_center_checked = "";

/* Connecting, selecting database */
$link = open_db();

if($select_star > 0) {
   // Find the center from the selected star
   prof_flag("Querying selected star");
   $select_result = query_star($select_star);
   $selected_star = mysql_fetch_array($select_result, MYSQL_ASSOC);
   if($select_center == "1") {
      $x_c = $selected_star["X"]; 
      $y_c = $selected_star["Y"]; 
      $z_c = $selected_star["Z"]; 
      $select_center_checked = "CHECKED";
   }
}
$trek_checked = "";
if($trek_names == "1") {
      $trek_checked = "CHECKED";
}

// Image type selection
$sel_normal = ($image_type == "normal")?"SELECTED":"";
$sel_3d = ($image_type == "3d")?"SELECTED":"";
$sel_printable = ($image_type == "printable")?"SELECTED":"";

if($select_star > 0) {
   $selected_ra_deg = $selected_star["RA"] * 360 / 24;
   $selected_dec_av = abs($selected_star["Declination"]);
   if($selected_star["Declination"] >= 0) { 
      $selected_dec_ns = 'North';
      $selected_dec_simbad = '%2B'.$selected_dec_av;
   } else {
      $selected_dec_ns = 'South';
      $selected_dec_simbad = $selected_star["Declination"];
   }
   $select_star_name = getDisplayName($selected_star, 0);
   $selected_display_name = $select_star_name;
   if($trek_names && $selected_star["Name"] != "" && $selected_display_name != $selected_star["Name"]) {
      $selected_display_name .= " (" . $selected_star["Name"] . ")";
   }
}
prof_flag("Querying Star Trek stars");
$alltrek_query = "SELECT * FROM tblStarTrek AS T, tblGalactic AS G WHERE G.StarID = T.StarID ORDER BY T.Name";
$alltrek_result = mysql_query($alltrek_query) or die("Query failed");
$trek_options = "";
while ($row = mysql_fetch_array($alltrek_result, MYSQL_ASSOC)) {
      $trek_options .= "<option value=\"$row[StarID]\">$row[Name]\n";
}

if($image_type == "3d") {
      $image_size /= 2;
      $map = "<td><img src=\"map.php?x_c=$x_c&y_c=$y_c&z_c=$z_c&xy_zoom=$zoom&z_zoom=$z_zoom&m_limit=$mag_limit&select_star=$select_star&image_size=$image_size&image_type=left&max_line=$max_line&trek_names=$trek_names\" width=" . $image_size*2 . " height=$image_size></td>";
      $map .= "<td><img src=\"map.php?x_c=$x_c&y_c=$y_c&z_c=$z_c&xy_zoom=$zoom&z_zoom=$z_zoom&m_limit=$mag_limit&select_star=$select_star&image_size=$image_size&image_type=right&max_line=$max_line&trek_names=$trek_names\" width=" . $image_size*2 . " height=$image_size></td>";
} else {
      $map = "<td><img src=\"map.php?x_c=$x_c&y_c=$y_c&z_c=$z_c&xy_zoom=$zoom&z_zoom=$z_zoom&m_limit=$mag_limit&select_star=$select_star&image_size=$image_size&image_type=$image_type&max_line=$max_line&trek_names=$trek_names\" width=" . $image_size*2 . " height=$image_size></td>";
}

/* Performing SQL query */
prof_flag("Querying all stars in map");
$result = query_all();
prof_flag("Query complete");
$star_count = 0;
$star_table = "";
while ($row = mysql_fetch_array($result, MYSQL_ASSOC)) {
   $star_count++;
   $display_name = getDisplayName($row, 0);
   $star_table .= <<<END
         <tr>
            <td><a href="?select_star={$row['StarID']}&select_center=1">{$row["StarID"]}</a></td><td>$display_name</td><td>{$row["Distance"]}</td><td>{$row["Spectrum"]}</td><td>{$row["AbsMag"]}</td>
         </tr>\n
END;
}

if($select_star > 0) {
   $distance_ly = $selected_star["Distance"] * 3.26;
   $selected_data = <<<END
   <h3>$selected_display_name</h3>
   <table width=100% cellpadding=1 cellspacing=1>
      <tr>
         <td width=50%>Absolute magnitude</td><td>{$selected_star["AbsMag"]}</td>
      </tr><tr>
         <td>Spectral type</td><td>{$selected_star["Spectrum"]}</td>
      </tr><tr>
         <td>Distance from Sol</td><td>{$distance_ly} light years</td>
      </tr><tr>
         <td>Galactic coordinates</td><td>{$selected_star["X"]}, {$selected_star["Y"]}, {$selected_star["Z"]}</td>
      </tr><tr>
         <td>Apparent magnitude</td><td>{$selected_star["Mag"]}</td> 
      </tr><tr>
         <td>Sky coordinates</td><td>{$selected_star["RA"]} h, {$selected_star["Declination"]}°</td>
     </tr>
   </table>
   [ <a href="http://www.stellar-database.com/Scripts/find_neighbors.exe?ly=2&X={$selected_star["X"]}&Y={$selected_star["Y"]}&Z={$selected_star["Z"]}" target="_blank">Look up this star at stellar-database.com</a> ]<br>
   [ <a href="http://simbad.u-strasbg.fr/sim-id.pl?protocol=html&Ident={$selected_ra_deg}+{$selected_dec_simbad}&NbIdent=1&Radius=1&Radius.unit=arcmin&CooFrame=FK5&CooEpoch=2000&CooEqui=2000&output.max=all&o.catall=on&output.mesdisp=N&Bibyear1=1983&Bibyear2=2004&Frame1=FK5&Frame2=FK4&Frame3=G&Equi1=2000.0&Equi2=1950.0&Equi3=2000.0&Epoch1=2000.0&Epoch2=1950.0&Epoch3=2000.0" target="_blank">Look up this star in SIMBAD</a> ]<br>
   [ <a href="http://www.fourmilab.ch/cgi-bin/uncgi/Yourtel?lon={$selected_star["RA"]}h&lat={$selected_dec_av}&ns={$selected_dec_ns}&date=0&fov=45�&coords=1&moonp=1&deep=1&deepm=7&consto=1&constn=1&constb=1&limag=6.5&starn=1&starnm=3.5&starb=1&starbm=4.5&imgsize=512&scheme=0" target="_blank">Plot a sky map centered on this star at fourmilab.ch</a> ]<br>
END;
} else {
   $selected_data = '<h3>No star selected</h3><br>';
}

?>

<html>
<head>
   <title>HYGMap</title>
</head>
<body style="font-family: sans-serif;">
<a href="about.html">About HYGMap</a>
   <table width=100% cellpadding="5" align=center border=0 bgcolor="#DDDDCC">
      <!-- TOP MENU -->
      <form method="GET" action="index.php">
      <tr>
         <td colspan=3 bgcolor="#DDDDCC">
            <table width=100% align=center border=1>
               <tr><td align="center">
                  <b>Center of map</b><br>
                  <table>
                     <tr><td><b>X</b></td><td><input type="text" name="x_c" value="<?=$x_c?>"></td></tr>
                     <tr><td><b>Y</b></td><td><input type="text" name="y_c" value="<?=$y_c?>"></td></tr>         
                     <tr><td><b>Z</b></td><td><input type="text" name="z_c" value="<?=$z_c?>"></td></tr>
                  </table>
                  <input type="submit" value="Get Map">
               </td><td align="center">
                  <b>Zoom</b> (distance from center to edge in light years)<br>
                  <table>
                     <tr><td><b>X-Y</b></td><td><input type="text" name="xy_zoom" value="<?=$zoom?>"></td></tr> 
                     <tr><td><b>Z</b></td><td><input type="text" name="z_zoom" value="<?=$z_zoom?>"></td></tr>
                  </table>
               </td><td align="center">
                  <b>Map Options</b><br>
                  <table>
                     <tr><td><b>Absolute Magnitude Limit</b></td><td><INPUT TYPE="text" name="m_limit" value="<?=$mag_limit?>" maxlength="4" size="4"></td></tr> 
                     <tr><td><b>Image Type</b></td>
                     <td>
                        <SELECT NAME="image_type">
                           <OPTION VALUE="normal" <?=$sel_normal?> >Normal
                           <OPTION VALUE="3d" <?=$sel_3d?> >3-D
                           <OPTION VALUE="printable" <?=$sel_printable?> >Black & white
                        </SELECT>
                     </td></tr>
                     <tr><td><b>Image Size (pixels)</b></td><td><INPUT TYPE="text" NAME="image_size" VALUE="<?=$image_size?>"></td></tr>
                     <tr><td><b>Connecting Lines (light years)</b></td><td><INPUT TYPE="text" NAME="max_line" VALUE="<?=$max_line?>"></td></tr>
                     <tr><td><b>Show fictional names</b></td><td><INPUT TYPE="checkbox" NAME="trek_names" VALUE="1" <?=$trek_checked?> ></td></tr>
                  </table>
               </td><td align="center">
               </td></tr>
            </table>
         </td>
      </tr>
      </form>
      <tr>
         <!-- SELECTED STAR DATA -->
         <td width=30% valign="top" bgcolor="DDDDFF">
            <?= $selected_data ?>
         </td>
         <!-- MAP -->
         <td width=100% align=center bgcolor="#ffffff">
            <table bordercolor=#aaaa99 bordercolorlight=#ccccaa bordercolordark=#666633 bgcolor=#aaaa99 border=5>
               <tr>
                  <?= $map ?>
               </tr>
            </table>
         </td>
      </tr>
      <tr>
         <td align="center" valign="top" bgcolor="#ffffff">
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
         </td>
         <!-- TABLE OF STARS IN MAP -->
         <td bgcolor="#ffffff">
            <table>
               <tr>
                  <th>StarID</th><th>Name</th><th>Distance</th><th>Spectral Type</th><th>Absolute Magnitude</th>
               </tr>
               <?=$star_table?>
            </table>
            <?=$star_count?> stars displayed.
         </td>
      </tr>
   </table>
</body>
</html>
<?php
      prof_flag("FINISH");
      prof_print();
?>