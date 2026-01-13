<?php
declare(strict_types=1);

namespace HYGMap\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for Config, Csrf, and Request classes
 */
class ConfigCsrfRequestTest extends TestCase
{
    protected function setUp(): void
    {
        // Load the classes
        require_once HYGMAP_SRC_DIR . '/Config.php';
        require_once HYGMAP_SRC_DIR . '/Csrf.php';
        require_once HYGMAP_SRC_DIR . '/Request.php';

        // Initialize session superglobal for testing
        $_SESSION = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET = [];
    }

    // =========================================================================
    // Config Tests
    // =========================================================================

    public function testConfigGetDefaultsReturnsArray(): void
    {
        $defaults = \Config::getDefaults();

        $this->assertIsArray($defaults);
        $this->assertArrayHasKey('unit', $defaults);
        $this->assertArrayHasKey('grid', $defaults);
        $this->assertArrayHasKey('image_type', $defaults);
        $this->assertArrayHasKey('image_size', $defaults);
        $this->assertArrayHasKey('m_limit', $defaults);
    }

    public function testConfigDefaultValues(): void
    {
        $defaults = \Config::getDefaults();

        $this->assertEquals('ly', $defaults['unit']);
        $this->assertEquals(20, $defaults['grid']);
        $this->assertEquals('normal', $defaults['image_type']);
        $this->assertEquals(600, $defaults['image_size']);
        $this->assertEquals(20.0, $defaults['m_limit']);
        $this->assertEquals(8.0, $defaults['m_limit_label']);
    }

    public function testConfigLoadReturnsDefaultsWhenSessionEmpty(): void
    {
        $_SESSION = [];
        $cfg = \Config::load();

        $this->assertEquals(\Config::getDefaults(), $cfg);
    }

    public function testConfigLoadReturnsSessionValues(): void
    {
        $_SESSION['cfg'] = ['unit' => 'pc', 'grid' => 50];
        $cfg = \Config::load();

        $this->assertEquals('pc', $cfg['unit']);
        $this->assertEquals(50, $cfg['grid']);
    }

    public function testConfigSaveMergesWithExisting(): void
    {
        $_SESSION['cfg'] = ['unit' => 'pc', 'grid' => 50];

        \Config::save(['grid' => 100, 'image_size' => 800]);

        $cfg = \Config::load();
        $this->assertEquals('pc', $cfg['unit']); // Preserved
        $this->assertEquals(100, $cfg['grid']); // Updated
        $this->assertEquals(800, $cfg['image_size']); // Added
    }

    public function testConfigGetReturnsSpecificValue(): void
    {
        $_SESSION['cfg'] = ['unit' => 'pc'];

        $this->assertEquals('pc', \Config::get('unit'));
    }

    public function testConfigGetReturnsDefaultForMissingKey(): void
    {
        $_SESSION['cfg'] = [];

        // Should return the class default
        $this->assertEquals('ly', \Config::get('unit'));
    }

    public function testConfigGetReturnsCustomDefaultForMissingKey(): void
    {
        $_SESSION['cfg'] = [];

        $this->assertEquals('custom', \Config::get('nonexistent', 'custom'));
    }

    // =========================================================================
    // Csrf Tests
    // =========================================================================

    public function testCsrfInitGeneratesToken(): void
    {
        $_SESSION = [];
        \Csrf::init();

        $token = \Csrf::token();
        $this->assertNotEmpty($token);
        $this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex chars
    }

    public function testCsrfInitPreservesExistingToken(): void
    {
        $_SESSION = ['csrf_token' => 'existing_token'];
        \Csrf::init();

        $this->assertEquals('existing_token', \Csrf::token());
    }

    public function testCsrfTokenReturnsEmptyWhenNotInitialized(): void
    {
        $_SESSION = [];

        $this->assertEquals('', \Csrf::token());
    }

    public function testCsrfValidateReturnsTrueForValidToken(): void
    {
        \Csrf::init();
        $token = \Csrf::token();

        $this->assertTrue(\Csrf::validate($token));
    }

    public function testCsrfValidateReturnsFalseForInvalidToken(): void
    {
        \Csrf::init();

        $this->assertFalse(\Csrf::validate('wrong_token'));
    }

    public function testCsrfValidateReturnsFalseForEmptyToken(): void
    {
        \Csrf::init();

        $this->assertFalse(\Csrf::validate(''));
    }

    public function testCsrfValidateReturnsFalseWhenNoTokenStored(): void
    {
        $_SESSION = [];

        $this->assertFalse(\Csrf::validate('any_token'));
    }

    public function testCsrfRegenerateCreatesNewToken(): void
    {
        \Csrf::init();
        $oldToken = \Csrf::token();

        \Csrf::regenerate();
        $newToken = \Csrf::token();

        $this->assertNotEquals($oldToken, $newToken);
        $this->assertEquals(64, strlen($newToken));
    }

    public function testCsrfRegenerateInvalidatesOldToken(): void
    {
        \Csrf::init();
        $oldToken = \Csrf::token();

        \Csrf::regenerate();

        $this->assertFalse(\Csrf::validate($oldToken));
    }

    // =========================================================================
    // Request Tests
    // =========================================================================

    public function testRequestGetMapParamsReturnsDefaults(): void
    {
        $_GET = [];
        $params = \Request::getMapParams();

        $this->assertEquals(0, $params['select_star']);
        $this->assertEquals(0, $params['select_center']);
        $this->assertEquals(0.0, $params['x_c']);
        $this->assertEquals(0.0, $params['y_c']);
        $this->assertEquals(0.0, $params['z_c']);
        $this->assertEquals(25.0, $params['xy_zoom']);
        $this->assertEquals(25.0, $params['z_zoom']);
        $this->assertEquals('', $params['image_side']);
    }

    public function testRequestGetMapParamsValidatesImageSide(): void
    {
        // Note: We can't easily test filter_input_array in unit tests
        // because it reads from actual PHP input, not $_GET
        // These tests verify the default behavior
        $params = \Request::getMapParams();

        // Invalid image_side values should be normalized to empty string
        $this->assertEquals('', $params['image_side']);
    }

    public function testRequestGetMapParamsHasExpectedKeys(): void
    {
        $params = \Request::getMapParams();

        $this->assertArrayHasKey('select_star', $params);
        $this->assertArrayHasKey('select_center', $params);
        $this->assertArrayHasKey('x_c', $params);
        $this->assertArrayHasKey('y_c', $params);
        $this->assertArrayHasKey('z_c', $params);
        $this->assertArrayHasKey('xy_zoom', $params);
        $this->assertArrayHasKey('z_zoom', $params);
        $this->assertArrayHasKey('image_side', $params);
    }

    public function testRequestGetMapParamsReturnsCorrectTypes(): void
    {
        $params = \Request::getMapParams();

        $this->assertIsInt($params['select_star']);
        $this->assertIsInt($params['select_center']);
        $this->assertIsFloat($params['x_c']);
        $this->assertIsFloat($params['y_c']);
        $this->assertIsFloat($params['z_c']);
        $this->assertIsFloat($params['xy_zoom']);
        $this->assertIsFloat($params['z_zoom']);
        $this->assertIsString($params['image_side']);
    }
}
