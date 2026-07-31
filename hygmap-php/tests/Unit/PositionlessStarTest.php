<?php
declare(strict_types=1);

namespace HYGMap\Tests\Unit;

use PHPUnit\Framework\TestCase;
use IndexHelpers;

/**
 * Fixture-driven tests for IndexHelpers::hasPosition().
 *
 * Reads tests/fixtures/positionless-stars.json — the same file the React and API suites
 * read — so all three stacks agree on which stars can be plotted.
 *
 * 25,342 catalogue stars have no usable parallax, so the API returns null for `dist` and
 * `x`/`y`/`z`. PHP cast those to float, which yields 0.0 — a real coordinate, Sol's — so a
 * positionless star was silently drawn at the origin and, with `select_center=1`,
 * re-centred the whole map there.
 *
 * The fixture is mounted at /fixtures by the Makefile.
 */
class PositionlessStarTest extends TestCase
{
    private const FIXTURE_PATH = '/fixtures/positionless-stars.json';

    public static function setUpBeforeClass(): void
    {
        require_once HYGMAP_SRC_DIR . '/IndexHelpers.php';
    }

    /** @return array<string, array{0: array<string, mixed>, 1: bool, 2: string}> */
    public static function fixtureCases(): array
    {
        if (!file_exists(self::FIXTURE_PATH)) {
            throw new \RuntimeException(
                'Shared fixture not mounted at ' . self::FIXTURE_PATH
                . '; see the test-unit target in the Makefile'
            );
        }

        $data = json_decode((string)file_get_contents(self::FIXTURE_PATH), true);
        if (!is_array($data['cases'] ?? null) || count($data['cases']) < 3) {
            throw new \RuntimeException('Shared fixture is empty or malformed');
        }

        $out = [];
        foreach ($data['cases'] as $case) {
            $out[$case['name']] = [$case['star'], $case['has_position'], $case['why']];
        }

        return $out;
    }

    /**
     * @dataProvider fixtureCases
     * @param array<string, mixed> $star
     */
    public function testHasPositionMatchesTheSharedFixture(
        array $star,
        bool $expected,
        string $why
    ): void {
        $this->assertSame($expected, IndexHelpers::hasPosition($star), $why);
    }

    public function testTheOriginIsAPositionNotTheAbsenceOfOne(): void
    {
        // The most important case. The bug being fixed cast null to 0.0 and drew
        // positionless stars at Sol; a check written with empty() or a truthiness test
        // would classify Sol itself as positionless and recreate the same confusion from
        // the opposite direction.
        $this->assertTrue(IndexHelpers::hasPosition(['x' => 0.0, 'y' => 0.0, 'z' => 0.0]));
        $this->assertTrue(IndexHelpers::hasPosition(['x' => 0, 'y' => 0, 'z' => 0]));
    }

    public function testANullCoordinateIsNoPosition(): void
    {
        $this->assertFalse(IndexHelpers::hasPosition(['x' => null, 'y' => null, 'z' => null]));
    }

    public function testAnAbsentKeyIsNoPosition(): void
    {
        // A partial row — from a projection that did not select coordinates — must not
        // read as plottable.
        $this->assertFalse(IndexHelpers::hasPosition([]));
        $this->assertFalse(IndexHelpers::hasPosition(['proper' => 'Sirius']));
    }

    public function testAPresentButNullKeyIsDistinguishedFromAnAbsentOne(): void
    {
        // Both are "no position", but for different reasons, and the implementation uses
        // array_key_exists rather than isset precisely so a present-null does not get
        // confused with a missing field. Asserted so the distinction is not "simplified"
        // away later.
        $this->assertFalse(IndexHelpers::hasPosition(['x' => null]));
        $this->assertFalse(IndexHelpers::hasPosition(['y' => 1.0]));
    }
}
