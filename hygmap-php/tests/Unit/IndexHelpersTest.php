<?php
declare(strict_types=1);

namespace HYGMap\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for IndexHelpers.php functions
 */
class IndexHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        // Load IndexHelpers which includes its own dependencies
        require_once HYGMAP_SRC_DIR . '/IndexHelpers.php';
    }

    // =========================================================================
    // buildSelectedStarData Tests
    // =========================================================================

    public function testBuildSelectedStarDataWithNull(): void
    {
        $result = buildSelectedStarData(null, 0, 'pc');

        $this->assertFalse($result['has_star']);
        $this->assertStringContainsString('No star selected', $result['html']);
    }

    public function testBuildSelectedStarDataWithStar(): void
    {
        $star = [
            'proper' => 'Sol',
            'absmag' => 4.83,
            'spect' => 'G2V',
            'dist' => 0.0,
            'x' => 0.0,
            'y' => 0.0,
            'z' => 0.0,
            'mag' => -26.74,
            'ra' => 0.0,
            'dec' => 0.0,
            'name' => '',
            'bf' => '',
        ];

        $result = buildSelectedStarData($star, 0, 'pc');

        $this->assertTrue($result['has_star']);
        $this->assertStringContainsString('Sol', $result['display_name']);
        $this->assertEquals(4.83, $result['absmag']);
        $this->assertEquals('G2V', $result['spect']);
        $this->assertEquals('pc', $result['unit']);
    }

    public function testBuildSelectedStarDataWithFictionalName(): void
    {
        $star = [
            'proper' => 'Epsilon Eridani',
            'absmag' => 6.19,
            'spect' => 'K2V',
            'dist' => 3.22,
            'x' => -1.0,
            'y' => 2.0,
            'z' => 0.5,
            'mag' => 3.73,
            'ra' => 3.55,
            'dec' => -9.46,
            'name' => 'Vulcan',
            'bf' => '',
        ];

        $result = buildSelectedStarData($star, 1, 'pc');

        $this->assertTrue($result['has_star']);
        $this->assertStringContainsString('Vulcan', $result['display_name']);
        $this->assertNotEmpty($result['memory_alpha']);
    }

    /**
     * Regression: the info panel used to pass a hardcoded 0 for $fic_names, so with a
     * naming layer on it led with the catalog name -- "Epsilon Eridani (Vulcan)" -- while
     * the map overlay, the star table and the API all led with "Vulcan". One page load,
     * four different names for one star.
     *
     * The canonical rule is that a fictional name wins outright. Asserting only that
     * "Vulcan" appears somewhere does not catch this; the order is the whole bug.
     */
    public function testSelectedStarPanelLeadsWithTheFictionalName(): void
    {
        $star = [
            'proper' => 'Epsilon Eridani',
            'absmag' => 6.19,
            'spect' => 'K2V',
            'dist' => 3.22,
            'x' => -1.0,
            'y' => 2.0,
            'z' => 0.5,
            'mag' => 3.73,
            'ra' => 3.55,
            'dec' => -9.46,
            'name' => 'Vulcan',
            'bf' => '',
        ];

        $withLayer = buildSelectedStarData($star, 1, 'pc');

        $vulcanAt = strpos(strip_tags($withLayer['display_name']), 'Vulcan');
        $catalogAt = strpos(strip_tags($withLayer['display_name']), 'Epsilon Eridani');
        $this->assertNotFalse($vulcanAt);
        $this->assertNotFalse($catalogAt, 'the catalog name is still worth showing as context');
        $this->assertLessThan($catalogAt, $vulcanAt, 'the fictional name must come first');

        // The catalog name is what Wikipedia knows -- the link must not point at "Vulcan".
        $this->assertStringContainsString('wikipedia', $withLayer['display_name']);
        $this->assertStringContainsString('Epsilon+Eridani', $withLayer['display_name']);

        // With no layer on, nothing fictional leaks in.
        $withoutLayer = buildSelectedStarData($star, 0, 'pc');
        $this->assertStringNotContainsString('Vulcan', $withoutLayer['display_name']);
        $this->assertEmpty($withoutLayer['memory_alpha']);
    }

    public function testBuildSelectedStarDataUnitConversion(): void
    {
        $star = [
            'proper' => 'Test Star',
            'absmag' => 5.0,
            'spect' => 'G0V',
            'dist' => 10.0, // 10 parsecs
            'x' => 5.0,
            'y' => 5.0,
            'z' => 0.0,
            'mag' => 5.0,
            'ra' => 12.0,
            'dec' => 45.0,
            'name' => '',
            'bf' => '',
        ];

        // With parsecs
        $resultPc = buildSelectedStarData($star, 0, 'pc');
        $this->assertEquals('pc', $resultPc['unit']);

        // With light years - distance should be converted
        $resultLy = buildSelectedStarData($star, 0, 'ly');
        $this->assertEquals('ly', $resultLy['unit']);

        // Distance in ly should be larger than in pc (by factor of ~3.26)
        $distPc = (float)str_replace(',', '', $resultPc['distance_ui']);
        $distLy = (float)str_replace(',', '', $resultLy['distance_ui']);
        $this->assertGreaterThan($distPc, $distLy);
    }

    public function testBuildSelectedStarDataCalculatesRaDeg(): void
    {
        $star = [
            'proper' => 'Test',
            'absmag' => 5.0,
            'spect' => 'G0V',
            'dist' => 10.0,
            'x' => 5.0,
            'y' => 5.0,
            'z' => 0.0,
            'mag' => 5.0,
            'ra' => 6.0, // 6 hours
            'dec' => 30.0,
            'name' => '',
            'bf' => '',
        ];

        $result = buildSelectedStarData($star, 0, 'pc');

        // 6 hours = 90 degrees (6 * 360 / 24)
        $this->assertEquals(90.0, $result['selected_ra_deg']);
    }

    public function testBuildSelectedStarDataDeclinationSign(): void
    {
        // Test positive declination
        $starNorth = [
            'proper' => 'North Star',
            'absmag' => 5.0,
            'spect' => 'G0V',
            'dist' => 10.0,
            'x' => 0.0,
            'y' => 0.0,
            'z' => 0.0,
            'mag' => 5.0,
            'ra' => 0.0,
            'dec' => 45.0,
            'name' => '',
            'bf' => '',
        ];

        $resultNorth = buildSelectedStarData($starNorth, 0, 'pc');
        $this->assertEquals('North', $resultNorth['selected_dec_ns']);
        $this->assertEquals(45.0, $resultNorth['selected_dec_av']);

        // Test negative declination
        $starSouth = [
            'proper' => 'South Star',
            'absmag' => 5.0,
            'spect' => 'G0V',
            'dist' => 10.0,
            'x' => 0.0,
            'y' => 0.0,
            'z' => 0.0,
            'mag' => 5.0,
            'ra' => 0.0,
            'dec' => -30.0,
            'name' => '',
            'bf' => '',
        ];

        $resultSouth = buildSelectedStarData($starSouth, 0, 'pc');
        $this->assertEquals('South', $resultSouth['selected_dec_ns']);
        $this->assertEquals(30.0, $resultSouth['selected_dec_av']); // Absolute value
    }

    // =========================================================================
    // buildMapHtml Tests
    // =========================================================================

    public function testBuildMapHtmlNormalMode(): void
    {
        $params = [
            'x_c' => 0.0,
            'y_c' => 0.0,
            'z_c' => 0.0,
            'xy_zoom' => 25.0,
            'z_zoom' => 25.0,
            'm_limit' => 10.0,
            'm_limit_label' => 8.0,
            'select_star' => 0,
            'image_size' => 600,
            'max_line' => 0,
        ];

        $html = buildMapHtml('normal', 600, $params);

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('map.php?', $html);
        $this->assertStringContainsString('width="1200"', $html); // 2x for normal mode
        $this->assertStringContainsString('height="600"', $html);
        $this->assertStringContainsString('alt="Star map', $html);
    }

    public function testBuildMapHtmlStereoMode(): void
    {
        $params = [
            'x_c' => 0.0,
            'y_c' => 0.0,
            'z_c' => 0.0,
            'xy_zoom' => 25.0,
            'z_zoom' => 25.0,
            'm_limit' => 10.0,
            'm_limit_label' => 8.0,
            'select_star' => 0,
            'image_size' => 300,
            'max_line' => 0,
        ];

        $html = buildMapHtml('stereo', 300, $params);

        // Should have two images for stereo mode
        $this->assertEquals(2, substr_count($html, '<img'));
        $this->assertStringContainsString('image_side=left', $html);
        $this->assertStringContainsString('image_side=right', $html);
        $this->assertStringContainsString('left eye', $html);
        $this->assertStringContainsString('right eye', $html);
    }

    public function testBuildMapHtmlIncludesAltText(): void
    {
        $params = [
            'x_c' => 10.5,
            'y_c' => -5.2,
            'z_c' => 3.0,
            'xy_zoom' => 50.0,
            'z_zoom' => 25.0,
            'm_limit' => 10.0,
            'm_limit_label' => 8.0,
            'select_star' => 0,
            'image_size' => 600,
            'max_line' => 0,
        ];

        $html = buildMapHtml('normal', 600, $params);

        $this->assertStringContainsString('alt="Star map centered at', $html);
        $this->assertStringContainsString('X:10.5', $html);
        $this->assertStringContainsString('Y:-5.2', $html);
        $this->assertStringContainsString('Z:3.0', $html);
    }

    public function testBuildMapHtmlEscapesParameters(): void
    {
        $params = [
            'x_c' => 0.0,
            'y_c' => 0.0,
            'z_c' => 0.0,
            'xy_zoom' => 25.0,
            'z_zoom' => 25.0,
            'm_limit' => 10.0,
            'm_limit_label' => 8.0,
            'select_star' => 0,
            'image_size' => 600,
            'max_line' => 0,
        ];

        $html = buildMapHtml('normal', 600, $params);

        // Should not contain unescaped special characters in src
        $this->assertStringNotContainsString('&"', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    // =========================================================================
    // buildStarImageMap - the no-JavaScript click-to-select path
    // =========================================================================

    /** @return array<int, array<string, mixed>> */
    private function imageMapStars(): array
    {
        return [
            ['id' => 1, 'name' => 'Sirius', 'sx' => 100.4, 'sy' => 200.6, 'absmag' => 1.4],
            ['id' => 2, 'name' => 'Vega', 'sx' => 50.0, 'sy' => 75.0, 'absmag' => 0.6],
            ['id' => 3, 'name' => 'Faint One', 'sx' => 10.0, 'sy' => 10.0, 'absmag' => 14.0],
        ];
    }

    public function testBuildStarImageMapEmitsAnAreaPerLabelledStar(): void
    {
        $html = \IndexHelpers::buildStarImageMap($this->imageMapStars(), 8.0);

        $this->assertStringContainsString('<map name="starmap">', $html);
        $this->assertSame(2, substr_count($html, '<area '), 'Expected one area per labelled star');
        $this->assertStringContainsString('href="?select_star=1&amp;select_center=1"', $html);
        $this->assertStringContainsString('href="?select_star=2&amp;select_center=1"', $html);
    }

    public function testBuildStarImageMapSkipsStarsTooDimToBeLabelled(): void
    {
        // Emitting an area for every rendered star cost 10,000 elements and 1.3MB of
        // HTML at wide zoom. Only labelled stars are clickable without JS.
        $html = \IndexHelpers::buildStarImageMap($this->imageMapStars(), 8.0);

        $this->assertStringNotContainsString('select_star=3', $html);
        $this->assertStringNotContainsString('Faint One', $html);
    }

    public function testBuildStarImageMapRoundsCoordinatesAndUsesTheHitRadius(): void
    {
        $html = \IndexHelpers::buildStarImageMap($this->imageMapStars(), 8.0);

        // 100.4, 200.6 -> 100, 201; radius matches HOVER_RADIUS in map-interactive.js
        $this->assertStringContainsString('coords="100,201,15"', $html);
    }

    public function testBuildStarImageMapEscapesStarNames(): void
    {
        // Star names come from third-party catalogs, so they are untrusted here.
        $stars = [['id' => 9, 'name' => '<script>alert(1)</script>', 'sx' => 1, 'sy' => 2, 'absmag' => 1.0]];
        $html = \IndexHelpers::buildStarImageMap($stars, 8.0);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testBuildStarImageMapReturnsEmptyStringWhenNothingQualifies(): void
    {
        $this->assertSame('', \IndexHelpers::buildStarImageMap([], 8.0));
        // All stars dimmer than the label limit: no map element at all, so the img gets
        // no usemap attribute pointing at nothing.
        $this->assertSame('', \IndexHelpers::buildStarImageMap($this->imageMapStars(), -5.0));
    }

    public function testBuildStarImageMapCapsTheNumberOfAreas(): void
    {
        $stars = [];
        for ($i = 1; $i <= 2000; $i++) {
            $stars[] = ['id' => $i, 'name' => "S{$i}", 'sx' => $i % 500, 'sy' => $i % 400, 'absmag' => 1.0];
        }

        $html = \IndexHelpers::buildStarImageMap($stars, 8.0);

        $this->assertSame(750, substr_count($html, '<area '), 'The hard cap on areas is not being applied');
    }

    public function testBuildMapHtmlAddsUsemapOnlyWhenAsked(): void
    {
        $params = ['x_c' => 0, 'y_c' => 0, 'z_c' => 0, 'xy_zoom' => 25];

        $without = \IndexHelpers::buildMapHtml('normal', 600, $params);
        $with = \IndexHelpers::buildMapHtml('normal', 600, $params, 'starmap');

        $this->assertStringNotContainsString('usemap', $without);
        $this->assertStringContainsString('usemap="#starmap"', $with);
    }
}
