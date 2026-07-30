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
require_once __DIR__ . '/ApiClient.php';
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

// Test API connection early to fail fast with friendly error
try {
    // Quick API health check by fetching worlds list (small response)
    ApiClient::instance()->queryWorlds();
} catch (RuntimeException $e) {
    // Answer 503, not 200. This page used to return success while telling the user the
    // service was down, so monitors, crawlers and caches all treated an outage as content.
    ErrorHandler::handleError(
        'Unable to connect to the star database. Please try again shortly.',
        $e
    );
}
