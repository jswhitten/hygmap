<?php
    include_once 'fix_mysql.inc.php';
    require 'map_inc.php';
    require 'db_inc.php';
    require 'common_inc.php';

    // Extract variables from query string
    list($select_star, $select_center, $x_c, $y_c, $z_c, $zoom, $z_zoom, $mag_limit, $image_size, $image_type, $max_line, $trek_names) = getVars();

    // Create image
    while (@ob_end_clean());
    Header("Content-type: image/jpeg");

    $image = ImageCreate($image_size*2,$image_size);


    // Allocate colors
    list($white, $grey, $darkgrey, $green, $red, $orange, $lightyellow, $yellow, $lightblue, $blue, $darkblue, $black) = allocateColors();

    // Fill background
    if($image_type == "printable") {
        ImageFill($image,50,50,$white);
    } else {
        ImageFill($image,50,50,$black);
    }

    // Connect, select, query database for stars within given coordinates
    $link = open_db();

    $result = query_all();
    // XXX: WTF
    $result_i = query_all();
    $result_j = query_all();


    // Draw grid
    drawGrid();

    // XXX: Plot connecting lines
    if($max_line > 0) {
    while ($row_i = mysql_fetch_array($result_i, MYSQL_ASSOC)) {
        $x_i = $row_i["X"];
        $y_i = $row_i["Y"];
        $z_i = $row_i["Z"];

        mysql_data_seek($result_j, 0);
        while ($row_j = mysql_fetch_array($result_j, MYSQL_ASSOC)) {
            $x_j = $row_j["X"];
            $y_j = $row_j["Y"];
            $z_j = $row_j["Z"];

            $x_diff = $x_i - $x_j;
            $y_diff = $y_i - $y_j;
            $z_diff = $z_i - $z_j;
            $dist_sums = pow($x_diff,2) + pow($y_diff,2) + pow($z_diff,2);
            $dist_sqrt = sqrt($dist_sums);
            if(($row_i["AbsMag"] < $mag_limit) && ($row_j["AbsMag"] < $mag_limit)) {
                if($dist_sqrt < $max_line/2) {
                    list ($screen_x_i, $screen_y_i) = screenCoords($x_i, $y_i, $z_i);
                    list ($screen_x_j, $screen_y_j) = screenCoords($x_j, $y_j, $z_j);
                    ImageLine($image,$screen_x_i,$screen_y_i,$screen_x_j,$screen_y_j,$lightblue);
                } elseif($dist_sqrt < 0.75 * $max_line) {
                    list ($screen_x_i, $screen_y_i) = screenCoords($x_i, $y_i, $z_i);
                    list ($screen_x_j, $screen_y_j) = screenCoords($x_j, $y_j, $z_j);
                    ImageLine($image,$screen_x_i,$screen_y_i,$screen_x_j,$screen_y_j,$blue);
                } elseif($dist_sqrt < $max_line) {
                    list ($screen_x_i, $screen_y_i) = screenCoords($x_i, $y_i, $z_i);
                    list ($screen_x_j, $screen_y_j) = screenCoords($x_j, $y_j, $z_j);
                    ImageLine($image,$screen_x_i,$screen_y_i,$screen_x_j,$screen_y_j,$darkblue);
                }
            }
        }

    }
    }

    // Plot each star
    while ($row = mysql_fetch_array($result, MYSQL_ASSOC)) {

        $id = $row["StarID"];
        $x = $row["X"];
        $y = $row["Y"];
        $z = $row["Z"];
        $mag = $row["AbsMag"];

        if($mag < $mag_limit) {

            list ($name, $labelcolor) = getLabel($trek_names);

            list ($screen_x, $screen_y) = screenCoords($x, $y, $z);

            $spec = getSpecClass($row["Spectrum"]); 

            $starcolor = specColor();

            list ($size, $labelsize) = starSize();

            // plot star
            plotStar($select_star, $id);

            // label
            labelStar($name, $labelsize, $labelcolor);
        }
    }
    // draw it
    ImageJPEG($image);
    ImageDestroy($image);

    // Free resultset
    mysql_free_result($result);

    // Close database connection */
    mysql_close($link);

?>
