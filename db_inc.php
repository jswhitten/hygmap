<?php

function open_db() {
    // Retrieve environment variables
    $db_user = getenv('DB_USERNAME');
    $db_pass = getenv('DB_PASSWORD');

    $link = mysqli_connect("localhost", "$db_user", "$db_pass")
        or die("Could not connect");

    mysqli_select_db($link, "whitten_starmap") or die("Could not select database");

    return $link;
}

function query_all($square = false, $max_line = 0, $order = "absmag") {

    global $x_c, $y_c, $z_c, $zoom, $z_zoom, $mag_limit, $link;

    $x_max = $x_c + $zoom + $max_line;
    $y_max = $y_c + ($square ? 1 : 2) * $zoom + $max_line;
    $z_max = $z_c + $z_zoom;

    $x_min = $x_c - $zoom - $max_line;
    $y_min = $y_c - ($square ? 1 : 2) * $zoom - $max_line;
    $z_min = $z_c - $z_zoom;

    #$query = "SELECT hyg.*, trek.name FROM hyg LEFT JOIN trek ON hyg.id = trek.hyg_id WHERE x > $x_min AND x < $x_max AND y > $y_min AND y < $y_max AND z > $z_min AND z < $z_max AND absmag < $mag_limit AND (gl IS NOT NULL OR bf IS NOT NULL OR iauname IS NOT NULL OR altname IS NOT NULL OR spect IS NOT NULL) ORDER BY $order";
    $query = "SELECT athyg.*, trek.name FROM athyg LEFT JOIN trek ON athyg.id = trek.id WHERE x > $x_min AND x < $x_max AND y > $y_min AND y < $y_max AND z > $z_min AND z < $z_max AND absmag < $mag_limit AND dist_src <> 'NONE' ORDER BY $order";
    $result = mysqli_query($link, $query) or die("Query $query failed: " . mysqli_error($link));
    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    mysqli_free_result($result);

    return $rows;
}

function query_star($id) {
    global $link;

    $query = "SELECT athyg.*, trek.Name FROM athyg LEFT JOIN trek ON athyg.id = trek.id WHERE athyg.id = $id";
    $result = mysqli_query($link, $query) or die("Query $query failed: " . mysqli_error($link));
    $row = mysqli_fetch_array($result, MYSQLI_ASSOC);
    mysqli_free_result($result);
 
    return $row;
}

function query_startrek() {
    global $link;

    $query = "SELECT * FROM trek ORDER BY Name";
    $result = mysqli_query($link, $query) or die("Query $query failed: " . mysqli_error($link));
    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    mysqli_free_result($result);

    return $rows;
}

?>
