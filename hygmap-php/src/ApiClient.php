<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiExceptions.php';

/**
 * ApiClient - HTTP client for the FastAPI backend
 *
 * The application's only data source: the PHP app has no direct database
 * access, and every star, signal, and fictional-name read goes through here.
 */
final class ApiClient
{
    private string $baseUrl;
    private int $timeout;
    private int $retries;
    private string $userAgent;

    /**
     * Per-request memo of GET responses, keyed by URL.
     *
     * A single page render asks for the same data more than once — index.php builds
     * both the star table and the interactive overlay from an identical bbox query,
     * via two separate public helpers. Memoizing here fixes that for every caller
     * without threading results through their signatures.
     *
     * Safe because the API is read-only and a PHP request is short-lived: nothing can
     * change between two calls within one render. The cache dies with the instance.
     *
     * @var array<string, array>
     */
    private array $responseCache = [];

    /** @var ApiClient|null Singleton instance */
    private static ?ApiClient $instance = null;

    public function __construct(?string $baseUrl = null, int $timeout = 30, int $retries = 2)
    {
        $this->baseUrl = $baseUrl ?? (getenv('API_BASE_URL') ?: 'http://hygmap-api:8000');
        $this->timeout = $timeout;
        $this->retries = max(0, $retries);
        $this->userAgent = sprintf(
            'hygmap-php/ApiClient (+https://github.com/jswhitten/hygmap) php/%s',
            PHP_VERSION
        );
    }

    /**
     * Get singleton instance
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset singleton instance (useful for testing)
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Query stars within a bounding box
     *
     * @param array $bbox [xmin, xmax, ymin, ymax, zmin, zmax] in parsecs
     * @param float $magLimit Maximum absolute magnitude
     * @param int $world_id Fictional world ID (0 = no fictional names)
     * @param string $order Sort order (absmag, mag, proper, dist with asc/desc)
     * @return array Star data rows
     */
    public function queryAll(
        array  $bbox,
        float  $magLimit,
        int    $world_id = 0,
        string $order = 'absmag asc'
    ): array {
        [$xmin, $xmax, $ymin, $ymax, $zmin, $zmax] = $bbox;

        $params = [
            'xmin' => $xmin,
            'xmax' => $xmax,
            'ymin' => $ymin,
            'ymax' => $ymax,
            'zmin' => $zmin,
            'zmax' => $zmax,
            'mag_max' => $magLimit,
            'world_id' => $world_id,
            'order' => $order,
            'limit' => 10000,
        ];

        // Trailing slash matters: FastAPI mounts this route at /api/stars/, and
        // requesting it without the slash costs a 307 redirect on every call.
        $response = $this->get('/api/stars/', $params);
        return $response['data'] ?? [];
    }

    /**
     * Get a single star by ID
     *
     * @param int $id Star ID
     * @param int $world_id Fictional world ID
     * @return array|null Star data or null if not found
     */
    public function queryStar(int $id, int $world_id): ?array
    {
        $response = $this->get("/api/stars/{$id}", ['world_id' => $world_id]);
        return $response['data'] ?? null;
    }

    /**
     * Resolve an AT-HYG v3.3 star ID to the star it names in the current catalog.
     *
     * Returns null when nothing maps to that legacy ID — either it never existed in v3.3,
     * or its star did not survive the v4 migration, or its identifier is shared by two
     * real binary components and the matcher refused to guess between them.
     *
     * @param int $v3_id AT-HYG v3.3 star ID
     * @param int $world_id Fictional world ID
     * @return array|null Current star data, or null if the legacy ID resolves to nothing
     */
    public function queryLegacyStar(int $v3_id, int $world_id): ?array
    {
        try {
            $response = $this->get("/api/stars/legacy/{$v3_id}", ['world_id' => $world_id]);
        } catch (ApiNotFoundException $e) {
            return null;
        }
        return $response['data'] ?? null;
    }

    /**
     * Query signals within a bounding box
     *
     * @param array $bbox [xmin, xmax, ymin, ymax, zmin, zmax] in parsecs
     * @param string $order Sort order
     * @return array Signal data rows
     */
    public function querySignals(
        array  $bbox,
        string $order = 'time desc'
    ): array {
        [$xmin, $xmax, $ymin, $ymax, $zmin, $zmax] = $bbox;

        $params = [
            'xmin' => $xmin,
            'xmax' => $xmax,
            'ymin' => $ymin,
            'ymax' => $ymax,
            'zmin' => $zmin,
            'zmax' => $zmax,
            'order' => $order,
            'limit' => 1000,
        ];

        $response = $this->get('/api/signals/', $params);
        return $response['data'] ?? [];
    }

    /**
     * Search for a star by name or catalog ID
     *
     * @param string $term Search term
     * @param int $worldId Fictional world to search names in (0 = real names only).
     *                     Defaults to 0 so existing callers are unaffected.
     * @return array|null First matching star with id, or null
     */
    public function searchStar(string $term, int $worldId = 0): ?array
    {
        $response = $this->get('/api/stars/search', [
            'q' => $term,
            'limit' => 1,
            'world_id' => $worldId,
        ]);
        $data = $response['data'] ?? [];
        if (empty($data)) {
            return null;
        }
        // `x` comes back alongside the id because the caller has to know whether the star
        // can be plotted before deciding to redirect to the map. 25,342 stars have no
        // usable parallax and therefore no coordinates; search.php shows those as an inert
        // result instead of sending the user to a view they cannot appear in.
        return [
            'id' => $data[0]['id'],
            'x' => $data[0]['x'] ?? null,
            'display_name' => $data[0]['display_name'] ?? null,
        ];
    }

