<?php
declare(strict_types=1);

/**
 * Common bootstrap logic shared by index.php and map.php
 * Loads configuration, extracts settings, and parses query parameters
 */

require 'common_inc.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Profiler.php';
require_once 'config.inc.php';

// Initialize profiler
$profiler = new Profiler();

// Load configuration from session
$cfg = cfg_load();

// Extract variables from query string
$vars = getVars();

// Test database connection early to fail fast with friendly error
try {
    Database::connection();
} catch (DatabaseConnectionException $e) {
    echo '<!DOCTYPE html><html><head><title>Database Error</title></head>';
    echo '<body style="font-family:sans-serif;margin:2rem;">';
    echo '<h1>⚠️ Database Connection Error</h1>';
    echo '<p>Unable to connect to the star database. Please check that the database service is running.</p>';
    echo '<p>Technical details have been logged.</p>';
    echo '</body></html>';
    exit(1);
}
