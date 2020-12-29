<?php
require '../userpass.php';

function open_db() {
    global $db_user, $db_pass;

    $link = mysql_connect("localhost", "$db_user", "$db_pass")
        or die("Could not connect");

    mysql_select_db("whitten_starmap") or die("Could not select database");

    return $link;
}

function query_all() {

    global $x_c, $y_c, $z_c, $zoom, $z_zoom, $mag_limit;

    $x_max = $x_c + $zoom;
    $y_max = $y_c + 2 * $zoom;
    $z_max = $z_c + $z_zoom;

    $x_min = $x_c - $zoom;
    $y_min = $y_c - 2 * $zoom;
    $z_min = $z_c - $z_zoom;

    /* Performing SQL query */
    $query = "SELECT tblHYG.*, tblGalactic.X, tblGalactic.Y, tblGalactic.Z, tblStarTrek.Name FROM tblGalactic INNER JOIN tblHYG ON tblGalactic.StarID = tblHYG.StarID LEFT JOIN tblStarTrek ON tblHYG.StarID = tblStarTrek.StarID WHERE X > $x_min AND X < $x_max AND Y > $y_min AND Y < $y_max AND Z > $z_min AND Z < $z_max AND AbsMag < $mag_limit AND (Gliese <> '' OR BayerFlam <> '' OR ProperName <> '' OR Spectrum <> '') ORDER BY Z";
    $result = mysql_query($query) or die("Query failed");

    return $result;
}

function query_star($id) {
    /* Performing SQL query */
    $query = "SELECT tblHYG.*, tblGalactic.X, tblGalactic.Y, tblGalactic.Z, tblStarTrek.Name FROM tblGalactic INNER JOIN tblHYG ON tblGalactic.StarID = tblHYG.StarID LEFT JOIN tblStarTrek ON tblHYG.StarID = tblStarTrek.StarID WHERE tblHYG.StarID = $id";
    $result = mysql_query($query) or die("Query failed");

    return $result;
}

?>
