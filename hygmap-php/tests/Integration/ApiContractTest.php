<?php
declare(strict_types=1);

namespace HYGMap\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ApiClient;
use RuntimeException;

/**
 * Contract tests: PHP against the REAL FastAPI backend.
 *
 * Why this file exists
 * --------------------
 * Every other PHP test mocks ApiClient, every API test runs the API in isolation, and
 * every frontend test mocks fetch. So the API could change shape and all three suites
 * would stay green — the mocks encode whatever the contract was on the day they were
 * written. That is not hypothetical: an audit on 2026-07-29 found the suites had no
 * power to catch the defects this project actually produces, and this was the largest
 * hole.
 *
 * These tests make no assertions about *values* beyond what is astronomically stable
 * (Sol sits at the origin). They assert the SHAPE of the response — that every field
 * the PHP app reads is actually present, with a usable type. A field silently
 * disappearing from the API is the failure this catches, and it is the one that would
 * otherwise reach production as a blank column or a PHP warning.
 *
 * Requires the stack to be up: `docker compose up -d`, then `make test-integration`.
 */
class ApiContractTest extends TestCase
{
    private ApiClient $api;

    /** Bounding box around Sol, in parsecs: [xmin, xmax, ymin, ymax, zmin, zmax] */
    private const NEARBY_BBOX = [-15.0, 15.0, -15.0, 15.0, -15.0, 15.0];

    /**
     * Fields the PHP app reads off a star row from queryAll().
     *
     * Derived from actual use in IndexHelpers, MapRenderer, StarFormatter and
     * export.php. If you remove one from the API, this test should fail before a
     * user sees a blank column.
     */
    private const REQUIRED_LIST_FIELDS = [
        'id', 'proper', 'bayer', 'flam', 'con', 'spect',
        'absmag', 'mag', 'dist', 'x', 'y', 'z',
        'hip', 'hd', 'gj', 'cns5', 'gaia', 'tyc',
        'name',
    ];

    protected function setUp(): void
    {
        require_once HYGMAP_SRC_DIR . '/ApiClient.php';
        ApiClient::resetInstance();
        $this->api = ApiClient::instance();

        try {
            $this->api->queryWorlds();
        } catch (RuntimeException $e) {
            $this->markTestSkipped(
                'API not reachable; start the stack with `docker compose up -d`. ' . $e->getMessage()
            );
        }
    }

    protected function tearDown(): void
    {
        ApiClient::resetInstance();
    }

    // =========================================================================
    // queryAll — the bbox query behind the map and the star table
    // =========================================================================

    public function testQueryAllReturnsStars(): void
    {
        $rows = $this->api->queryAll(self::NEARBY_BBOX, 20.0);

        $this->assertNotEmpty($rows, 'No stars within 15pc of Sol — the database is empty or the bbox contract changed');
    }

    public function testQueryAllRowsCarryEveryFieldPhpReads(): void
    {
        $rows = $this->api->queryAll(self::NEARBY_BBOX, 20.0);
        $row = $rows[0];

        foreach (self::REQUIRED_LIST_FIELDS as $field) {
            $this->assertArrayHasKey(
                $field,
                $row,
                "The API stopped returning '{$field}', which the PHP app reads. "
                . 'Check StarBase in hygmap-api/app/schemas/star.py.'
            );
        }
    }

    public function testCoordinatesAndMagnitudesAreNumeric(): void
    {
        $rows = $this->api->queryAll(self::NEARBY_BBOX, 20.0);

        foreach (['x', 'y', 'z', 'absmag'] as $field) {
            $this->assertIsNumeric(
                $rows[0][$field],
                "Field '{$field}' must be numeric — PHP does arithmetic on it without casting checks"
            );
        }
    }

    public function testSolIsAtTheOrigin(): void
    {
        $rows = $this->api->queryAll(self::NEARBY_BBOX, 20.0);

        $sol = null;
        foreach ($rows as $row) {
            if (($row['proper'] ?? '') === 'Sol') {
                $sol = $row;
                break;
            }
        }

        $this->assertNotNull($sol, 'Sol is not in a 15pc box around the origin');
        // The coordinate system is defined with Sol at (0,0,0). If this drifts, every
        // distance in both UIs is wrong, and nothing else would notice.
        $this->assertEqualsWithDelta(0.0, (float)$sol['x'], 0.001, 'Sol has moved off the X origin');
        $this->assertEqualsWithDelta(0.0, (float)$sol['y'], 0.001, 'Sol has moved off the Y origin');
        $this->assertEqualsWithDelta(0.0, (float)$sol['z'], 0.001, 'Sol has moved off the Z origin');
    }

    public function testMagnitudeLimitExcludesDimmerStars(): void
    {
        $bright = $this->api->queryAll(self::NEARBY_BBOX, 5.0);
        $all    = $this->api->queryAll(self::NEARBY_BBOX, 20.0);

        $this->assertLessThan(
            count($all),
            count($bright),
            'The magnitude limit did not reduce the result set — mag_max may be filtering the wrong column'
        );

        foreach ($bright as $row) {
            $this->assertLessThan(
                5.0,
                (float)$row['absmag'],
                'mag_max must filter on absolute magnitude; a star dimmer than the limit came back'
            );
        }
    }

