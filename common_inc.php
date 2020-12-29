<?php

function getVars() {

    global $_GET;

    $select_star = isset($_GET["select_star"])?$_GET["select_star"]:"0";
    $select_center = isset($_GET["select_center"])?"1":"0";
    $center_x = isset($_GET["x_c"])?$_GET["x_c"]:"0";
    $center_y = isset($_GET["y_c"])?$_GET["y_c"]:"0";
    $center_z = isset($_GET["z_c"])?$_GET["z_c"]:"0";
    $zoom = isset($_GET["xy_zoom"])?$_GET["xy_zoom"]:"20";
    $z_zoom = isset($_GET["z_zoom"])?$_GET["z_zoom"]:"20";
    $mag_limit = isset($_GET["m_limit"])?$_GET["m_limit"]:"20"; 
    if(!(is_numeric($mag_limit))) {
       $mag_limit = 20;
    }
    $image_size = isset($_GET["image_size"])?$_GET["image_size"]:"600";
    $image_type = isset($_GET["image_type"])?$_GET["image_type"]:"normal";
    $max_line = isset($_GET["max_line"])?$_GET["max_line"]:"0";
    $trek_names = isset($_GET["trek_names"])?$_GET["trek_names"]:"0"; 

    return array($select_star, $select_center, $center_x, $center_y, $center_z, $zoom, $z_zoom, $mag_limit, $image_size, $image_type, $max_line, $trek_names);
}

function getLabel($trek_names) {
    global $row, $yellow, $white, $black, $grey, $darkgrey, $image_type;

    if($trek_names == "1" && isset($row["Name"]) && $row["Name"] != "") {
        $name = $row["Name"];
        $labelcolor = $yellow;
        $printcolor = $black;
    } elseif(isset($row["ProperName"]) && $row["ProperName"] != "") {
        $name = $row["ProperName"];
        $labelcolor = $white;
        $printcolor = $black;
    } elseif($row["BayerFlam"] != "" && $row["BayerFlam"] != "-") {
        $name = ltrim($row["BayerFlam"]);
        $labelcolor = $grey;
        $printcolor = $darkgrey;
    } elseif($row["Gliese"] != "") {
        $name = $row["Gliese"];
        $labelcolor = $grey;
        $printcolor = $darkgrey;
    } elseif($row["HD"] > 0) {
        $name = "HD".$row["HD"];
        $labelcolor = $grey;
        $printcolor = $darkgrey;
    } else {
        $name = $row["Spectrum"];
        $labelcolor = $darkgrey;
        $printcolor = $darkgrey;
    }

    if($image_type == "printable") {
        $labelcolor = $printcolor;
    }

    return array($name, $labelcolor);
}

function getDisplayName($row, $trek_names) {
    if($trek_names == "1" && isset($row["Name"]) && $row["Name"] != "") {
        $name = $row["Name"];
    } elseif(isset($row["ProperName"]) && $row["ProperName"] != "") {
        $name = $row["ProperName"];
    } elseif($row["BayerFlam"] != "" && $row["BayerFlam"] != "-") {
        $name = ltrim($row["BayerFlam"]);
    } elseif($row["Gliese"] != "") {
        $name = $row["Gliese"];
    } elseif($row["HD"] > 0) {
        $name = "HD".$row["HD"];
    } else {
        $name = $row["Spectrum"];
    }

    return $name;
}


// Call this at each point of interest, passing a descriptive string
function prof_flag($str)
{
    global $prof_timing, $prof_names;
    $prof_timing[] = microtime(true);
    $prof_names[] = $str;
}

// Call this when you're done and want to see the results
function prof_print()
{
    global $prof_timing, $prof_names;
    $size = count($prof_timing);
    for($i=0;$i<$size - 1; $i++)
    {
        echo "<b>{$prof_names[$i]}</b><br>";
        echo sprintf("&nbsp;&nbsp;&nbsp;%f<br>", $prof_timing[$i+1]-$prof_timing[$i]);
    }
    echo "<b>{$prof_names[$size-1]}</b><br>";
    echo "<b>Total time:</b> " . $prof_timing[$size-1]-$prof_timing[0];
}

?>
