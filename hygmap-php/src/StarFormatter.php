<?php
declare(strict_types=1);

/**
 * Star name and label formatting utilities
 *
 * Handles display name selection and color assignment for stars
 * based on available catalog identifiers and display preferences.
 */
final class StarFormatter
{
    /** Threshold for fading catalog star labels */
    public const MAG_THRESHOLD_FADE = 8.5;

    /**
     * Get label color for catalog identifiers (GJ, HD, HIP)
     * Brighter stars get grey labels, dimmer ones get dark grey
     */
    private static function getCatalogLabelColor(float $mag, array $colors): int
    {
        return $mag < self::MAG_THRESHOLD_FADE
            ? ($colors['grey'] ?? 0)
            : ($colors['darkgrey'] ?? 0);
    }

    /**
     * Get the display name for a star based on available identifiers
     *
     * Returns the most appropriate name based on priority:
     * 1. Fictional name (if enabled and available)
     * 2. Proper name (e.g., "Sirius", "Vega")
     * 3. Bayer designation (e.g., "Alpha Centauri")
     * 4. Flamsteed number (e.g., "61 Cygni")
     * 5. Catalog IDs: GJ, HD, HIP, Gaia
     * 6. Spectral type as fallback
     *
     * @param array $row Star data from database
     * @param int $fic_names Fiction world ID (0 = none, 1 = Star Trek, 2 = Babylon 5)
     * @param bool $with_color Whether to return color information (for map rendering)
     * @param string $image_type Image type ('printable', 'normal', etc.)
     * @param float $mag Star magnitude (for color determination)
     * @param array $colors Color palette array (required when $with_color is true)
     * @return string|array Returns name string, or [name, color] if $with_color is true
     */
    public static function getDisplayName(
        array $row,
        int $fic_names = 0,
        bool $with_color = false,
        string $image_type = 'normal',
        float $mag = 99.0,
        array $colors = []
    ): string|array {
        $name = '';
        $labelcolor = $colors['darkgrey'] ?? 0;
        $printcolor = $colors['darkgrey'] ?? 0;

        // Priority order for name selection
        if ($fic_names > 0 && !empty($row["name"])) {
            $name = $row["name"];
            $labelcolor = $colors['yellow'] ?? 0;
            $printcolor = $colors['black'] ?? 0;
        } elseif (!empty($row["proper"])) {
            $name = $row["proper"];
            $labelcolor = $colors['white'] ?? 0;
            $printcolor = $colors['black'] ?? 0;
        } elseif (!empty($row["bayer"])) {
            $name = ltrim($row["bayer"]) . " " . $row["con"];
            $labelcolor = $colors['grey'] ?? 0;
            $printcolor = $colors['darkgrey'] ?? 0;
        } elseif (!empty($row["flam"])) {
            $name = ltrim($row["flam"]) . " " . $row["con"];
            $labelcolor = $colors['grey'] ?? 0;
            $printcolor = $colors['darkgrey'] ?? 0;
        } elseif (!empty($row["gj"])) {
            $name = "GJ " . $row["gj"];
            $labelcolor = self::getCatalogLabelColor($mag, $colors);
            $printcolor = $colors['darkgrey'] ?? 0;
        } elseif (!empty($row["hd"])) {
            $name = "HD " . $row["hd"];
            $labelcolor = self::getCatalogLabelColor($mag, $colors);
            $printcolor = $colors['darkgrey'] ?? 0;
        } elseif (!empty($row["hip"])) {
            $name = "HIP " . $row["hip"];
            $labelcolor = self::getCatalogLabelColor($mag, $colors);
            $printcolor = $colors['darkgrey'] ?? 0;
        } elseif (!empty($row["gaia"])) {
            $name = "Gaia " . $row["gaia"];
            $labelcolor = $colors['darkgrey'] ?? 0;
            $printcolor = $colors['darkgrey'] ?? 0;
        } elseif (!empty($row["spect"])) {
            $name = $row["spect"];
            $labelcolor = $colors['darkgrey'] ?? 0;
            $printcolor = $colors['darkgrey'] ?? 0;
        }

        if (!$with_color) {
            return $name;
        }

        // Apply printable mode color override
        if ($image_type === "printable") {
            $labelcolor = $printcolor;
        }

        return [$name, $labelcolor];
    }
}
