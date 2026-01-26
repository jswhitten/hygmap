<?php
declare(strict_types=1);

/**
 * ApiClient - HTTP client for the FastAPI backend
 *
 * Replaces direct database access with REST API calls.
 * Methods mirror the Database class interface for easy migration.
 */
final class ApiClient
{
    private string $baseUrl;
    private int $timeout;
    private int $retries;
    private string $userAgent;

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

        $response = $this->get('/api/stars', $params);
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

        $response = $this->get('/api/signals', $params);
        return $response['data'] ?? [];
    }

    /**
     * Search for a star by name or catalog ID
     *
     * @param string $term Search term
     * @return array|null First matching star with id, or null
     */
    public function searchStar(string $term): ?array
    {
        $response = $this->get('/api/stars/search', ['q' => $term, 'limit' => 1]);
        $data = $response['data'] ?? [];
        if (empty($data)) {
            return null;
        }
        // Return just the id field to match Database::searchStar behavior
        return ['id' => $data[0]['id']];
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
            ]);

            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $lastError = ($error ?: 'Unknown error') . " (URL: {$url})";
            } elseif ($statusCode >= 500 || $statusCode === 429) {
                // Retry on transient server or rate-limit errors
                $lastError = "HTTP {$statusCode}: {$response}";
            } elseif ($statusCode >= 400) {
                // Do not retry on client errors
                throw new RuntimeException($this->formatApiError($url, $statusCode, $response));
            } else {
                $data = json_decode((string)$response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $lastError = 'Failed to parse API response: ' . json_last_error_msg();
                } else {
                    return $data;
                }
            }

            // Backoff before retrying (simple linear backoff: 100ms * attempts)
            if ($attempts <= $this->retries) {
                usleep(100000 * $attempts);
            }
        }

        throw new RuntimeException("API request failed after {$attempts} attempt(s): {$lastError}");
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
