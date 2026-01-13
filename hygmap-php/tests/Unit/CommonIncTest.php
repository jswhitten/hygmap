<?php
declare(strict_types=1);

namespace HYGMap\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for utility classes (Units, MapGeometry, StarFormatter, RenderingConstants)
 */
class CommonIncTest extends TestCase
{
    protected function setUp(): void
    {
        // Load the classes directly
        require_once HYGMAP_SRC_DIR . '/Units.php';
        require_once HYGMAP_SRC_DIR . '/MapGeometry.php';
        require_once HYGMAP_SRC_DIR . '/StarFormatter.php';
        require_once HYGMAP_SRC_DIR . '/RenderingConstants.php';
    }

    // =========================================================================
    // Unit Conversion Tests (Units class)
    // =========================================================================

    public function testToPcWithParsecs(): void
    {
        $this->assertEqualsWithDelta(10.0, \Units::toParsecs(10.0, 'pc'), 0.0001);
    }

    public function testToPcWithLightYears(): void
    {
        // 10 light years should be approximately 3.066 parsecs
        $result = \Units::toParsecs(10.0, 'ly');
        $expected = 10.0 / \Units::LY_PER_PC;
        $this->assertEqualsWithDelta($expected, $result, 0.0001);
    }

    public function testFromPcWithParsecs(): void
    {
        $this->assertEqualsWithDelta(10.0, \Units::fromParsecs(10.0, 'pc'), 0.0001);
    }

    public function testFromPcWithLightYears(): void
    {
        // 10 parsecs should be approximately 32.6 light years
        $result = \Units::fromParsecs(10.0, 'ly');
        $expected = 10.0 * \Units::LY_PER_PC;
        $this->assertEqualsWithDelta($expected, $result, 0.0001);
    }

    public function testToLyWithLightYears(): void
    {
        $this->assertEqualsWithDelta(10.0, \Units::toLightYears(10.0, 'ly'), 0.0001);
    }

    public function testToLyWithParsecs(): void
    {
        // 10 parsecs should be approximately 32.6 light years
        $result = \Units::toLightYears(10.0, 'pc');
        $expected = 10.0 * \Units::LY_PER_PC;
        $this->assertEqualsWithDelta($expected, $result, 0.0001);
    }

    public function testUnitConversionRoundTrip(): void
    {
        $original = 25.5;

        // Convert ly -> pc -> ly should return original
        $pc = \Units::toParsecs($original, 'ly');
        $ly = \Units::fromParsecs($pc, 'ly');
        $this->assertEqualsWithDelta($original, $ly, 0.0001);
    }

    // =========================================================================
    // Bounding Box Tests (MapGeometry class)
    // =========================================================================

    public function testBuildBoundingBoxWithParsecs(): void
    {
        $bbox = \MapGeometry::buildBoundingBox(0.0, 0.0, 0.0, 25.0, 25.0, 'pc', 'normal');

        $this->assertCount(6, $bbox);
        $this->assertEqualsWithDelta(-25.0, $bbox[0], 0.0001); // xmin
        $this->assertEqualsWithDelta(25.0, $bbox[1], 0.0001);  // xmax
        $this->assertEqualsWithDelta(-50.0, $bbox[2], 0.0001); // ymin (2x for normal mode)
        $this->assertEqualsWithDelta(50.0, $bbox[3], 0.0001);  // ymax
        $this->assertEqualsWithDelta(-25.0, $bbox[4], 0.0001); // zmin
        $this->assertEqualsWithDelta(25.0, $bbox[5], 0.0001);  // zmax
    }

    public function testBuildBoundingBoxWithLightYears(): void
    {
        $bbox = \MapGeometry::buildBoundingBox(0.0, 0.0, 0.0, 25.0, 25.0, 'ly', 'normal');

        // Values should be converted to parsecs
        $expected_zoom = 25.0 / \Units::LY_PER_PC;
        $this->assertEqualsWithDelta(-$expected_zoom, $bbox[0], 0.0001);
        $this->assertEqualsWithDelta($expected_zoom, $bbox[1], 0.0001);
    }

