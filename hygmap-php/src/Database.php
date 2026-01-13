<?php
declare(strict_types=1);

require_once __DIR__ . '/AstronomyData.php';
require_once __DIR__ . '/DatabaseConnectionException.php';

final class Database
{
    /** @var PDO|null */
    private static ?PDO $pdo = null;
    
    /** Maximum rows to return from queries */
    private const MAX_ROWS = 10000;

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
            error_log("Database connection failed: " . $e->getMessage());
            throw new DatabaseConnectionException("Unable to connect to the star database", 0, $e);
        }

    }

    // --------------------------------------------------
    // Query helpers
    // --------------------------------------------------

    public static function queryAll(
        array  $bbox,
        float  $magLimit,
        int    $world_id = 0,
        string $order = 'absmag asc'
    ): array {
        [$xmin, $xmax, $ymin, $ymax, $zmin, $zmax] = $bbox;

        $allowed = ['absmag','absmag desc','absmag asc','mag','mag desc','mag asc','proper','dist'];
        if (!in_array(strtolower($order), $allowed, true)) {
            $order = 'absmag asc';
        }

        // Single query handles both cases: with or without fictional names
        $sql = "
            SELECT a.*, COALESCE(f.name, '') AS name
            FROM   athyg a
            LEFT   JOIN fic f 
            ON     a.id = f.star_id
            AND    f.world_id = ?
            WHERE  x BETWEEN ? AND ?
            AND    y BETWEEN ? AND ?
            AND    z BETWEEN ? AND ?
            AND    absmag <= ?
            ORDER  BY $order
            LIMIT " . self::MAX_ROWS;

        $stmt = self::connection()->prepare($sql);
        $stmt->execute([$world_id, $xmin, $xmax, $ymin, $ymax, $zmin, $zmax, $magLimit]);

        return $stmt->fetchAll();
    }

    /** Return one star by ATHYG id, or null */
    public static function queryStar(int $id, int $world_id): ?array
    {
        // Single query handles both cases: with or without fictional names
        $sql = "
            SELECT a.*, COALESCE(f.name, '') AS name
            FROM   athyg a
            LEFT   JOIN fic f 
            ON     a.id = f.star_id
            AND    f.world_id = ?
            WHERE  a.id = ?
        ";
        
        $stmt = self::connection()->prepare($sql);
        $stmt->execute([$world_id, $id]);

        return $stmt->fetch() ?: null;
    }


    // Query all signals in a bounding box
    public static function querySignals(
        array  $bbox,
        string $order = 'time desc'
    ): array {
        [$xmin, $xmax, $ymin, $ymax, $zmin, $zmax] = $bbox;

        // Security whitelist for the ORDER BY clause
        $allowed = ['time', 'time asc', 'time desc', 'name', 'name asc', 'name desc', 'frequency'];
        if (!in_array(strtolower($order), $allowed, true)) {
            $order = 'time desc';
        }

        $sql = "
            SELECT *
            FROM   signals
            WHERE  x BETWEEN ? AND ?
            AND    y BETWEEN ? AND ?
            AND    z BETWEEN ? AND ?
            ORDER  BY $order
            LIMIT " . self::MAX_ROWS;

        $stmt = self::connection()->prepare($sql);
        $stmt->execute([$xmin, $xmax, $ymin, $ymax, $zmin, $zmax]);

        return $stmt->fetchAll();
    }

    /* -------------------------------------------------
       Search by proper / Bayer / Flamsteed / catalog
       -------------------------------------------------*/
    public static function searchStar(string $term): ?array
    {
        $t = strtolower(trim($term));

        // 1. catalog prefixes
        $catalog = [
        ['hd'  , '/^hd\s*(\d+)/i' ,
                    true ],                     // 1234

        ['hip' , '/^hip\s*(\d+)/i',
                    true ],                     // 12345

        ['gaia', '/^gaia\s*(\d+)/i',
                    true ],                     // 1234567890

        // Gliese / GJ  — digits     + optional “.n” + optional letter
        ['gj'  , '/^(?:gliese|gl|gj)\s*(\d+(?:\.\d+)?[A-Za-z]?)/iu',
                    false],                    // 581 · 762.1 · 762.1A · 667C

        // Tycho — three numeric groups with dashes
        ['tyc' , '/^tyc\s*([\d]{1,5}-[\d]{1,5}-[\d])\b/i',
                    false],                    // 9007-5848-1
        ];

        foreach ($catalog as [$col, $re, $isNum]) {
            if (preg_match($re, $t, $m)) {
                $value = $isNum ? (int)$m[1] : $m[1];    // keep alphanum IDs intact
                $sql   = "SELECT id FROM athyg WHERE $col = ? LIMIT 1";
                $stmt  = self::connection()->prepare($sql);
                $stmt->execute([$value]);
                if ($r = $stmt->fetch()) return $r;
            }
        }

        // 2. Bayer / Flamsteed
        if (preg_match('/^\s*([0-9]+)\s+([a-z]{3,})/i', $t, $m)) {
            // Flamsteed
            $num  = ltrim($m[1],'0');
            $con3 = ucfirst(AstronomyData::CONSTELLATIONS[strtolower(preg_replace('/\s+/','',$m[2]))] ?? ucfirst(substr($m[2],0,3)));

            $sql  = "SELECT id FROM athyg WHERE flam=? AND lower(con)=lower(?) LIMIT 1";
            $stmt = self::connection()->prepare($sql);
            $stmt->execute([$num,$con3]);
            if ($r=$stmt->fetch()) return $r;

        } elseif (preg_match('/^\s*([^\d\s]+)\s+([a-z]{3,})/iu', $t, $m)) {
            // Bayer
            $g = mb_strtolower($m[1], 'UTF-8');
            $bayer = isset(AstronomyData::GREEK_LETTERS[$g]) ? ucfirst(AstronomyData::GREEK_LETTERS[$g]) : null;
            if ($bayer) {
                $con3 = ucfirst(
                        AstronomyData::CONSTELLATIONS[strtolower(preg_replace('/\s+/', '', $m[2]))]
                        ?? substr($m[2], 0, 3)
                        );

                // exact: "Iot Peg"
                $sql  = "SELECT id FROM athyg WHERE bayer = ? AND con = ? LIMIT 1";
                $stmt = self::connection()->prepare($sql);
                $stmt->execute([$bayer, $con3]);
                if ($r = $stmt->fetch()) return $r;

                // prefix:  “Alp Cen” matches Alp-1 / Alp-2 / Alp-A...
                $sql  = "SELECT id
                        FROM   athyg
                        WHERE  bayer ILIKE ?  -- case-insensitive prefix
                        AND    con   = ?
                        ORDER BY bayer        -- Alp-1 before Alp-2
                        LIMIT 1";
                $stmt = self::connection()->prepare($sql);
                $stmt->execute([$bayer.'%', $con3]);
                if ($r = $stmt->fetch()) return $r;
            }
        }
        return null;

    }

    /** All fiction names (optionally filter by universe) */
    public static function queryFiction(?int $world_id = null): array
    {
        if ($world_id) {
            $sql = "SELECT * FROM fic WHERE world_id = ? ORDER BY name";
            $stmt = self::connection()->prepare($sql);
            $stmt->execute([$world_id]);
        } else {
            $sql = "SELECT * FROM fic ORDER BY name";
            $stmt = self::connection()->prepare($sql);
            $stmt->execute();
        }
        return $stmt->fetchAll();
    }

    /** All real names */
    public static function queryProperNames(): array
    {
        $sql = "SELECT id, proper FROM athyg WHERE proper IS NOT NULL ORDER BY proper";
        $stmt = self::connection()->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** All fictional universes/worlds */
    public static function queryWorlds(): array
    {
        $sql = "SELECT id, name FROM fic_worlds ORDER BY id";
        $stmt = self::connection()->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll();
    }


}
