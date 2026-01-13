<?php
declare(strict_types=1);

require 'bootstrap.php';
require_once 'MapRenderer.php';

// Variables defined in bootstrap.php
/** @var array $cfg */
/** @var array $vars */

// Create renderer and generate map
$renderer = new MapRenderer($cfg, $vars);
$renderer->render();
