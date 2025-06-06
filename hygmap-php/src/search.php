<?php
declare(strict_types=1);
require_once 'common_inc.php';
require_once 'Database.php';
session_start();

$q = trim($_GET['q'] ?? '');
if ($q === '') { header('Location: index.php'); exit; }

$row = Database::searchStar($q);

if ($row) {
    $id = (int)$row['id'];
    $_SESSION['last_map'] = "index.php?select_star=$id&select_center=1";
    header("Location: index.php?select_star=$id&select_center=1",true,302);
} else {
    echo "<h3>No match for &ldquo;".htmlspecialchars($q)."&rdquo;</h3>";
    echo '<p><a href="index.php">Back to map</a></p>';
}
