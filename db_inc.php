<?php
require '../userpass.php';

function open_db() {
    global $db_user, $db_pass;

    $link = mysqli_connect("localhost", "$db_user", "$db_pass")
        or die("Could not connect");

    mysqli_select_db($link, "whitten_starmap") or die("Could not select database");

    return $link;
}

function query_all() {

    global $x_c, $y_c, $z_c, $zoom, $z_zoom, $mag_limit, $link;

    $x_max = $x_c + $zoom;
    $y_max = $y_c + 2 * $zoom;
    $z_max = $z_c + $z_zoom;

    $x_min = $x_c - $zoom;
    $y_min = $y_c - 2 * $zoom;
    $z_min = $z_c - $z_zoom;

    $query = "SELECT tblHYG.*, tblGalactic.X, tblGalactic.Y, tblGalactic.Z, tblStarTrek.Name FROM tblGalactic INNER JOIN tblHYG ON tblGalactic.StarID = tblHYG.StarID LEFT JOIN tblStarTrek ON tblHYG.StarID = tblStarTrek.StarID WHERE X > $x_min AND X < $x_max AND Y > $y_min AND Y < $y_max AND Z > $z_min AND Z < $z_max AND AbsMag < $mag_limit AND (Gliese <> '' OR BayerFlam <> '' OR ProperName <> '' OR Spectrum <> '') ORDER BY Z";
    $result = mysqli_query($link, $query) or die("Query failed");

    return $result;
}

function query_star($id) {
    global $link;

    $query = "SELECT tblHYG.*, tblGalactic.X, tblGalactic.Y, tblGalactic.Z, tblStarTrek.Name FROM tblGalactic INNER JOIN tblHYG ON tblGalactic.StarID = tblHYG.StarID LEFT JOIN tblStarTrek ON tblHYG.StarID = tblStarTrek.StarID WHERE tblHYG.StarID = $id";
    $result = mysqli_query($link, $query) or die("Query failed");

    return $result;
}

function query_startrek() {
    global $link;

    $query = "SELECT * FROM tblStarTrek ORDER BY Name";
    $result = mysqli_query($link, $query) or die("Query failed");

    return $result;
}

?>
