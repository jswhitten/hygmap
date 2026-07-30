<?php
declare(strict_types=1);

namespace HYGMap\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The classic UI must report failure with a failure status.
 *
 * An audit on 2026-07-29 found that an API outage and a bad star id produced the same
 * generic message with an HTTP **200**. Consequences: a user who mistyped an id was told
 * the system was broken, a user hitting a real outage was given no reason to retry, and
 * anything non-human — monitors, crawlers, caches — recorded a failed page as content.
 *
 * These tests drive the real PHP app over HTTP. The outage half cannot be induced from
 * here without stopping a container, so it is verified manually and recorded in the
 * feature file; what is asserted here is everything reachable with the stack healthy.
 *
 * Requires the stack to be up: `docker compose up -d`, then `make test-integration`.
 */
class ErrorResponseTest extends TestCase
{
    private const PHP_BASE = 'http://hygmap-php';

    /** @return array{status:int, body:string} */
    private function fetch(string $path): array
    {
        $ch = curl_init(self::PHP_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false) {
            $this->markTestSkipped('PHP app not reachable: ' . curl_error($ch));
        }

        return ['status' => $status, 'body' => (string)$body];
    }

    public function testUnknownStarIdReturns404(): void
    {
        $response = $this->fetch('/?select_star=999999999');

        $this->assertSame(
            404,
            $response['status'],
            'An unknown star id must be a 404, not a 200 with an error message in the body'
        );
    }

    public function testUnknownStarIdSaysWhatIsActuallyWrong(): void
    {
        $response = $this->fetch('/?select_star=999999999');

        $this->assertStringContainsString('No star with id 999999999', $response['body']);
        // Star ids come from the source catalog and change between releases, so a stale
        // bookmark is the most likely cause. Say so rather than blaming the service.
        $this->assertStringContainsString('stale link', $response['body']);
        $this->assertStringNotContainsString(
            'not responding',
            $response['body'],
            'A missing star must not be reported as a service failure'
        );
    }

    public function testAValidStarIsStillA200(): void
    {
        // Guard against over-correcting: only an unknown id is a 404. Star id 1 is Sol.
        $response = $this->fetch('/?select_star=1&select_center=1');

        $this->assertSame(200, $response['status'], 'A real star id must still return 200');
    }

    public function testAnEmptyRegionIsNotAnError(): void
    {
        // A bounding box with no stars is a valid, successful answer -- not a 404.
        $response = $this->fetch('/?x_c=2500&y_c=2500&z_c=0&xy_zoom=1&z_zoom=1');

        $this->assertSame(200, $response['status'], 'An empty sky is a successful page');
    }

    public function testNoJsImageMapIsPresent(): void
    {
        // Clicking a star used to require JavaScript, with no fallback. The image map is
        // the no-JS path: it works with scripting disabled because the browser resolves
        // <area href> natively.
        $response = $this->fetch('/?x_c=0&y_c=0&z_c=0&xy_zoom=25&z_zoom=25');

        $this->assertStringContainsString('<map name="starmap">', $response['body']);
        $this->assertStringContainsString('usemap="#starmap"', $response['body']);
        $this->assertMatchesRegularExpression(
            '/<area shape="circle" coords="\d+,\d+,\d+" href="\?select_star=\d+&amp;select_center=1"/',
            $response['body'],
            'Image map areas must link to the same URL the JS click handler would use'
        );
    }

    public function testImageMapStaysBounded(): void
    {
        // Unbounded, this cost 10,000 areas and 1.3MB of HTML at wide zoom.
        $response = $this->fetch('/?x_c=0&y_c=0&z_c=0&xy_zoom=200&z_zoom=200');

        $areas = substr_count($response['body'], '<area ');
        $this->assertLessThanOrEqual(750, $areas, 'The image map is not respecting its cap');
        $this->assertGreaterThan(0, $areas, 'A wide view should still have clickable stars');
    }
}
