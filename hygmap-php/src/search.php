<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiClient.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/IndexHelpers.php';

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

if ($row && $row['x'] === null) {
    // Found, but it has no position, so there is nothing to centre the map on. Redirecting
    // would land the user on a view of Sol with this star's name in the panel — which is
    // the bug this replaces, not a lesser version of it. Say plainly what happened
    // instead: an unclickable result with no explanation is worse than either hiding the
    // star or plotting it wrongly. 25,342 stars are in this position.
    //
    // The star page is still linked, because the info panel now reports RA/Dec and
    // apparent magnitude honestly and marks the position unknown. What is dropped is
    // `select_center`, not access to the star.
    $id = (int)$row['id'];
    $name = $row['display_name'] ?? $q;
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<title>Star found, but not mappable</title></head>';
    echo '<body style="font-family:sans-serif;margin:2rem;">';
    echo '<h3>' . htmlspecialchars((string)$name, ENT_QUOTES) . ' cannot be shown on the map</h3>';
    echo '<p>This star exists in the catalog, but no parallax measurement exists for it, so'
       . ' its distance and position are unknown. It has a place on the sky but not in 3D'
       . ' space, so the map has nowhere to put it.</p>';
    echo '<p>[ <a href="/?select_star=' . $id . '&amp;c=' . IndexHelpers::CATALOG_VERSION
       . '">See what is known about it</a> ]</p>';
    echo '<p>[ <a href="/">Back to map</a> ]</p>';
    echo '</body></html>';
} elseif ($row) {
    $id = (int)$row['id'];
    $c = IndexHelpers::CATALOG_VERSION;
    $_SESSION['last_map'] = "/?select_star=$id&select_center=1&c=$c";
    header("Location: /?select_star=$id&select_center=1&c=$c", true, 302);
} else {
    echo "<h3>No match for &ldquo;".htmlspecialchars($q)."&rdquo;</h3>";
    echo '<p><a href="/">Back to map</a></p>';
}
