<?php
declare(strict_types=1);

namespace HYGMap\Tests\Unit;

use PHPUnit\Framework\TestCase;
use MapRenderer;
use ReflectionClass;

/**
 * Fixture-driven spectral-colour tests for MapRenderer.
 *
 * Reads the shared table at tests/fixtures/spectral-colors.json — the same file the React
 * suite reads — so the two renderers are held to one classification. An audit on 2026-07-31
 * found they disagreed for roughly 460 stars: React read character 0 blindly (classifying
 * 'sdF8:' as 's') and had no entry for the carbon and S-type classes R, C, N and S. PHP was
 * the correct side; these tests pin it so it stays the reference.
 *
 * Two reflection tricks, both to avoid GD:
 *
 * - `newInstanceWithoutConstructor()` — the composer image has no GD extension, so anything
 *   that allocates colours cannot run. The methods under test are pure string logic and do
 *   not need a constructed renderer.
 * - The `colors` property is replaced with a name-keyed table of distinct integers, so
 *   `getStarColor()` returns something that can be mapped straight back to the fixture's
 *   colour NAME. The fixture deliberately does not pin hex values — the 2D and 3D palettes
 *   differ on purpose — so what is asserted is which stars are grouped together.
 *
 * The fixture is mounted at /fixtures by the Makefile.
 */
class SpectralColorFixtureTest extends TestCase
{
    private const FIXTURE_PATH = '/fixtures/spectral-colors.json';

    /** Distinct sentinel per colour name, so the return value identifies the branch taken. */
    private const COLOR_IDS = [
        'blue' => 1,
        'lightblue' => 2,
        'lightyellow' => 3,
        'yellow' => 4,
        'orange' => 5,
        'red' => 6,
        'white' => 7,
        'black' => 8,
        'darkgrey' => 9,
        'darkblue' => 10,
        'green' => 11,
    ];

    /** @return array<int, array{spect: ?string, class: string, color: string, why: string}> */
    private function fixtureCases(): array
    {
        // A missing fixture fails rather than skips. A guard that quietly passes when the
        // thing it guards is absent is how TestProdComposeTrustSetting went unexecuted for
        // two audit cycles.
        $this->assertFileExists(
            self::FIXTURE_PATH,
            'shared fixture not mounted; see the test-unit target in the Makefile'
        );

        $data = json_decode((string)file_get_contents(self::FIXTURE_PATH), true);
        $this->assertIsArray($data['cases'] ?? null, 'fixture has no cases array');
        $this->assertGreaterThan(10, count($data['cases']), 'fixture is suspiciously small');

        return $data['cases'];
    }

    private function renderer(): MapRenderer
    {
        // MapRenderer is not autoloaded (src/ has no PSR-4 namespace); MapRendererTest
        // does the same.
        require_once HYGMAP_SRC_DIR . '/MapRenderer.php';

        $class = new ReflectionClass(MapRenderer::class);
        $renderer = $class->newInstanceWithoutConstructor();

        $colors = $class->getProperty('colors');
        $colors->setValue($renderer, self::COLOR_IDS);

        return $renderer;
    }

    /** @param array<string, mixed> $args */
    private function call(MapRenderer $renderer, string $method, array $args): mixed
    {
        $reflected = new \ReflectionMethod(MapRenderer::class, $method);
        return $reflected->invokeArgs($renderer, $args);
    }

    public function testSpectralClassMatchesTheSharedFixture(): void
    {
        $renderer = $this->renderer();

        foreach ($this->fixtureCases() as $case) {
            $actual = $this->call($renderer, 'getSpecClass', [$case['spect']]);
            $this->assertSame(
                $case['class'],
                $actual,
                sprintf('spect %s should classify as %s', json_encode($case['spect']), $case['class'])
            );
        }
    }

    public function testStarColorMatchesTheSharedFixture(): void
    {
        $renderer = $this->renderer();
        $idToName = array_flip(self::COLOR_IDS);

        foreach ($this->fixtureCases() as $case) {
            $id = $this->call($renderer, 'getStarColor', [$case['spect']]);
            $this->assertArrayHasKey($id, $idToName, 'getStarColor returned an unknown colour id');
            $this->assertSame(
                $case['color'],
                $idToName[$id],
                sprintf(
                    'spect %s should be %s%s',
                    json_encode($case['spect']),
                    $case['color'],
                    $case['why'] !== '' ? " ({$case['why']})" : ''
                )
            );
        }
    }

    public function testCarbonAndSTypeStarsShareTheCoolColours(): void
    {
        // The specific regression, asserted as equalities so a palette change cannot
        // silently split these apart again.
        $renderer = $this->renderer();
        $color = fn(?string $s) => $this->call($renderer, 'getStarColor', [$s]);

        $this->assertSame($color('K1V'), $color('R5'), 'R should be coloured as K');
        foreach (['C5II', 'N0', 'S4,2'] as $spect) {
            $this->assertSame($color('M5.5Ve'), $color($spect), "$spect should be coloured as M");
        }
    }

    public function testSubdwarfPrefixIsStrippedBeforeReadingTheClass(): void
    {
        $renderer = $this->renderer();
        $color = fn(?string $s) => $this->call($renderer, 'getStarColor', [$s]);

        $this->assertSame($color('F5IV'), $color('sdF8:'));
        $this->assertSame($color('B8Ia'), $color('sdB'));
        $this->assertSame($color('M5.5Ve'), $color(' dM4'));
    }

    public function testUppercaseSIsAnSTypeStarNotASubdwarfPrefix(): void
    {
        // The prefix test is deliberately case-sensitive. If it were not, every S-type
        // star would read its class from index 2 and be miscoloured.
        $renderer = $this->renderer();
        $this->assertSame('S', $this->call($renderer, 'getSpecClass', ['S4,2']));
    }
}
