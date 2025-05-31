<?php
// test_postgres_connection.php
include 'db_inc.php';

try {
    $pdo = open_db();
    echo "✅ PostgreSQL connection successful!\n<br>";
    
    // Test a simple query
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM athyg");
    $result = $stmt->fetch();
    echo "✅ Total stars: " . $result['count'] . "\n<br>";
    
    // Test your function (you'll need to set these globals first)
    $x_c = 0; $y_c = 0; $z_c = 0; $zoom = 50; $z_zoom = 50; $mag_limit = 10;
    $stars = query_all();
    echo "✅ Query returned " . count($stars) . " stars\n<br>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n<br>";
}
?>