<?php
declare(strict_types=1);

namespace HYGMap\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Tests that map.php actually draws a map.
 *
 * Why this file exists
 * --------------------
 * Nothing exercised MapRenderer at all. CI asserted that `map.php` returns HTTP 200,
 * which proves the GD calls did not throw — it does not prove a star was drawn, or
 * drawn in the right place. An audit on 2026-07-29 named this as one of the claims
 * resting on the weakest evidence: the map is the product, and a blank map is a 200.
 *
 * MapRenderer::render() fetches its own data from the API, so it cannot be unit tested
 * without a seam (REPO-HYGIENE has that task). These tests therefore drive the real
 * endpoint over HTTP and reason about the returned image.
 *
 * The test container has no GD, so pixels cannot be read directly. Instead the tests
 * compare renders that MUST differ if drawing is happening at all — a renderer that
 * silently stopped plotting stars would return near-identical images for a dense and an
 * empty sky, and that is exactly the regression worth catching.
 *
 * Note on parameters: only position and zoom reach the renderer through the query
 * string. Everything else (image_size, m_limit, unit, grid, image_type) is session
 * configuration written by configure.php. That is why these tests vary the view rather
 * than the settings, and it is worth knowing before adding a test here.
 *
 * Requires the stack to be up: `docker compose up -d`, then `make test-integration`.
 */
class MapRenderingTest extends TestCase
{
    private const PHP_BASE = 'http://hygmap-php';

    /**
     * Fetch a map image.
     *
     * @param array<string, string|int|float> $overrides
     * @return array{status:int, body:string, contentType:string}
     */
    private function fetchMap(array $overrides = []): array
    {
        // Only position and zoom are query parameters. image_size, m_limit, unit, grid
        // and the rest are session configuration written by configure.php, so passing
        // them here would do nothing -- see Request::getMapParams() and Config::DEFAULTS.
        $params = array_merge([
            'x_c' => 0,
            'y_c' => 0,
            'z_c' => 0,
            'xy_zoom' => 25,
            'z_zoom' => 25,
        ], $overrides);

        $url = self::PHP_BASE . '/map.php?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        if ($body === false) {
            $this->markTestSkipped('PHP app not reachable at ' . self::PHP_BASE . ': ' . curl_error($ch));
        }

        return ['status' => $status, 'body' => (string)$body, 'contentType' => $contentType];
    }

    public function testMapEndpointReturnsAnImage(): void
    {
        $response = $this->fetchMap();

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString(
            'image/',
            $response['contentType'],
            'map.php must return an image, not an HTML error page with a 200 status'
        );
    }

    public function testRenderedImageHasTheConfiguredDimensions(): void
    {
        // image_size is session configuration set through configure.php, NOT a query
        // parameter -- bootstrap.php takes only position and zoom from the query string
        // (Request::getMapParams()). So this asserts the documented default of 600 from
        // Config::DEFAULTS. Note that CI's smoke test passes image_size=100 in the URL,
        // which has no effect; it is harmless but proves less than it appears to.
        $response = $this->fetchMap();

        $info = getimagesizefromstring($response['body']);

        $this->assertNotFalse($info, 'Response body is not a decodable image');
        $this->assertSame(600, $info[1], 'Rendered height should be the configured default of 600');
        // Non-stereo maps are rendered as two panels side by side.
        $this->assertContains(
            $info[0],
            [600, 1200],
            'Rendered width should be image_size or twice it, got ' . $info[0]
        );
    }

    public function testStarDensityIsVisibleInTheRender(): void
    {
        // Proving "a star was drawn" without GD in the test container takes an indirect
        // route: render three regions whose real star counts differ by orders of
        // magnitude, and require the encoded images to grow with them. A renderer that
        // silently stopped plotting stars would return three near-identical grids --
        // and all three would still be HTTP 200, which is why the CI smoke test never
        // noticed.
        //
        // Measured 2026-07-29: ~74KB at Sol (5000+ stars), ~48KB at 500pc (88 stars),
        // ~27KB at 3000pc (0 stars). The thresholds below are deliberately loose; the
        // ordering is the assertion, not the byte counts.
        $dense = $this->fetchMap(['x_c' => 0, 'y_c' => 0]);
        $sparse = $this->fetchMap(['x_c' => 500, 'y_c' => 500]);
        $empty = $this->fetchMap(['x_c' => 3000, 'y_c' => 3000]);

        foreach ([$dense, $sparse, $empty] as $response) {
            $this->assertSame(200, $response['status']);
        }

        $this->assertGreaterThan(
            strlen($empty['body']),
            strlen($sparse['body']),
            'A region with stars encoded no larger than an empty one; stars may not be drawn'
        );
        $this->assertGreaterThan(
            strlen($sparse['body']),
            strlen($dense['body']),
            'The solar neighbourhood encoded no larger than a sparse region 500pc out'
        );
    }

    public function testPanningChangesTheView(): void
    {
        // Two different regions of the sky must not render identically. This catches a
        // renderer that ignores its centre coordinates -- the map would look plausible
        // and always show the same thing.
        $atSol = $this->fetchMap(['x_c' => 0, 'y_c' => 0, 'z_c' => 0]);
        $farAway = $this->fetchMap(['x_c' => 200, 'y_c' => 200, 'z_c' => 0]);

        $this->assertNotSame(
            $atSol['body'],
            $farAway['body'],
            'Panning 200pc away produced an identical image; the view centre is being ignored'
        );
    }

    public function testZoomChangesTheView(): void
    {
        $wide = $this->fetchMap(['xy_zoom' => 100]);
        $tight = $this->fetchMap(['xy_zoom' => 5]);

        $this->assertNotSame(
            $wide['body'],
            $tight['body'],
            'Changing the zoom produced an identical image; the zoom parameter is being ignored'
        );
    }
}
