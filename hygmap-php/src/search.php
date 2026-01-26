<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiClient.php';

session_start();

$q = trim($_GET['q'] ?? '');
if ($q === '') { header('Location: index.php'); exit; }

try {
    $row = ApiClient::instance()->searchStar($q);
} catch (RuntimeException $e) {
    error_log("Search error: " . $e->getMessage());
    echo '<!DOCTYPE html><html><head><title>Search Error</title></head>';
    echo '<body style="font-family:sans-serif;margin:2rem;">';
    echo '<h3>⚠️ Search Error</h3>';
    echo '<p>Unable to search the star database at this time.</p>';
    echo '<p><a href="index.php">Back to map</a></p>';
    echo '</body></html>';
    exit;
}

if ($row) {
    $id = (int)$row['id'];
    $_SESSION['last_map'] = "index.php?select_star=$id&select_center=1";
    header("Location: index.php?select_star=$id&select_center=1",true,302);
} else {
    echo "<h3>No match for &ldquo;".htmlspecialchars($q)."&rdquo;</h3>";
    echo '<p><a href="index.php">Back to map</a></p>';
}
