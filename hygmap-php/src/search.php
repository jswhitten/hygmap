<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiClient.php';
require_once __DIR__ . '/Config.php';

session_start();

$q = trim($_GET['q'] ?? '');
if ($q === '') { header('Location: /'); exit; }

// The configured universe scopes the search the same way it scopes the map, so a
// fictional name is findable exactly when it is the name being displayed. Config::load()
// needs the session, which is why it is read after session_start().
$cfg = Config::load();
$ficNames = (int)$cfg['fic_names'];

try {
    $row = ApiClient::instance()->searchStar($q, $ficNames);
} catch (RuntimeException $e) {
    error_log("Search error: " . $e->getMessage());
    echo '<!DOCTYPE html><html><head><title>Search Error</title></head>';
    echo '<body style="font-family:sans-serif;margin:2rem;">';
    echo '<h3>⚠️ Search Error</h3>';
    echo '<p>Unable to search the star database at this time.</p>';
    echo '<p><a href="/">Back to map</a></p>';
    echo '</body></html>';
    exit;
}

if ($row) {
    $id = (int)$row['id'];
    $_SESSION['last_map'] = "/?select_star=$id&select_center=1";
    header("Location: /?select_star=$id&select_center=1",true,302);
} else {
    echo "<h3>No match for &ldquo;".htmlspecialchars($q)."&rdquo;</h3>";
    echo '<p><a href="/">Back to map</a></p>';
}
