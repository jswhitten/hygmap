<?php
declare(strict_types=1);

require_once __DIR__ . '/Units.php';

/**
 * Map geometry calculations
 *
 * Handles coordinate transformations and bounding box calculations
 * for the star map display.
 */
final class MapGeometry
{
    /**
     * Calculate bounding box in parsecs for database queries
     *
     * @param float $x_c Center X coordinate in UI units
     * @param float $y_c Center Y coordinate in UI units
     * @param float $z_c Center Z coordinate in UI units
     * @param float $xy_zoom X/Y zoom level in UI units
     * @param float $z_zoom Z zoom level in UI units
     * @param string $unit UI unit ('pc' or 'ly')
     * @param string $image_type Image type ('stereo', 'left', 'right', 'normal', 'printable')
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} [xmin, xmax, ymin, ymax, zmin, zmax] in parsecs
     */
    public static function buildBoundingBox(
        float $x_c,
        float $y_c,
        float $z_c,
        float $xy_zoom,
        float $z_zoom,
        string $unit,
        string $image_type = 'normal'
    ): array {
        $xy_zoom_pc = Units::toParsecs($xy_zoom, $unit);
        $z_zoom_pc = Units::toParsecs($z_zoom, $unit);

        // Stereo/3D modes use square aspect ratio, others use 2:1
        $is_stereo = in_array($image_type, ['stereo', 'left', 'right'], true);
        $y_multiplier = $is_stereo ? $xy_zoom_pc : 2 * $xy_zoom_pc;

        return [
            Units::toParsecs($x_c, $unit) - $xy_zoom_pc,      // xmin
            Units::toParsecs($x_c, $unit) + $xy_zoom_pc,      // xmax
            Units::toParsecs($y_c, $unit) - $y_multiplier,    // ymin
            Units::toParsecs($y_c, $unit) + $y_multiplier,    // ymax
            Units::toParsecs($z_c, $unit) - $z_zoom_pc,       // zmin
            Units::toParsecs($z_c, $unit) + $z_zoom_pc,       // zmax
        ];
    }
}
