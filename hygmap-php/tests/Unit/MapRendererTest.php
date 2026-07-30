<?php
declare(strict_types=1);

namespace HYGMap\Tests\Unit;

use PHPUnit\Framework\TestCase;
use MapRenderer;

/**
 * Tests for MapRenderer's non-drawing behaviour.
 *
 * This class had no unit tests at all, because render() called ApiClient::instance()
 * internally — it could not be constructed and exercised without a live API, so nothing
 * tried. REPO-HYGIENE moved fetching to the caller, which makes this possible.
 *
 * The drawing itself still cannot be unit tested here: the composer image has no GD
 * extension (verified — `extension_loaded('gd')` is false). Pixel-level behaviour is covered
 * over HTTP in tests/Integration/MapRenderingTest.php instead. What is testable in-process is
 * the geometry the renderer hands its caller, which is exactly the part a caller now depends
 * on.
 */
class MapRendererTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $cfg = [
        'unit' => 'pc',
        'grid' => 10,
        'fic_names' => 0,
        'image_type' => 'normal',
        'image_size' => 600,
        'max_line' => 0,
        'm_limit' => 20.0,
        'm_limit_label' => 8.0,
        'show_signals' => true,
    ];

    /** @var array<string, mixed> */
    private array $vars = [
        'select_star' => 0,
        'x_c' => 0.0,
        'y_c' => 0.0,
        'z_c' => 0.0,
        'xy_zoom' => 25.0,
        'z_zoom' => 25.0,
        'image_side' => '',
    ];

    protected function setUp(): void
    {
        require_once HYGMAP_SRC_DIR . '/MapRenderer.php';
    }

    /** @param array<string, mixed> $overrides */
    private function renderer(array $overrides = [], array $cfgOverrides = []): MapRenderer
    {
        return new MapRenderer(
            array_merge($this->cfg, $cfgOverrides),
            array_merge($this->vars, $overrides)
        );
    }

    public function testCanBeConstructedWithoutAnApi(): void
    {
        // The point of the change: constructing this used to imply a live API.
        $this->assertInstanceOf(MapRenderer::class, $this->renderer());
    }

    public function testBoundingBoxIsExposedForTheCallerToFetchWith(): void
    {
        $bbox = $this->renderer()->buildBoundingBox();

        $this->assertCount(6, $bbox, 'bbox must be [xmin, xmax, ymin, ymax, zmin, zmax]');
        foreach ($bbox as $value) {
            $this->assertIsFloat($value);
        }
    }

    public function testBoundingBoxIsOrderedMinBeforeMax(): void
    {
        // The API rejects an inverted range, so this is the caller's contract too.
        [$xmin, $xmax, $ymin, $ymax, $zmin, $zmax] = $this->renderer()->buildBoundingBox();

        $this->assertLessThan($xmax, $xmin);
        $this->assertLessThan($ymax, $ymin);
        $this->assertLessThan($zmax, $zmin);
    }

    public function testBoundingBoxIsCentredOnTheView(): void
    {
        [$xmin, $xmax, $ymin, $ymax] = $this->renderer(['x_c' => 100.0, 'y_c' => -50.0])
            ->buildBoundingBox();

        $this->assertEqualsWithDelta(100.0, ($xmin + $xmax) / 2, 0.001);
        $this->assertEqualsWithDelta(-50.0, ($ymin + $ymax) / 2, 0.001);
    }

    public function testZoomingInNarrowsTheBoundingBox(): void
    {
        $wide = $this->renderer(['xy_zoom' => 100.0])->buildBoundingBox();
        $tight = $this->renderer(['xy_zoom' => 5.0])->buildBoundingBox();

        $this->assertLessThan($wide[1] - $wide[0], $tight[1] - $tight[0]);
    }

    public function testLightYearConfigProducesASmallerParsecBox(): void
    {
        // The bbox goes to the API, which works in parsecs, so a view measured in light-years
        // must cover fewer parsecs than the same number of parsecs would.
        $inParsecs = $this->renderer()->buildBoundingBox();
        $inLightYears = $this->renderer([], ['unit' => 'ly'])->buildBoundingBox();

        $this->assertLessThan(
            $inParsecs[1] - $inParsecs[0],
            $inLightYears[1] - $inLightYears[0],
            'a light-year-denominated view spans fewer parsecs'
        );
    }
}
