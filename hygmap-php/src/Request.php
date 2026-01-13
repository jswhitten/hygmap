<?php
declare(strict_types=1);

/**
 * HTTP Request parameter handling
 *
 * Extracts and validates query parameters from HTTP requests
 * with strict type validation and bounds checking.
 */
final class Request
{
    /** Maximum coordinate value (parsecs) */
    private const COORD_MAX = 100000.0;

    /** Minimum zoom level */
    private const ZOOM_MIN = 0.1;

    /** Default map view parameters */
    private const MAP_DEFAULTS = [
        'select_star'   => 0,
        'select_center' => 0,
        'x_c'           => 0.0,
        'y_c'           => 0.0,
        'z_c'           => 0.0,
        'xy_zoom'       => 25.0,
        'z_zoom'        => 25.0,
        'image_side'    => '',
    ];

    /**
     * Extract and validate map view parameters from GET request
     *
     * Applies strict type validation and bounds checking to all parameters.
     *
     * @return array{
     *   select_star: int,
     *   select_center: int,
     *   x_c: float,
     *   y_c: float,
     *   z_c: float,
     *   xy_zoom: float,
     *   z_zoom: float,
     *   image_side: string
     * }
     */
    public static function getMapParams(): array
    {
        // Filter specification for input validation
        $spec = [
            'select_star'   => ['filter' => FILTER_VALIDATE_INT,   'flags' => FILTER_NULL_ON_FAILURE],
            'select_center' => ['filter' => FILTER_VALIDATE_INT,   'flags' => FILTER_NULL_ON_FAILURE],
            'x_c'           => ['filter' => FILTER_VALIDATE_FLOAT, 'flags' => FILTER_NULL_ON_FAILURE],
            'y_c'           => ['filter' => FILTER_VALIDATE_FLOAT, 'flags' => FILTER_NULL_ON_FAILURE],
            'z_c'           => ['filter' => FILTER_VALIDATE_FLOAT, 'flags' => FILTER_NULL_ON_FAILURE],
            'xy_zoom'       => ['filter' => FILTER_VALIDATE_FLOAT, 'flags' => FILTER_NULL_ON_FAILURE],
            'z_zoom'        => ['filter' => FILTER_VALIDATE_FLOAT, 'flags' => FILTER_NULL_ON_FAILURE],
            'image_side'    => ['filter' => FILTER_UNSAFE_RAW],
        ];

        /** @var array<string, mixed>|false|null $rawInput */
        $rawInput = filter_input_array(INPUT_GET, $spec, true);
        $input = is_array($rawInput) ? $rawInput : [];

        // Merge with defaults (excluding null values)
        $vars = array_replace(
            self::MAP_DEFAULTS,
            array_filter($input, static fn($v) => $v !== null)
        );

        // Validate image_side enum
        $vars['image_side'] = in_array($vars['image_side'], ['left', 'right'], true)
            ? $vars['image_side']
            : '';

        // Clamp zoom levels
        $vars['xy_zoom'] = max(self::ZOOM_MIN, $vars['xy_zoom']);
        $vars['z_zoom']  = max(self::ZOOM_MIN, $vars['z_zoom']);

        // Clamp coordinates to reasonable bounds
        $vars['x_c'] = self::clampCoordinate($vars['x_c']);
        $vars['y_c'] = self::clampCoordinate($vars['y_c']);
        $vars['z_c'] = self::clampCoordinate($vars['z_c']);

        return $vars;
    }

    /**
     * Clamp a coordinate value to valid bounds
     *
     * @param float $value Coordinate value
     * @return float Clamped value
     */
    private static function clampCoordinate(float $value): float
    {
        return max(-self::COORD_MAX, min(self::COORD_MAX, $value));
    }
}
