<?php
declare(strict_types=1);

namespace HYGMap\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Drives `search.php` over HTTP, against the real stack.
 *
 * Why this file exists
 * --------------------
 * **No test executed `search.php` at all** until 2026-08-01. The CI smoke test searches
 * "Sol", which has a position and therefore takes the redirect branch, so every other
 * branch of the file was unexercised. That is not a theoretical gap: this code has been
 * hand-debugged three separate times — a contrast fix, a `lang`/charset fix, and the
 * shared page-shell refactor — and no regression test landed on any of those occasions.
 * `audit-tests` filed it as major on 2026-07-31.
 *
 * It has to be driven over HTTP rather than by including the file. `search.php` reads
 * `$_GET`, calls `session_start()`, and `exit`s on two of its four paths; including it
 * would take the PHPUnit process with it. Over HTTP the redirect is also observable as a
 * redirect, which is the actual contract.
 *
 * Requires the stack to be up: `docker compose up -d`, then `make test-integration`.
 */
class SearchTest extends TestCase
{
    private const PHP_BASE = 'http://hygmap-php';
    private const FIXTURE_PATH = '/fixtures/positionless-stars.json';

    /**
     * Fetch without following redirects — a 302 is one of the answers under test.
     *
     * @return array{status:int, body:string, location:string}
     */
    private function fetch(string $path): array
    {
        $ch = curl_init(self::PHP_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        if ($raw === false) {
            $this->markTestSkipped('PHP app not reachable: ' . curl_error($ch));
        }

        $raw = (string)$raw;
        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        $location = '';
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
            $location = trim($m[1]);
        }

        return ['status' => $status, 'body' => $body, 'location' => $location];
    }

    /**
     * The positionless star the shared fixture nominates as the ordinary case.
     *
     * Taken from the fixture rather than hardcoded so that this test and
     * `Unit\PositionlessStarTest` cannot drift onto different stars — and so that if the
     * catalog ever stops carrying this id, both fail together and for the same reason.
     *
     * @return array<string, mixed>
     */
    private static function positionlessStar(): array
    {
        if (!file_exists(self::FIXTURE_PATH)) {
            throw new \RuntimeException(
                'Shared fixture not mounted at ' . self::FIXTURE_PATH
                . '; see the test-integration target in the Makefile'
            );
        }

        $data = json_decode((string)file_get_contents(self::FIXTURE_PATH), true);
        foreach ($data['cases'] ?? [] as $case) {
            if (($case['has_position'] ?? true) === false && !empty($case['star']['hip'])) {
                return $case['star'];
            }
        }

        throw new \RuntimeException('Shared fixture has no positionless case with a HIP id');
    }

    public function testPositionlessStarIsFoundButNotRedirectedToTheMap(): void
    {
        $star = self::positionlessStar();
        $response = $this->fetch('/search.php?q=' . rawurlencode('HIP ' . $star['hip']));

        $this->assertSame(
            200,
            $response['status'],
            'A findable star must not 404 merely because it cannot be mapped'
        );
        $this->assertSame(
            '',
            $response['location'],
            'Redirecting would land the reader on a view of Sol carrying this star\'s name'
        );
    }

    public function testPositionlessStarSaysWhyItCannotBeMapped(): void
    {
        $star = self::positionlessStar();
        $response = $this->fetch('/search.php?q=' . rawurlencode('HIP ' . $star['hip']));

        $this->assertStringContainsString('cannot be shown on the map', $response['body']);
        $this->assertStringContainsString('no parallax measurement', $response['body']);
    }

    public function testPositionlessStarStillLinksToItsDetailsWithoutCentering(): void
    {
        $star = self::positionlessStar();
        $response = $this->fetch('/search.php?q=' . rawurlencode('HIP ' . $star['hip']));

        // Access to the star is kept; only `select_center` is dropped, because there is
        // nothing to centre on. NULL-COORDINATES turns on exactly this distinction.
        $this->assertMatchesRegularExpression(
            '/href="\/\?select_star=' . (int)$star['id'] . '&amp;c=\d+"/',
            $response['body'],
            'The star page must still be reachable from a positionless search result'
        );
        $this->assertStringNotContainsString(
            'select_center',
            $response['body'],
            'A star with no position must not be offered as something to centre on'
        );
    }

    public function testPositionlessResultIsAWellFormedPage(): void
    {
        // The `lang`/charset fix landed in one branch of this file and not its sibling six
        // lines away, and audit-frontend then found the same defect twice in one function.
        // The shared page shell is what prevents that; this asserts it on the branch that
        // was wrong.
        $star = self::positionlessStar();
        $response = $this->fetch('/search.php?q=' . rawurlencode('HIP ' . $star['hip']));

        $this->assertStringContainsString('<html lang="en">', $response['body']);
        $this->assertStringContainsString('<meta charset="utf-8">', $response['body']);
        $this->assertStringContainsString('<a href="/">Back to map</a>', $response['body']);
    }

    public function testMappableStarStillRedirectsAndCentres(): void
    {
        // The control case. Only an unmappable star loses the redirect.
        $response = $this->fetch('/search.php?q=Sirius');

        $this->assertSame(302, $response['status']);
        $this->assertStringContainsString('select_center=1', $response['location']);
    }

    public function testGibberishIsAPlainNoMatch(): void
    {
        $response = $this->fetch('/search.php?q=' . rawurlencode('qqzzxx not a star'));

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('No match', $response['body']);
        // A typo must not be dressed up as a disabled-universe problem.
        $this->assertStringNotContainsString('configure.php', $response['body']);
    }

    public function testFictionalNameSaysItsUniverseIsSwitchedOff(): void
    {
        // No session, so no universe is enabled — the state a first-time visitor is in.
        // "Vulcan" is the example in CLAUDE.md's own purpose statement, and it used to
        // produce a bare "No match" indistinguishable from a misspelling.
        $response = $this->fetch('/search.php?q=Vulcan');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('not switched on', $response['body']);
        $this->assertStringContainsString('configure.php', $response['body']);
    }
}
