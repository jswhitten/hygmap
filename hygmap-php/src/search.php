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

/**
 * One page shell for every branch below.
 *
 * This exists because the `lang`/charset fix landed in one branch of this file and not
 * its sibling six lines away, and audit-frontend then found the same defect twice in the
 * same function (2026-07-31). Three branches emitting their own <head> by hand is what
 * made that possible, so there is now one place to get it wrong.
 */
function search_page(string $title, string $body): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title></head>';
    echo '<body style="font-family:sans-serif;margin:2rem;">';
    echo $body;
    echo '<p>[ <a href="/">Back to map</a> ]</p>';
    echo '</body></html>';
}

try {
    $row = ApiClient::instance()->searchStar($q, $ficNames);
} catch (RuntimeException $e) {
    error_log("Search error: " . $e->getMessage());
    search_page(
        'Search Error',
        '<h3>⚠️ Search Error</h3>'
        . '<p>The star database did not answer in time. It may be busy rather than'
        . ' down — trying again often works.</p>'
    );
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
    search_page(
        'Star found, but not mappable',
        '<h3>' . htmlspecialchars((string)$name, ENT_QUOTES) . ' cannot be shown on the map</h3>'
        . '<p>This star exists in the catalog, but no parallax measurement exists for it, so'
        . ' its distance and position are unknown. It has a place on the sky but not in 3D'
        . ' space, so the map has nowhere to put it.</p>'
        . '<p>[ <a href="/?select_star=' . $id . '&amp;c=' . IndexHelpers::CATALOG_VERSION
        . '">See what is known about it</a> ]</p>'
    );
} elseif ($row) {
    $id = (int)$row['id'];
    $c = IndexHelpers::CATALOG_VERSION;
    $_SESSION['last_map'] = "/?select_star=$id&select_center=1&c=$c";
    header("Location: /?select_star=$id&select_center=1&c=$c", true, 302);
} else {
    // Before saying "no match", check whether the name exists in a universe the visitor
    // has not switched on. "Vulcan" with no universe selected is not a typo and should not
    // read like one — it is the exact first interaction this project describes itself by,
    // and it currently dead-ends. One extra lookup, on this path only.
    //
    // A failure here must not turn a plain "no match" into an error page: the search
    // itself already succeeded, and this is a hint, not the answer.
    $elsewhere = null;
    try {
        $elsewhere = ApiClient::instance()->findFictionalNameInOtherWorlds($q, $ficNames);
    } catch (RuntimeException $e) {
        error_log("Fictional-universe hint lookup failed: " . $e->getMessage());
    }

    $body = '<h3>No match for &ldquo;' . htmlspecialchars($q, ENT_QUOTES) . '&rdquo;</h3>';
    if ($elsewhere !== null) {
        $body .= '<p><strong>' . htmlspecialchars($elsewhere['name'], ENT_QUOTES)
              . '</strong> is a name in <strong>'
              . htmlspecialchars($elsewhere['world_name'], ENT_QUOTES)
              . '</strong>, which is not switched on. Fictional names are only searched in'
              . ' the universe you have selected, so this star is findable once you enable'
              . ' it.</p>'
              . '<p>[ <a href="/configure.php">Choose a fictional universe</a> ]</p>';
    }
    search_page('No match', $body);
}