    public function testBuildBoundingBoxStereoMode(): void
    {
        // Stereo mode should have square aspect ratio (1:1 for x and y)
        $bbox = \MapGeometry::buildBoundingBox(0.0, 0.0, 0.0, 25.0, 25.0, 'pc', 'stereo');

        // Y should be same as X in stereo mode (not 2x)
        $this->assertEqualsWithDelta(-25.0, $bbox[2], 0.0001); // ymin
        $this->assertEqualsWithDelta(25.0, $bbox[3], 0.0001);  // ymax
    }

    public function testBuildBoundingBoxLeftMode(): void
    {
        $bbox = \MapGeometry::buildBoundingBox(0.0, 0.0, 0.0, 25.0, 25.0, 'pc', 'left');

        // Should behave same as stereo
        $this->assertEqualsWithDelta(-25.0, $bbox[2], 0.0001);
        $this->assertEqualsWithDelta(25.0, $bbox[3], 0.0001);
    }

    public function testBuildBoundingBoxRightMode(): void
    {
        $bbox = \MapGeometry::buildBoundingBox(0.0, 0.0, 0.0, 25.0, 25.0, 'pc', 'right');

        // Should behave same as stereo
        $this->assertEqualsWithDelta(-25.0, $bbox[2], 0.0001);
        $this->assertEqualsWithDelta(25.0, $bbox[3], 0.0001);
    }

    public function testBuildBoundingBoxWithOffset(): void
    {
        $bbox = \MapGeometry::buildBoundingBox(10.0, 20.0, 5.0, 25.0, 25.0, 'pc', 'normal');

        $this->assertEqualsWithDelta(-15.0, $bbox[0], 0.0001); // xmin = 10 - 25
        $this->assertEqualsWithDelta(35.0, $bbox[1], 0.0001);  // xmax = 10 + 25
        $this->assertEqualsWithDelta(-30.0, $bbox[2], 0.0001); // ymin = 20 - 50
        $this->assertEqualsWithDelta(70.0, $bbox[3], 0.0001);  // ymax = 20 + 50
        $this->assertEqualsWithDelta(-20.0, $bbox[4], 0.0001); // zmin = 5 - 25
        $this->assertEqualsWithDelta(30.0, $bbox[5], 0.0001);  // zmax = 5 + 25
    }

    // =========================================================================
    // Star Display Name Tests (StarFormatter class)
    // =========================================================================

    public function testGetStarDisplayNameWithProperName(): void
    {
        $row = ['proper' => 'Sirius', 'bayer' => 'Alp', 'con' => 'CMa'];
        $name = \StarFormatter::getDisplayName($row);
        $this->assertEquals('Sirius', $name);
    }

    public function testGetStarDisplayNameWithBayer(): void
    {
        $row = ['bayer' => 'Alp', 'con' => 'Cen'];
        $name = \StarFormatter::getDisplayName($row);
        $this->assertEquals('Alp Cen', $name);
    }

    public function testGetStarDisplayNameWithFlamsteed(): void
    {
        $row = ['flam' => '61', 'con' => 'Cyg'];
        $name = \StarFormatter::getDisplayName($row);
        $this->assertEquals('61 Cyg', $name);
    }

    public function testGetStarDisplayNameWithGliese(): void
    {
        $row = ['gj' => '581'];
        $name = \StarFormatter::getDisplayName($row);
        $this->assertEquals('GJ 581', $name);
    }

    public function testGetStarDisplayNameWithHenryDraper(): void
    {
        $row = ['hd' => '48915'];
        $name = \StarFormatter::getDisplayName($row);
        $this->assertEquals('HD 48915', $name);
    }

