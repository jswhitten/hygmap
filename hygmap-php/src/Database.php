<?php
declare(strict_types=1);

final class Database
{
    /** @var PDO|null */
    private static ?PDO $pdo = null;

    /** Singleton connection */
    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        // --- env vars with sane fallbacks ---
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            getenv('DB_HOST')     ?: 'localhost',
            getenv('DB_PORT')     ?: '5432',
            getenv('DB_NAME')     ?: 'hygmap',
        );

        try {
            self::$pdo = new PDO(
                $dsn,
                getenv('DB_USERNAME') ?: 'postgres',
                getenv('DB_PASSWORD') ?: '',
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            return self::$pdo;
        } catch (PDOException $e) {
            die('❌ DB connect failed: ' . $e->getMessage());
        }
    }

    // --------------------------------------------------
    // Query helpers
    // --------------------------------------------------

    public static function queryAll(
        array  $bbox,
        float  $magLimit,
        string $order = 'absmag asc'
    ): array {
        [$xmin, $xmax, $ymin, $ymax, $zmin, $zmax] = $bbox;

        $allowed = ['absmag','absmag desc','absmag asc','mag','mag desc','mag asc','proper','dist'];
        if (!in_array(strtolower($order), $allowed, true)) {
            $order = 'absmag asc';
        }

        $MAX_ROWS = 10000;

        $sql = "
            SELECT a.*, f.name
            FROM   athyg a
            LEFT   JOIN fic f ON a.id = f.star_id
            WHERE  x BETWEEN ? AND ?
              AND  y BETWEEN ? AND ?
              AND  z BETWEEN ? AND ?
              AND  absmag <= ?
            ORDER  BY $order
            LIMIT $MAX_ROWS
        ";

        $stmt = self::connection()->prepare($sql);
        $stmt->execute([$xmin,$xmax,$ymin,$ymax,$zmin,$zmax,$magLimit]);
        return $stmt->fetchAll();
    }

    /** Return one star by ATHYG id, or null */
    public static function queryStar(int $id): ?array
    {
        $sql  = "SELECT a.*, f.name
                 FROM   athyg a
                 LEFT   JOIN fic f ON a.id = f.id
                 WHERE  a.id = ?";

        $stmt = self::connection()->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** All fiction names (optionally filter by universe) */
    public static function queryFiction(?string $universe = null): array
    {
        if ($universe) {
            $sql = "SELECT * FROM fic WHERE universe = ? ORDER BY name";
            $stmt = self::connection()->prepare($sql);
            $stmt->execute([$universe]);
        } else {
            $sql = "SELECT * FROM fic ORDER BY name";
            $stmt = self::connection()->prepare($sql);
            $stmt->execute();
        }
        return $stmt->fetchAll();
    }
}
