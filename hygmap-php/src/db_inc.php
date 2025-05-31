<?php

function open_db() {
    // Retrieve environment variables
    $db_user = getenv('DB_USERNAME');
    $db_pass = getenv('DB_PASSWORD');
    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_port = getenv('DB_PORT') ?: '5432';
    $db_name = getenv('DB_NAME') ?: 'hygmap';

    try {
        $dsn = "pgsql:host=$db_host;port=$db_port;dbname=$db_name";
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        return $pdo;
    } catch (PDOException $e) {
        die("Could not connect to PostgreSQL: " . $e->getMessage());
    }
}

function query_all($square = false, $max_line = 0, $order = "absmag") {
    global $x_c, $y_c, $z_c, $zoom, $z_zoom, $mag_limit;

    $pdo = open_db();

    $x_max = $x_c + $zoom + $max_line;
    $y_max = $y_c + ($square ? 1 : 2) * $zoom + $max_line;
    $z_max = $z_c + $z_zoom;

    $x_min = $x_c - $zoom - $max_line;
    $y_min = $y_c - ($square ? 1 : 2) * $zoom - $max_line;
    $z_min = $z_c - $z_zoom;

    // Updated query with proper escaping for ORDER BY
    $allowed_orders = ['absmag', 'absmag desc', 'mag', 'mag desc', 'proper', 'dist'];
    if (!in_array($order, $allowed_orders)) {
        $order = 'absmag desc';
    }

    $query = "SELECT athyg.*, fic.name FROM athyg LEFT JOIN fic ON athyg.id = fic.id 
              WHERE x > ? AND x < ? AND y > ? AND y < ? AND z > ? AND z < ? 
              AND absmag < ? AND dist_src <> 'NONE' 
              ORDER BY $order";
    
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute([$x_min, $x_max, $y_min, $y_max, $z_min, $z_max, $mag_limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        die("Query failed: " . $e->getMessage());
    }
}

function query_star($id) {
    $pdo = open_db();

    $query = "SELECT athyg.*, fic.name FROM athyg LEFT JOIN fic ON athyg.id = fic.id WHERE athyg.id = ?";
    
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        die("Query failed: " . $e->getMessage());
    }
}

function query_startrek() {
    $pdo = open_db();

    $query = "SELECT * FROM fic ORDER BY name";
    
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        die("Query failed: " . $e->getMessage());
    }
}

?>