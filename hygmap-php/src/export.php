<?php
declare(strict_types=1);

/**
 * CSV Export endpoint
 *
 * Exports stars in the current view as a downloadable CSV file.
 * Uses the same bounding box and magnitude parameters as the main view.
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Units.php';
require_once __DIR__ . '/StarFormatter.php';
require_once __DIR__ . '/MapGeometry.php';
require_once __DIR__ . '/ApiClient.php';

session_start();
Csrf::init();

// Load configuration
$cfg = Config::load();
$unit = $cfg['unit'];
$fic_names = (int)$cfg['fic_names'];
$m_limit = (float)$cfg['m_limit'];

// Get view parameters from query string
$x_c = filter_input(INPUT_GET, 'x_c', FILTER_VALIDATE_FLOAT) ?? 0.0;
$y_c = filter_input(INPUT_GET, 'y_c', FILTER_VALIDATE_FLOAT) ?? 0.0;
$z_c = filter_input(INPUT_GET, 'z_c', FILTER_VALIDATE_FLOAT) ?? 0.0;
$xy_zoom = filter_input(INPUT_GET, 'xy_zoom', FILTER_VALIDATE_FLOAT) ?? 25.0;
$z_zoom = filter_input(INPUT_GET, 'z_zoom', FILTER_VALIDATE_FLOAT) ?? 25.0;

// Build bounding box (same logic as index.php)
$bbox = MapGeometry::buildBoundingBox($x_c, $y_c, $z_c, $xy_zoom, $z_zoom, $unit, 'normal');

// Query stars
try {
    $rows = ApiClient::instance()->queryAll($bbox, $m_limit, $fic_names, 'absmag');
} catch (RuntimeException $e) {
    http_response_code(500);
    die('API error');
}

// Set headers for CSV download
$filename = sprintf('hygmap_stars_%s.csv', date('Y-m-d_His'));
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Open output stream
$output = fopen('php://output', 'w');

/**
 * Neutralize spreadsheet formula injection in a CSV cell.
 *
 * Star names come from third-party catalogs and the fictional-names table, so they are
 * untrusted input as far as this app is concerned. Excel, LibreOffice, and Google Sheets
 * evaluate any cell whose first character is =, +, -, or @ as a formula when the file is
 * opened, which turns an exported star list into code execution on the reader's machine.
 * Prefixing a single quote makes the cell render as literal text in all three.
 *
 * Leading whitespace is stripped first: " =cmd" is still treated as a formula by Excel.
 *
 * @param mixed $value Cell value
 * @return mixed Original value, or a quote-prefixed string if it could be read as a formula
 */
function csv_safe(mixed $value): mixed
{
    if (!is_string($value) || $value === '') {
        return $value;
    }

    $trimmed = ltrim($value, " \t\r\n");
    if ($trimmed !== '' && str_contains('=+-@', $trimmed[0])) {
        return "'" . $value;
    }

    return $value;
}

// Write CSV header
fputcsv($output, [
    'Name',
    'Constellation',
    'Spectral Type',
    'Absolute Magnitude',
    'Distance from Sol (' . $unit . ')',
    'X (' . $unit . ')',
    'Y (' . $unit . ')',
    'Z (' . $unit . ')',
    'Apparent Magnitude',
    'RA (hours)',
    'Dec (degrees)',
    'Proper Name',
    'Bayer Designation',
    'Flamsteed Number',
    'Henry Draper ID',
    'Hipparcos ID',
    'Gliese ID',
]);

// Write star data
foreach ($rows as $row) {
    // Get display name
    $display_name = StarFormatter::getDisplayName($row, $fic_names);

    // Convert coordinates to display units
    $x_ui = Units::fromParsecs((float)$row['x'], $unit);
    $y_ui = Units::fromParsecs((float)$row['y'], $unit);
    $z_ui = Units::fromParsecs((float)$row['z'], $unit);
    $dist_ui = Units::fromParsecs((float)$row['dist'], $unit);

    // Build Bayer designation
    $bayer = '';
    if (!empty($row['bayer']) && !empty($row['con'])) {
        $bayer = $row['bayer'] . ' ' . $row['con'];
    }

    // Build Flamsteed designation
    $flamsteed = '';
    if (!empty($row['flam']) && !empty($row['con'])) {
        $flamsteed = $row['flam'] . ' ' . $row['con'];
    }

    fputcsv($output, array_map('csv_safe', [
        $display_name,
        $row['con'] ?? '',
        $row['spect'] ?? '',
        $row['absmag'],
        round($dist_ui, 4),
        round($x_ui, 4),
        round($y_ui, 4),
        round($z_ui, 4),
        $row['mag'] ?? '',
        $row['ra'] ?? '',
        $row['dec'] ?? '',
        $row['proper'] ?? '',
        $bayer,
        $flamsteed,
        $row['hd'] ?? '',
        $row['hip'] ?? '',
        $row['gj'] ?? '',
    ]));
}

fclose($output);
