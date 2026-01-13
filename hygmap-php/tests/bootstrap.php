<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap file
 *
 * Sets up autoloading and any global test configuration
 */

// Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Define test environment
define('HYGMAP_TEST_ENV', true);

// Determine source directory - handles both local dev and Docker container
// Local: tests/../src/  Docker: tests/../ (files in /var/www/html/)
if (is_dir(__DIR__ . '/../src')) {
    define('HYGMAP_SRC_DIR', __DIR__ . '/../src');
} else {
    define('HYGMAP_SRC_DIR', __DIR__ . '/..');
}

// Mock session for tests that need it
if (session_status() === PHP_SESSION_NONE) {
    // Use a mock session handler for testing
    $_SESSION = [];
}
