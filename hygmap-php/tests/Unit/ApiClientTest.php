<?php
declare(strict_types=1);

namespace HYGMap\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ApiClient;

/**
 * Tests for ApiClient.php
 *
 * Note: These tests focus on the public interface. Integration tests
 * should be run separately with a real API server.
 */
class ApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        require_once HYGMAP_SRC_DIR . '/ApiClient.php';
        // Reset singleton between tests
        ApiClient::resetInstance();
    }

    protected function tearDown(): void
    {
        ApiClient::resetInstance();
    }

    // =========================================================================
    // Constructor and Singleton Tests
    // =========================================================================

    public function testConstructorWithDefaults(): void
    {
        $client = new ApiClient();
        $this->assertInstanceOf(ApiClient::class, $client);
    }

    public function testConstructorWithCustomUrl(): void
    {
        $client = new ApiClient('http://custom-api:9000', 60);
        $this->assertInstanceOf(ApiClient::class, $client);
    }

    public function testSingletonInstance(): void
    {
        $instance1 = ApiClient::instance();
        $instance2 = ApiClient::instance();
        $this->assertSame($instance1, $instance2);
    }

    public function testResetInstance(): void
    {
        $instance1 = ApiClient::instance();
        ApiClient::resetInstance();
        $instance2 = ApiClient::instance();
        $this->assertNotSame($instance1, $instance2);
    }

    // =========================================================================
    // Method Signature Tests (verify methods exist with correct signatures)
    // =========================================================================

    public function testQueryAllMethodExists(): void
    {
        $client = new ApiClient();
        $this->assertTrue(method_exists($client, 'queryAll'));

        $reflection = new \ReflectionMethod($client, 'queryAll');
        $params = $reflection->getParameters();

        $this->assertEquals('bbox', $params[0]->getName());
        $this->assertEquals('magLimit', $params[1]->getName());
        $this->assertEquals('world_id', $params[2]->getName());
        $this->assertEquals('order', $params[3]->getName());
    }

    public function testQueryStarMethodExists(): void
    {
        $client = new ApiClient();
        $this->assertTrue(method_exists($client, 'queryStar'));

        $reflection = new \ReflectionMethod($client, 'queryStar');
        $params = $reflection->getParameters();

        $this->assertEquals('id', $params[0]->getName());
        $this->assertEquals('world_id', $params[1]->getName());
    }

    public function testQuerySignalsMethodExists(): void
    {
        $client = new ApiClient();
        $this->assertTrue(method_exists($client, 'querySignals'));
    }

    public function testSearchStarMethodExists(): void
    {
        $client = new ApiClient();
        $this->assertTrue(method_exists($client, 'searchStar'));
    }

    public function testQueryFictionMethodExists(): void
    {
        $client = new ApiClient();
        $this->assertTrue(method_exists($client, 'queryFiction'));
    }

    public function testQueryProperNamesMethodExists(): void
    {
        $client = new ApiClient();
        $this->assertTrue(method_exists($client, 'queryProperNames'));
    }

    public function testQueryWorldsMethodExists(): void
    {
        $client = new ApiClient();
        $this->assertTrue(method_exists($client, 'queryWorlds'));
    }

    // =========================================================================
    // Default Parameter Tests
    // =========================================================================

    public function testQueryAllDefaultParameters(): void
    {
        $reflection = new \ReflectionMethod(ApiClient::class, 'queryAll');
        $params = $reflection->getParameters();

        // world_id defaults to 0
        $this->assertTrue($params[2]->isDefaultValueAvailable());
        $this->assertEquals(0, $params[2]->getDefaultValue());

        // order defaults to 'absmag asc'
        $this->assertTrue($params[3]->isDefaultValueAvailable());
        $this->assertEquals('absmag asc', $params[3]->getDefaultValue());
    }

    public function testQuerySignalsDefaultOrder(): void
    {
        $reflection = new \ReflectionMethod(ApiClient::class, 'querySignals');
        $params = $reflection->getParameters();

        // order defaults to 'time desc'
        $this->assertTrue($params[1]->isDefaultValueAvailable());
        $this->assertEquals('time desc', $params[1]->getDefaultValue());
    }

    public function testQueryFictionDefaultWorldId(): void
    {
        $reflection = new \ReflectionMethod(ApiClient::class, 'queryFiction');
        $params = $reflection->getParameters();

        // world_id is nullable with default null
        $this->assertTrue($params[0]->isDefaultValueAvailable());
        $this->assertNull($params[0]->getDefaultValue());
    }
}
