<?php
declare(strict_types=1);

namespace HYGMap\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Database;
use PDO;

/**
 * Integration tests for Database class
 *
 * These tests require a running database connection.
 * Skip with: phpunit --exclude-group=database
 *
 * @group database
 */
class DatabaseTest extends TestCase
{
    private static bool $databaseAvailable = false;

    public static function setUpBeforeClass(): void
    {
        // Uses HYGMAP_SRC_DIR from bootstrap
        require_once HYGMAP_SRC_DIR . '/Database.php';

        // Check if database is available
        try {
            Database::connection();
            self::$databaseAvailable = true;
        } catch (\Exception $e) {
            self::$databaseAvailable = false;
        }
    }

    protected function setUp(): void
    {
        if (!self::$databaseAvailable) {
            $this->markTestSkipped('Database not available');
        }
    }

    // =========================================================================
    // Connection Tests
    // =========================================================================

    public function testConnectionReturnsPdo(): void
    {
        $pdo = Database::connection();
        $this->assertInstanceOf(PDO::class, $pdo);
    }

    public function testConnectionIsSingleton(): void
    {
        $pdo1 = Database::connection();
        $pdo2 = Database::connection();
        $this->assertSame($pdo1, $pdo2);
    }

    // =========================================================================
    // queryAll Tests
    // =========================================================================

    public function testQueryAllReturnsArray(): void
    {
        $bbox = [-10, 10, -10, 10, -10, 10]; // 20pc cube around Sol
        $result = Database::queryAll($bbox, 10.0);

        $this->assertIsArray($result);
    }

    public function testQueryAllIncludesSol(): void
    {
        // Sol is at origin (0,0,0) with proper name "Sol"
        $bbox = [-1, 1, -1, 1, -1, 1];
        $result = Database::queryAll($bbox, 30.0);

        $solFound = false;
        foreach ($result as $star) {
            if (isset($star['proper']) && $star['proper'] === 'Sol') {
                $solFound = true;
                break;
            }
        }

        $this->assertTrue($solFound, 'Sol should be found near origin');
    }

    public function testQueryAllRespectsMagnitudeLimit(): void
    {
        $bbox = [-100, 100, -100, 100, -100, 100];

        // Query with strict magnitude limit
        $result = Database::queryAll($bbox, 5.0);

        foreach ($result as $star) {
            $this->assertLessThanOrEqual(5.0, $star['absmag'],
                'All stars should have absmag <= magnitude limit');
        }
    }

    public function testQueryAllWithFictionalWorld(): void
    {
        $bbox = [-100, 100, -100, 100, -100, 100];

        // Query with Star Trek universe (world_id = 1)
        $result = Database::queryAll($bbox, 10.0, 1);

        $this->assertIsArray($result);
        // Each row should have 'name' field (may be empty)
        if (!empty($result)) {
            $this->assertArrayHasKey('name', $result[0]);
        }
    }

    public function testQueryAllOrderByAllowed(): void
    {
        $bbox = [-10, 10, -10, 10, -10, 10];

        // Test allowed order values
        $allowedOrders = ['absmag', 'absmag desc', 'absmag asc', 'mag'];
        foreach ($allowedOrders as $order) {
            $result = Database::queryAll($bbox, 10.0, 0, $order);
            $this->assertIsArray($result);
        }
    }

    public function testQueryAllOrderByInvalidFallsBackToDefault(): void
    {
        $bbox = [-10, 10, -10, 10, -10, 10];

        // Invalid order should not throw, should use default
        $result = Database::queryAll($bbox, 10.0, 0, 'invalid; DROP TABLE athyg;--');
        $this->assertIsArray($result);
    }

    // =========================================================================
    // queryStar Tests
    // =========================================================================

    public function testQueryStarReturnsNullForInvalidId(): void
    {
        $result = Database::queryStar(999999999, 0);
        $this->assertNull($result);
    }