    /**
     * Find a fictional name matching $term in a universe OTHER than $excludeWorldId.
     *
     * Answers one question, on one code path: search.php has already found nothing, and
     * needs to distinguish "no such star" from "that name exists, but its universe is
     * switched off". A cold visitor typing "Vulcan" with no universe selected gets the
     * same bare "No match" as a typo, which is the first interaction this project
     * describes itself by.
     *
     * Costs one `/worlds` call plus one `/fictional-names` call per other world, on the
     * no-match path only. `fic` is 191 rows across a handful of worlds, so this is cheap
     * today; if it stops being cheap, the fix is an API endpoint that matches names across
     * worlds in one query, not a cache here.
     *
     * Matching mirrors the search endpoint deliberately: case-insensitive substring, but
     * prefix-anchored below TRIGRAM_MIN_CHARS (3), because that is what the user's query
     * would have done had the world been enabled. Saying "we found it" and then having the
     * real search not find it would be worse than saying nothing.
     *
     * @param string $term       The query that just failed.
     * @param int    $excludeWorldId The world already enabled, whose names were searched.
     * @return array{name: string, world_id: int, world_name: string}|null
     */
    public function findFictionalNameInOtherWorlds(string $term, int $excludeWorldId): ?array
    {
        $needle = mb_strtolower(trim($term));
        if ($needle === '') {
            return null;
        }
        $anchored = mb_strlen($needle) < 3;

        foreach ($this->queryWorlds() as $world) {
            $worldId = (int)($world['id'] ?? 0);
            if ($worldId <= 0 || $worldId === $excludeWorldId) {
                continue;
            }
            foreach ($this->queryFiction($worldId) as $fic) {
                $name = mb_strtolower((string)($fic['name'] ?? ''));
                $hit = $anchored
                    ? str_starts_with($name, $needle)
                    : str_contains($name, $needle);
                if ($hit) {
                    return [
                        'name' => (string)$fic['name'],
                        'world_id' => $worldId,
                        'world_name' => (string)($world['name'] ?? "universe $worldId"),
                    ];
                }
            }
        }
        return null;
    }

    /**
     * Get all fictional names (optionally filtered by world)
     *
     * @param int|null $world_id Filter by world ID
     * @return array Fictional name records with star_id and name
     */
    public function queryFiction(?int $world_id = null): array
    {
        if ($world_id === null || $world_id === 0) {
            return [];
        }
        $response = $this->get('/api/stars/fictional-names', ['world_id' => $world_id]);
        return $response['data'] ?? [];
    }

    /**
     * Get all proper star names
     *
     * @return array Records with id and proper
     */
    public function queryProperNames(): array
    {
        $response = $this->get('/api/stars/proper-names');
        return $response['data'] ?? [];
    }

    /**
     * Get all fictional worlds
     *
     * @return array Records with id and name
     */
    public function queryWorlds(): array
    {
        $response = $this->get('/api/stars/worlds');
        return $response['data'] ?? [];
    }

    /**
     * Make a GET request to the API
     *
     * @param string $endpoint API endpoint path
     * @param array $params Query parameters
     * @return array Decoded JSON response
     * @throws RuntimeException on HTTP or JSON errors
     */
    private function get(string $endpoint, array $params = []): array
    {
        $url = $this->baseUrl . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        if (array_key_exists($url, $this->responseCache)) {
            return $this->responseCache[$url];
        }

        $attempts = 0;
        $lastError = '';

        while ($attempts <= $this->retries) {
            $attempts++;

            $ch = curl_init($url);
            if ($ch === false) {
                $lastError = 'Failed to initialize HTTP request';
                break;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: ' . $this->userAgent,
                ],
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_ENCODING => '', // enable gzip/deflate if available
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
            ]);

            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $lastError = ($error ?: 'Unknown error') . " (URL: {$url})";
            } elseif ($statusCode >= 500 || $statusCode === 429) {
                // Retry on transient server or rate-limit errors
                $lastError = "HTTP {$statusCode}: {$response}";
            } elseif ($statusCode === 404) {
                // Not a fault: the API is healthy and is telling us the thing does not
                // exist. Callers need this distinct from an outage so they can answer 404
                // instead of claiming the service is broken.
                throw new ApiNotFoundException($this->formatApiError($url, $statusCode, $response));
            } elseif ($statusCode >= 400) {
                // Do not retry on other client errors
                throw new RuntimeException($this->formatApiError($url, $statusCode, $response));
            } else {
                $data = json_decode((string)$response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $snippet = substr((string)$response, 0, 200);
                    $lastError = sprintf(
                        'Failed to parse API response: %s (status %d, content-type %s, body snippet: %s)',
                        json_last_error_msg(),
                        $statusCode,
                        $contentType ?: 'unknown',
                        $snippet === '' ? '[empty]' : $snippet
                    );
                } else {
                    $this->responseCache[$url] = $data;
                    return $data;
                }
            }

            // Backoff before retrying (simple linear backoff: 100ms * attempts)
            if ($attempts <= $this->retries) {
                usleep(100000 * $attempts);
            }
        }

        // Connection failure, or 5xx that survived every retry. This is an outage.
        throw new ApiUnavailableException("API request failed after {$attempts} attempt(s): {$lastError}");
    }

    /**
     * Format API error including JSON body when available
     */
    private function formatApiError(string $url, int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $detail = $decoded['detail'] ?? $decoded['message'] ?? $body;
            return sprintf('API error %d at %s: %s', $status, $url, is_string($detail) ? $detail : json_encode($detail));
        }
        return sprintf('API error %d at %s: %s', $status, $url, $body);
    }
}
