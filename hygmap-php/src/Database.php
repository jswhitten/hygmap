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
        int    $world_id = 0,
        string $order = 'absmag asc'
    ): array {
        [$xmin, $xmax, $ymin, $ymax, $zmin, $zmax] = $bbox;

        $allowed = ['absmag','absmag desc','absmag asc','mag','mag desc','mag asc','proper','dist'];
        if (!in_array(strtolower($order), $allowed, true)) {
            $order = 'absmag asc';
        }

        $MAX_ROWS = 10000;

        if($world_id > 0) {
            $sql = "
                SELECT a.*, f.name
                FROM   athyg a
                LEFT   JOIN fic f 
                ON     a.id = f.star_id
                AND    f.world_id = ?
                WHERE  x BETWEEN ? AND ?
                AND  y BETWEEN ? AND ?
                AND  z BETWEEN ? AND ?
                AND  absmag <= ?
                ORDER  BY $order
                LIMIT $MAX_ROWS
            ";

            $stmt = self::connection()->prepare($sql);
            $stmt->execute([$world_id,$xmin,$xmax,$ymin,$ymax,$zmin,$zmax,$magLimit]);
        } else {
            $sql = "
                SELECT a.*, ''
                FROM   athyg a
                WHERE  x BETWEEN ? AND ?
                AND  y BETWEEN ? AND ?
                AND  z BETWEEN ? AND ?
                AND  absmag <= ?
                ORDER  BY $order
                LIMIT $MAX_ROWS
            ";

            $stmt = self::connection()->prepare($sql);
            $stmt->execute([$xmin,$xmax,$ymin,$ymax,$zmin,$zmax,$magLimit]);

        }

        return $stmt->fetchAll();
    }

    /** Return one star by ATHYG id, or null */
    public static function queryStar(int $id, int $world_id): ?array
    {
        echo 'queryStar('.$id.','.$world_id.')<br>';
        if($world_id) {
            $sql = "SELECT a.*, f.name
                    FROM athyg a
                    LEFT JOIN fic f ON a.id = f.star_id AND f.world_id = ?
                    WHERE a.id = ?";
            $stmt = self::connection()->prepare($sql);
            $stmt->execute([$world_id, $id]); // order of parameters flipped!
        } else {
            $sql  = "SELECT    *, '' AS name
                        FROM   athyg
                        WHERE  id = ?";
            $stmt = self::connection()->prepare($sql);
            $stmt->execute([$id]);
        }

        return $stmt->fetch() ?: null;
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
        // greek-letter ➜ 3-letter Bayer prefix
        $greek = [
        'α'=>'alp','alpha'=>'alp',  'alp'=>'alp',
        'β'=>'bet','beta'=>'bet',   'bet'=>'bet',
        'γ'=>'gam','gamma'=>'gam',  'gam'=>'gam',
        'δ'=>'del','delta'=>'del',  'del'=>'del',
        'ε'=>'eps','epsilon'=>'eps','eps'=>'eps',
        'ζ'=>'zet','zeta'=>'zet',   'zet'=>'zet',
        'η'=>'eta','eta'=>'eta',
        'θ'=>'the','theta'=>'the',  'the'=>'the',
        'ι'=>'iot','iota'=>'iot',   'iot'=>'iot',
        'κ'=>'kap','kappa'=>'kap',  'kap'=>'kap',
        'λ'=>'lam','lambda'=>'lam', 'lam'=>'lam',
        'μ'=>'mu','mu'=>'mu',
        'ν'=>'nu','nu'=>'nu',
        'ξ'=>'ksi','xi'=>'ksi',     'ksi'=>'ksi',
        'ο'=>'omi','omicron'=>'omi','omi'=>'omi',
        'π'=>'pi','pi'=>'pi',
        'ρ'=>'rho','rho'=>'rho',
        'σ'=>'sig','sigma'=>'sig',  'sig'=>'sig',
        'τ'=>'tau','tau'=>'tau',
        'υ'=>'ups','upsilon'=>'ups','ups'=>'ups',
        'φ'=>'phi','phi'=>'phi',
        'χ'=>'chi','chi'=>'chi',
        'ψ'=>'psi','psi'=>'psi',
        'ω'=>'ome','omega'=>'ome',  'ome'=>'ome',
        ];

        $const = [
        'andromeda'          => 'And',
        'antlia'             => 'Ant',
        'apus'               => 'Aps',
        'aquarius'           => 'Aqr',
        'aquila'             => 'Aql',
        'ara'                => 'Ara',
        'aries'              => 'Ari',
        'auriga'             => 'Aur',
        'bootes'             => 'Boo',
        'caelum'             => 'Cae',
        'camelopardalis'     => 'Cam',
        'cancer'             => 'Cnc',
        'canesvenatici'      => 'CVn',
        'canismajor'         => 'CMa',
        'canisminor'         => 'CMi',
        'capricornus'        => 'Cap',
        'carina'             => 'Car',
        'cassiopeia'         => 'Cas',
        'centaurus'          => 'Cen',
        'cepheus'            => 'Cep',
        'cetus'              => 'Cet',
        'chamaeleon'         => 'Cha',
        'circinus'           => 'Cir',
        'columba'            => 'Col',
        'comaberenices'      => 'Com',
        'coronaaustralis'    => 'CrA',
        'coronaborealis'     => 'CrB',
        'corvus'             => 'Crv',
        'crater'             => 'Crt',
        'crux'               => 'Cru',
        'cygnus'             => 'Cyg',
        'delphinus'          => 'Del',
        'dorado'             => 'Dor',
        'draco'              => 'Dra',
        'equuleus'           => 'Equ',
        'eridanus'           => 'Eri',
        'fornax'             => 'For',
        'gemini'             => 'Gem',
        'grus'               => 'Gru',
        'hercules'           => 'Her',
        'horologium'         => 'Hor',
        'hydra'              => 'Hya',
        'hydrus'             => 'Hyi',
        'indus'              => 'Ind',
        'lacerta'            => 'Lac',
        'leo'                => 'Leo',
        'leominor'           => 'LMi',
        'lepus'              => 'Lep',
        'libra'              => 'Lib',
        'lupus'              => 'Lup',
        'lynx'               => 'Lyn',
        'lyra'               => 'Lyr',
        'mensa'              => 'Men',
        'microscopium'       => 'Mic',
        'monoceros'          => 'Mon',
        'musca'              => 'Mus',
        'norma'              => 'Nor',
        'octans'             => 'Oct',
        'ophiuchus'          => 'Oph',
        'orion'              => 'Ori',
        'pavo'               => 'Pav',
        'pegasus'            => 'Peg',
        'perseus'            => 'Per',
        'phoenix'            => 'Phe',
        'pictor'             => 'Pic',
        'piscisaustrinus'    => 'PsA',
        'pisces'             => 'Psc',
        'puppis'             => 'Pup',
        'pyxis'              => 'Pyx',
        'reticulum'          => 'Ret',
        'sagitta'            => 'Sge',
        'sagittarius'        => 'Sgr',
        'scorpius'           => 'Sco',
        'sculptor'           => 'Scl',
        'serpens'            => 'Ser',
        'sextans'            => 'Sex',
        'taurus'             => 'Tau',
        'telescopium'        => 'Tel',
        'triangulumaustrale' => 'TrA',
        'triangulum'         => 'Tri',
        'tucana'             => 'Tuc',
        'ursamajor'          => 'UMa',
        'ursaminor'          => 'UMi',
        'vela'               => 'Vel',
        'virgo'              => 'Vir',
        'volans'             => 'Vol',
        'vulpecula'          => 'Vul',
        ];


        if (preg_match('/^\s*([0-9]+)\s+([a-z]{3,})/i', $t, $m)) {
            // Flamsteed
            $num  = ltrim($m[1],'0');
            $con3 = ucfirst($const[strtolower(preg_replace('/\s+/','',$m[2]))] ?? ucfirst(substr($m[2],0,3)));

            $sql  = "SELECT id FROM athyg WHERE flam=? AND lower(con)=? LIMIT 1";
            $stmt = self::connection()->prepare($sql);
            $stmt->execute([$num,$con3]);
            if ($r=$stmt->fetch()) return $r;

        } elseif (preg_match('/^\s*([^\d\s]+)\s+([a-z]{3,})/iu', $t, $m)) {
            // Bayer
            $g = mb_strtolower($m[1], 'UTF-8');
            $bayer = isset($greek[$g]) ? ucfirst($greek[$g]) : null;   // safe lookup
            if ($bayer) {
                $con3 = ucfirst(
                        $const[strtolower(preg_replace('/\s+/', '', $m[2]))]
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
    public static function queryFiction(?string $universe = null): array
    {
        if ($universe) {
            $sql = "SELECT * FROM fic WHERE world_id = ? ORDER BY name";
            $stmt = self::connection()->prepare($sql);
            $stmt->execute([$universe]);
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

}
