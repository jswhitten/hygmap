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
}