    public function testGetStarDisplayNameWithHipparcos(): void
    {
        $row = ['hip' => '32349'];
        $name = \StarFormatter::getDisplayName($row);
        $this->assertEquals('HIP 32349', $name);
    }

    public function testGetStarDisplayNameWithGaia(): void
    {
        $row = ['gaia' => '5853498713190525696'];
        $name = \StarFormatter::getDisplayName($row);
        $this->assertEquals('Gaia 5853498713190525696', $name);
    }

    public function testGetStarDisplayNameWithSpectralType(): void
    {
        $row = ['spect' => 'G2V'];
        $name = \StarFormatter::getDisplayName($row);
        $this->assertEquals('G2V', $name);
    }

    public function testGetStarDisplayNameEmpty(): void
    {
        $row = [];
        $name = \StarFormatter::getDisplayName($row);
        $this->assertEquals('', $name);
    }

    public function testGetStarDisplayNamePriority(): void
    {
        // Proper name should take priority over all others
        $row = [
            'proper' => 'Sol',
            'bayer' => 'Alp',
            'con' => 'Cen',
            'gj' => '551',
            'hd' => '128620',
            'hip' => '71681',
        ];
        $name = \StarFormatter::getDisplayName($row);
        $this->assertEquals('Sol', $name);
    }

    public function testGetStarDisplayNameWithFictionalName(): void
    {
        $row = ['name' => 'Vulcan', 'proper' => '40 Eridani'];
        $name = \StarFormatter::getDisplayName($row, fic_names: 1);
        $this->assertEquals('Vulcan', $name);
    }

    public function testGetStarDisplayNameFictionalDisabled(): void
    {
        $row = ['name' => 'Vulcan', 'proper' => '40 Eridani'];
        $name = \StarFormatter::getDisplayName($row, fic_names: 0);
        $this->assertEquals('40 Eridani', $name);
    }

    public function testGetStarDisplayNameWithColor(): void
    {
        $colors = ['white' => 1, 'grey' => 2, 'darkgrey' => 3, 'yellow' => 4, 'black' => 5];
        $row = ['proper' => 'Sirius'];

        $result = \StarFormatter::getDisplayName($row, 0, true, 'normal', 1.0, $colors);

        $this->assertIsArray($result);
        $this->assertEquals('Sirius', $result[0]);
        $this->assertEquals(1, $result[1]); // white color for proper names
    }

    public function testGetStarDisplayNamePrintableMode(): void
    {
        $colors = ['white' => 1, 'grey' => 2, 'darkgrey' => 3, 'yellow' => 4, 'black' => 5];
        $row = ['proper' => 'Sirius'];

        $result = \StarFormatter::getDisplayName($row, 0, true, 'printable', 1.0, $colors);

        $this->assertIsArray($result);
        $this->assertEquals('Sirius', $result[0]);
        $this->assertEquals(5, $result[1]); // black color for printable mode
    }

    // =========================================================================
    // Constants Tests (Units and RenderingConstants classes)
    // =========================================================================

    public function testLyPerPcConstant(): void
    {
        // Verify the conversion constant is approximately correct
        $this->assertEqualsWithDelta(3.26156, \Units::LY_PER_PC, 0.001);
    }

    public function testColorConstants(): void
    {
        $this->assertEquals([255, 255, 255], \RenderingConstants::COLOR_WHITE);
        $this->assertEquals([0, 0, 0], \RenderingConstants::COLOR_BLACK);
        $this->assertEquals([255, 255, 0], \RenderingConstants::COLOR_YELLOW);
    }

    public function testMagnitudeThresholds(): void
    {
        $this->assertIsFloat(\RenderingConstants::MAG_THRESHOLD_DIM);
        $this->assertIsFloat(\RenderingConstants::MAG_THRESHOLD_BRIGHT);
        $this->assertGreaterThan(\RenderingConstants::MAG_THRESHOLD_BRIGHT, \RenderingConstants::MAG_THRESHOLD_DIM);
    }
}
