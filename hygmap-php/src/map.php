<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require 'bootstrap.php';
require_once 'MapRenderer.php';

// Create renderer and generate map
$renderer = new MapRenderer($cfg, $vars);
$renderer->render();
