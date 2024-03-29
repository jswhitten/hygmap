<?php

function getVars() {
    global $_GET;

    $select_star = isset($_GET["select_star"])?$_GET["select_star"]:"0";
    $select_center = isset($_GET["select_center"])?"1":"0";
    $center_x = isset($_GET["x_c"])?$_GET["x_c"]:"0";
    $center_y = isset($_GET["y_c"])?$_GET["y_c"]:"0";
    $center_z = isset($_GET["z_c"])?$_GET["z_c"]:"0";
    $zoom = isset($_GET["xy_zoom"])?$_GET["xy_zoom"]:"25";
    $z_zoom = isset($_GET["z_zoom"])?$_GET["z_zoom"]:"20";
    $mag_limit = isset($_GET["m_limit"])?$_GET["m_limit"]:"20"; 
    $mag_limit_label = isset($_GET["m_limit_label"])?$_GET["m_limit_label"]:"8"; 
    if(!(is_numeric($mag_limit))) {
        $mag_limit = 20;
    }
    if(!(is_numeric($mag_limit_label))) {
        $mag_limit_label = 8;
    }
    $image_size = isset($_GET["image_size"])?$_GET["image_size"]:"600";
    $image_type = isset($_GET["image_type"])?$_GET["image_type"]:"normal";
    $max_line = isset($_GET["max_line"])?$_GET["max_line"]:"0";
    $trek_names = isset($_GET["trek_names"])?$_GET["trek_names"]:"0"; 

    return array($select_star, $select_center, $center_x, $center_y, $center_z, $zoom, $z_zoom, $mag_limit, $mag_limit_label, $image_size, $image_type, $max_line, $trek_names);
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