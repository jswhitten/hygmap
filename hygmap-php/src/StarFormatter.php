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
     * 5. Catalog IDs: GJ, HD, HIP, CNS5, Gaia
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

        // Canonical priority order, decided 2026-07-29 (DISPLAY-NAME-CANON). This chain
        // must stay in step with StarBase.display_name in hygmap-api/app/schemas/star.py
        // and getStarDisplayName in hygmap-frontend/src/types/star.ts. All three are
        // asserted against tests/fixtures/display-names.json; change that table first.
        if ($fic_names > 0 && !empty($row["name"])) {
            $name = $row["name"];
            $labelcolor = $colors['yellow'] ?? 0;
            $printcolor = $colors['black'] ?? 0;
        } elseif (!empty($row["proper"])) {
            $name = $row["proper"];
            $labelcolor = $colors['white'] ?? 0;
            $printcolor = $colors['black'] ?? 0;
        } elseif (!empty($row["bayer"]) && !empty($row["con"])) {
            // The constellation is required: a bare Greek letter is not a designation.
            // This used to emit "Alp " with a trailing space instead of falling through.
            $name = trim($row["bayer"]) . " " . trim($row["con"]);
            $labelcolor = $colors['grey'] ?? 0;
            $printcolor = $colors['darkgrey'] ?? 0;
        } elseif (!empty($row["flam"]) && !empty($row["con"])) {
            $name = trim($row["flam"]) . " " . trim($row["con"]);
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
        } elseif (!empty($row["hr"])) {
            // This branch was missing entirely, so a star whose only designation was a
            // Yale Bright Star number rendered with an EMPTY label on the map.
            $name = "HR " . $row["hr"];
            $labelcolor = self::getCatalogLabelColor($mag, $colors);
            $printcolor = $colors['darkgrey'] ?? 0;
        } elseif (!empty($row["cns5"])) {
            $name = "CNS5 " . $row["cns5"];
            $labelcolor = $colors['darkgrey'] ?? 0;
            $printcolor = $colors['darkgrey'] ?? 0;
        } elseif (!empty($row["tyc"])) {
            $name = "TYC " . $row["tyc"];
            $labelcolor = $colors['darkgrey'] ?? 0;
            $printcolor = $colors['darkgrey'] ?? 0;
        } elseif (!empty($row["gaia"])) {
            $name = "Gaia " . $row["gaia"];
            $labelcolor = $colors['darkgrey'] ?? 0;
            $printcolor = $colors['darkgrey'] ?? 0;
        } elseif (!empty($row["spect"])) {
            $name = $row["spect"];
            $labelcolor = $colors['darkgrey'] ?? 0;
            $printcolor = $colors['darkgrey'] ?? 0;
        } else {
            // Last resort, so the name is never empty. A star that matched nothing above
            // used to render as a blank label.
            $name = "ID " . ($row["id"] ?? '?');
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
