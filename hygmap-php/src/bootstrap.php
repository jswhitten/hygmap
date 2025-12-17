<?php
declare(strict_types=1);

/**
 * Common bootstrap logic shared by index.php and map.php
 * Loads configuration, extracts settings, and parses query parameters
 */

require 'common_inc.php';
require_once __DIR__ . '/Database.php';
require_once 'config.inc.php';

// Initialize profiling arrays
$prof_timing = [];
$prof_names = [];

// Load configuration from session
$cfg = cfg_load();

// Extract variables from query string
$vars = getVars();