    public function testOrderingByDistancePutsSolFirst(): void
    {
        $rows = $this->api->queryAll(self::NEARBY_BBOX, 20.0, 0, 'dist asc');

        $this->assertSame(
            'Sol',
            $rows[0]['proper'] ?? null,
            'Ordering by ascending distance should start at Sol, which is 0pc away'
        );
    }

    // =========================================================================
    // queryStar — the star detail panel
    // =========================================================================

    public function testQueryStarReturnsDetailForAKnownStar(): void
    {
        $rows = $this->api->queryAll(self::NEARBY_BBOX, 20.0);
        $id = (int)$rows[0]['id'];

        $star = $this->api->queryStar($id, 0);

        $this->assertNotNull($star, "Star {$id} came back from queryAll but not from queryStar");
        $this->assertSame($id, (int)$star['id']);

        foreach (['proper', 'bayer', 'flam', 'con', 'spect', 'absmag', 'x', 'y', 'z'] as $field) {
            $this->assertArrayHasKey($field, $star, "Star detail is missing '{$field}'");
        }
    }

    public function testQueryStarThrowsTypedNotFoundForAnUnknownId(): void
    {
        // ApiClient used to throw a bare RuntimeException for every 4xx, so callers could
        // not tell "no such star" from "the API is down" -- both became the same generic
        // page with an HTTP 200. The typed exception is what lets index.php answer 404.
        // Deliberately beyond every assigned id range, including the 7,000,000-block used
        // for supplement stars.
        $this->expectException(\ApiNotFoundException::class);

        $this->api->queryStar(999999999, 0);
    }

    public function testNotFoundExceptionIsStillARuntimeException(): void
    {
        // Existing catch (RuntimeException) blocks must keep working.
        try {
            $this->api->queryStar(999999999, 0);
            $this->fail('Expected an exception for an unknown star id');
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(\ApiNotFoundException::class, $e);
            $this->assertStringContainsString('404', $e->getMessage());
        }
    }

    // =========================================================================
    // searchStar — used by search.php
    // =========================================================================

    public function testSearchFindsAWellKnownStar(): void
    {
        $result = $this->api->searchStar('Sirius');

        $this->assertNotNull($result, 'Searching for Sirius returned nothing');
        $this->assertArrayHasKey('id', $result, 'search.php redirects using the id field');
    }

    public function testSearchAcceptsBothGreekLetterForms(): void
    {
        // athyg.bayer stores "Alp-1" for Alpha Centauri, so a plain substring match on
        // "alp cen" finds nothing. Both spellings must resolve to the same star.
        $spelled = $this->api->searchStar('alpha cen');
        $abbrev  = $this->api->searchStar('alp cen');

        $this->assertNotNull($spelled, 'Search for "alpha cen" returned nothing');
        $this->assertNotNull($abbrev, 'Search for "alp cen" returned nothing');
        $this->assertSame(
            (int)$spelled['id'],
            (int)$abbrev['id'],
            'The spelled-out and abbreviated Bayer forms must find the same star'
        );
    }

    public function testSearchForNonsenseReturnsNull(): void
    {
        $this->assertNull(
            $this->api->searchStar('zzzznotastar'),
            'A search with no matches must return null rather than an arbitrary star'
        );
    }

    // =========================================================================
    // Dropdown data — configure.php and the fictional-world selector
    // =========================================================================

    public function testQueryWorldsReturnsUsableOptions(): void
    {
        $worlds = $this->api->queryWorlds();

        $this->assertNotEmpty($worlds, 'No fictional worlds returned; the universe dropdown would be empty');
        $this->assertArrayHasKey('id', $worlds[0]);
        $this->assertArrayHasKey('name', $worlds[0]);
    }

    public function testQueryProperNamesReturnsUsableOptions(): void
    {
        $names = $this->api->queryProperNames();

        $this->assertNotEmpty($names, 'No proper names returned; the star dropdown would be empty');
        $this->assertArrayHasKey('id', $names[0]);
        $this->assertArrayHasKey('proper', $names[0]);
    }

    public function testFictionalNamesAreScopedToTheirWorld(): void
    {
        $worlds = $this->api->queryWorlds();
        $worldId = (int)$worlds[0]['id'];

        $names = $this->api->queryFiction($worldId);

        $this->assertNotEmpty($names, "World {$worldId} has no fictional names");
        $this->assertArrayHasKey('star_id', $names[0], 'Fictional names must carry the star they attach to');
        $this->assertArrayHasKey('name', $names[0]);
    }

    public function testWorldIdZeroMeansNoFictionalNames(): void
    {
        // 0 is the documented sentinel for "no fictional overlay". The API must not
        // attach names when it is passed.
        $rows = $this->api->queryAll(self::NEARBY_BBOX, 20.0, 0);

        foreach ($rows as $row) {
            $this->assertSame(
                '',
                $row['name'],
                'world_id=0 must return empty fictional names, not names from some default world'
            );
        }
    }

    // =========================================================================
    // Signals
    // =========================================================================

    public function testQuerySignalsReturnsPositionedSignals(): void
    {
        $signals = $this->api->querySignals(self::NEARBY_BBOX);

        if ($signals === []) {
            $this->markTestSkipped('No SETI signals within 15pc; nothing to check');
        }

        foreach (['id', 'x', 'y', 'z'] as $field) {
            $this->assertArrayHasKey($field, $signals[0], "Signal rows must carry '{$field}' to be drawn");
        }
    }
}
