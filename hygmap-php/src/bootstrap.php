<?php
declare(strict_types=1);

/**
 * Common bootstrap logic shared by index.php and map.php
 * Loads configuration, extracts settings, and parses query parameters
 */

// Core classes
require_once __DIR__ . '/Units.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Request.php';
require_once __DIR__ . '/StarFormatter.php';
require_once __DIR__ . '/ErrorHandler.php';
require_once __DIR__ . '/RenderingConstants.php';
require_once __DIR__ . '/MapGeometry.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Profiler.php';

// Initialize session and CSRF protection
session_start();
Csrf::init();

// Error reporting configuration
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Initialize profiler
$profiler = new Profiler();

// Load configuration from session
$cfg = Config::load();

// Extract variables from query string
$vars = Request::getMapParams();

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
