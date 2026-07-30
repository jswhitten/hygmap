<?php
declare(strict_types=1);

namespace HYGMap\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StarFormatter;

/**
 * Fixture-driven display-name tests for StarFormatter.
 *
 * The existing tests in CommonIncTest.php set one catalog field at a time, so none of
 * them can reproduce the bug an audit found on 2026-07-29: star 7301 carries both `gj`
 * and `hip`, PHP renders "GJ 1" and the API renders "HIP 439". This class drives the
 * shared table at tests/fixtures/display-names.json, which the API and frontend suites
 * read too, so all three are held to one set of expectations.
 *
 * Cases the fixture marks with `php_diverges` are the known disagreements. They assert
 * PHP's CURRENT behaviour and then mark themselves incomplete, so the suite stays green
 * while the divergence stays visible in every run. DISPLAY-NAME-CANON decides the
 * canonical order and removes those flags.
 *
 * The fixture is mounted at /fixtures by the Makefile.
 */
class DisplayNameFixtureTest extends TestCase
{
    private const FIXTURE_PATH = '/fixtures/display-names.json';

    /** Defaults so each fixture case only states the fields it exercises. */
    private const ROW_DEFAULTS = [
        'id' => 0, 'proper' => null, 'bayer' => null, 'flam' => null, 'con' => null,
        'hip' => null, 'hd' => null, 'hr' => null, 'gj' => null,
        'cns5' => null, 'gaia' => null, 'tyc' => null, 'name' => '',
    ];

    protected function setUp(): void
    {
        require_once HYGMAP_SRC_DIR . '/StarFormatter.php';

        if (!file_exists(self::FIXTURE_PATH)) {
            $this->markTestSkipped('Shared fixture not mounted at ' . self::FIXTURE_PATH);
        }
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function displayNameCases(): array
    {
        if (!file_exists(self::FIXTURE_PATH)) {
            return ['fixture not mounted' => [[]]];
        }

        $data = json_decode((string)file_get_contents(self::FIXTURE_PATH), true);
        $out = [];
        foreach ($data['cases'] as $case) {
            $out[$case['name']] = [$case];
        }

        return $out;
    }

    /**
     * @dataProvider displayNameCases
     * @param array<string, mixed> $case
     */
    public function testDisplayNameMatchesSharedFixture(array $case): void
    {
        if ($case === []) {
            $this->markTestSkipped('Shared fixture not mounted');
        }

        $row = array_merge(self::ROW_DEFAULTS, $case['star']);
        // PHP takes the selected world as an argument rather than inferring it from the
        // presence of a fictional name, so the fixture's world_id has to be passed
        // through. (The API infers it, because its query only populates `name` when a
        // world was requested.)
        $worldId = (int)($case['world_id'] ?? 0);
        $actual = StarFormatter::getDisplayName($row, $worldId);

        if (isset($case['php_diverges'])) {
            // Documented disagreement with the shared table. Assert what PHP does today
            // so an unnoticed third behaviour cannot creep in, then flag it.
            $this->assertSame(
                $case['php_diverges'],
                $actual,
                $case['name'] . ': PHP behaviour changed but the fixture still records the old divergence'
            );
            $this->markTestIncomplete(sprintf(
                '%s: PHP says "%s", the shared fixture expects "%s". Tracked by DISPLAY-NAME-CANON.',
                $case['name'],
                $actual,
                $case['expected']
            ));
        }

        $this->assertSame(
            $case['expected'],
            $actual,
            $case['name'] . ': PHP disagrees with the shared display-name table'
        );
    }
}