    public function testQueryStarReturnsSolById(): void
    {
        // First find Sol's ID
        $bbox = [-0.1, 0.1, -0.1, 0.1, -0.1, 0.1];
        $stars = Database::queryAll($bbox, 30.0);

        $solId = null;
        foreach ($stars as $star) {
            if (isset($star['proper']) && $star['proper'] === 'Sol') {
                $solId = $star['id'];
                break;
            }
        }

        if ($solId === null) {
            $this->markTestSkipped('Sol not found in database');
        }

        $result = Database::queryStar($solId, 0);
        $this->assertNotNull($result);
        $this->assertEquals('Sol', $result['proper']);
    }

    // =========================================================================
    // searchStar Tests
    // =========================================================================

    public function testSearchStarByHenryDraper(): void
    {
        // HD 48915 is Sirius
        $result = Database::searchStar('HD 48915');
        $this->assertNotNull($result, 'HD 48915 (Sirius) should be found');
        $this->assertArrayHasKey('id', $result);
    }

    public function testSearchStarByHipparcos(): void
    {
        // HIP 32349 is Sirius
        $result = Database::searchStar('HIP 32349');
        $this->assertNotNull($result, 'HIP 32349 (Sirius) should be found');
    }

    public function testSearchStarByBayer(): void
    {
        // Alpha Centauri
        $result = Database::searchStar('Alpha Centauri');
        $this->assertNotNull($result, 'Alpha Centauri should be found');
    }

    public function testSearchStarByBayerShort(): void
    {
        // Alpha Cen
        $result = Database::searchStar('Alpha Cen');
        $this->assertNotNull($result, 'Alpha Cen should be found');
    }

    public function testSearchStarByFlamsteed(): void
    {
        // 61 Cygni
        $result = Database::searchStar('61 Cygni');
        $this->assertNotNull($result, '61 Cygni should be found');
    }

    public function testSearchStarByGliese(): void
    {
        // Gliese 581 (famous exoplanet host)
        $result = Database::searchStar('GJ 581');
        // May or may not exist depending on data
        $this->assertTrue($result === null || isset($result['id']));
    }

    public function testSearchStarNotFound(): void
    {
        $result = Database::searchStar('Nonexistent Star XYZ123');
        $this->assertNull($result);
    }

    public function testSearchStarSqlInjectionSafe(): void
    {
        // This should not cause any SQL errors
        $result = Database::searchStar("'; DROP TABLE athyg; --");
        $this->assertNull($result);
    }

    // =========================================================================
    // queryProperNames Tests
    // =========================================================================

    public function testQueryProperNamesReturnsArray(): void
    {
        $result = Database::queryProperNames();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result, 'Should have at least one proper name');
    }

    public function testQueryProperNamesContainsSol(): void
    {
        $result = Database::queryProperNames();

        $solFound = false;
        foreach ($result as $star) {
            if ($star['proper'] === 'Sol') {
                $solFound = true;
                break;
            }
        }

        $this->assertTrue($solFound, 'Sol should be in proper names list');
    }

    // =========================================================================
    // queryWorlds Tests
    // =========================================================================

    public function testQueryWorldsReturnsArray(): void
    {
        $result = Database::queryWorlds();
        $this->assertIsArray($result);
    }

    public function testQueryWorldsHasExpectedStructure(): void
    {
        $result = Database::queryWorlds();

        if (!empty($result)) {
            $this->assertArrayHasKey('id', $result[0]);
            $this->assertArrayHasKey('name', $result[0]);
        }
    }

    // =========================================================================
    // queryFiction Tests
    // =========================================================================

    public function testQueryFictionReturnsArray(): void
    {
        $result = Database::queryFiction();
        $this->assertIsArray($result);
    }

    public function testQueryFictionFiltersByWorld(): void
    {
        // Get Star Trek fictional names (world_id = 1)
        $worlds = Database::queryWorlds();
        if (empty($worlds)) {
            $this->markTestSkipped('No worlds in database');
        }

        $worldId = $worlds[0]['id'];
        $result = Database::queryFiction($worldId);

        $this->assertIsArray($result);
        foreach ($result as $fic) {
            $this->assertEquals($worldId, $fic['world_id']);
        }
    }
}
