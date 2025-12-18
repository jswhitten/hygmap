<?php
declare(strict_types=1);

/**
 * MapRenderer - Handles all star map image generation
 *
 * This class encapsulates the entire map rendering pipeline, including:
 * - Image creation and color allocation
 * - Grid drawing
 * - Star and signal plotting
 * - Label rendering
 *
 * Eliminates global variable usage by storing all configuration and state as properties.
 */
class MapRenderer
{
    private $image;
    private array $colors = [];

    // Configuration
    private string $unit;
    private float $grid;
    private int $fic_names;
    private string $image_type;
    private int $image_size;
    private float $max_line;
    private float $m_limit;
    private float $m_limit_label;
    private bool $show_signals;

    // View parameters
    private int $select_star;
    private float $x_c;
    private float $y_c;
    private float $z_c;
    private float $xy_zoom;
    private float $z_zoom;
    private string $image_side;

    public function __construct(array $cfg, array $vars)
    {
        // Extract configuration
        $this->unit = $cfg['unit'];
        $this->grid = (float)$cfg['grid'];
        $this->fic_names = (int)$cfg['fic_names'];
        $this->image_type = $cfg['image_type'];
        $this->image_size = (int)$cfg['image_size'];
        $this->max_line = (float)$cfg['max_line'];
        $this->m_limit = (float)$cfg['m_limit'];
        $this->m_limit_label = (float)$cfg['m_limit_label'];
        $this->show_signals = (bool)$cfg['show_signals'];

        // Extract view parameters
        $this->select_star = $vars['select_star'];
        $this->x_c = $vars['x_c'];
        $this->y_c = $vars['y_c'];
        $this->z_c = $vars['z_c'];
        $this->xy_zoom = $vars['xy_zoom'];
        $this->z_zoom = $vars['z_zoom'];
        $this->image_side = $vars['image_side'];
    }

    /**
     * Main rendering pipeline - orchestrates the entire map generation process
     */
    public function render(): void
    {
        // Set up output buffering and headers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
        header("Content-type: image/jpeg");

        // Create and configure image
        $this->createImage();
        $this->allocateColors();
        $this->fillBackground();

        // Query data
        $bbox = $this->buildBoundingBox();

        try {
            $rows = Database::queryAll($bbox, $this->m_limit, $this->fic_names, 'absmag desc');
        } catch (PDOException $e) {
            error_log("Map generation error: " . $e->getMessage());
            $this->createErrorImage("Database error - unable to load stars");
            return;
        }

        try {
            $signal_rows = $this->show_signals ? Database::querySignals($bbox) : [];
        } catch (PDOException $e) {
            error_log("Signal query error: " . $e->getMessage());
            $signal_rows = [];
        }

        // Render all elements
        $this->drawGrid();
        $this->plotConnectingLines($rows);
        $this->plotStars($rows);
        $this->plotSignals($signal_rows);

        // Output
        $this->output();
    }

    /**
     * Create the GD image resource
     */
    private function createImage(): void
    {
        if ($this->image_type == "stereo") {
            $this->image = ImageCreate($this->image_size, $this->image_size);
        } else {
            $this->image = ImageCreate($this->image_size * 2, $this->image_size);
        }
    }

    /**
     * Allocate all colors needed for rendering
     */
    private function allocateColors(): void
    {
        $this->colors = [
            'white' => ImageColorAllocate($this->image, ...COLOR_WHITE),
            'grey' => ImageColorAllocate($this->image, ...COLOR_GREY),
            'darkgrey' => ImageColorAllocate($this->image, ...COLOR_DARK_GREY),
            'green' => ImageColorAllocate($this->image, ...COLOR_GREEN),
            'red' => ImageColorAllocate($this->image, ...COLOR_RED),
            'orange' => ImageColorAllocate($this->image, ...COLOR_ORANGE),
            'lightyellow' => ImageColorAllocate($this->image, ...COLOR_LIGHT_YELLOW),
            'yellow' => ImageColorAllocate($this->image, ...COLOR_YELLOW),
            'lightblue' => ImageColorAllocate($this->image, ...COLOR_LIGHT_BLUE),
            'blue' => ImageColorAllocate($this->image, ...COLOR_BLUE),
            'darkblue' => ImageColorAllocate($this->image, ...COLOR_DARK_BLUE),
            'black' => ImageColorAllocate($this->image, ...COLOR_BLACK),
        ];
    }

    /**
     * Fill the background based on image type
     */
    private function fillBackground(): void
    {
        $bgcolor = ($this->image_type == "printable") ? $this->colors['white'] : $this->colors['black'];
        ImageFill($this->image, 50, 50, $bgcolor);
    }

    /**
     * Build bounding box in parsecs for database queries
     */
    private function buildBoundingBox(): array
    {
        return buildBoundingBox(
            $this->x_c,
            $this->y_c,
            $this->z_c,
            $this->xy_zoom,
            $this->z_zoom,
            $this->unit,
            $this->image_type
        );
    }

    /**
     * Draw the coordinate grid
     */
    private function drawGrid(): void
    {
        $distance = $this->grid;

        if ($this->image_type == "printable") {
            $linecolor = $this->colors['darkgrey'];
            $zerolinecolor = $this->colors['darkblue'];
        } else {
            $linecolor = $this->colors['green'];
            $zerolinecolor = $this->colors['blue'];
        }

        $is_stereo = ($this->image_type == "stereo");
        $y_multiplier = $is_stereo ? 1 : 2;
        $x_max = $is_stereo ? $this->image_size : $this->image_size * 2;

        // Calculate grid positions
        $gx_first = fmod(($this->y_c + $this->xy_zoom * $y_multiplier), $distance);
        $gx_label = ($this->y_c + $this->xy_zoom * $y_multiplier) - $gx_first;
        $gxs_int = ($this->image_size / 2) * ($distance / $this->xy_zoom);
        $gxs_first = ($gx_first / $distance) * $gxs_int;

        $gy_first = fmod(($this->x_c + $this->xy_zoom), $distance);
        $gy_label = ($this->x_c + $this->xy_zoom) - $gy_first;
        $gys_int = ($this->image_size / 2) * ($distance / $this->xy_zoom);
        $gys_first = ($gy_first / $distance) * $gxs_int;

        // Draw vertical grid lines
        for ($g = $gxs_first; $g < $x_max; $g += $gxs_int) {
            $color = ($gx_label == 0 && !$is_stereo) ? $zerolinecolor : $linecolor;
            ImageLine($this->image, (int)$g, 0, (int)$g, $this->image_size, $color);

            // Label with coordinate value (convert from parsecs in stereo mode)
            $label_value = $is_stereo ? from_pc($gx_label, $this->unit) : $gx_label;
            ImageString($this->image, 1, (int)$g + 5, 5, (string)round($label_value, 2), $this->colors['grey']);
            $gx_label -= $distance;
        }

        // Draw horizontal grid lines
        for ($g = $gys_first; $g < $this->image_size; $g += $gys_int) {
            $color = ($gy_label == 0 && !$is_stereo) ? $zerolinecolor : $linecolor;
            ImageLine($this->image, 0, (int)$g, $x_max, (int)$g, $color);

            $label_value = $is_stereo ? from_pc($gy_label, $this->unit) : $gy_label;
            ImageString($this->image, 1, 5, (int)$g + 5, (string)round($label_value, 2), $this->colors['grey']);
            $gy_label -= $distance;
        }
    }

    /**
     * Plot connecting lines between nearby stars
     */
    private function plotConnectingLines(array $rows): void
    {
        if ($this->max_line <= 0) {
            return;
        }

        // Pre-filter stars by magnitude and pre-calculate coordinates
        $eligible_stars = [];
        foreach ($rows as $row) {
            if ($row["absmag"] < $this->m_limit) {
                $x_ui = from_pc((float)$row["x"], $this->unit);
                $y_ui = from_pc((float)$row["y"], $this->unit);
                $z_ui = from_pc((float)$row["z"], $this->unit);

                list($screen_x, $screen_y) = $this->screenCoords($x_ui, $y_ui, $z_ui);

                $eligible_stars[] = [
                    'x' => $x_ui,
                    'y' => $y_ui,
                    'z' => $z_ui,
                    'screen_x' => $screen_x,
                    'screen_y' => $screen_y,
                ];
            }
        }

        // Only check upper triangle to avoid duplicate pairs
        $count = count($eligible_stars);

        // Calculate squared distance thresholds (in UI units)
        $max_far_line2 = $this->max_line * $this->max_line;
        $max_mid_line2 = (CONNECTING_LINE_MID_FACTOR * $this->max_line) * (CONNECTING_LINE_MID_FACTOR * $this->max_line);
        $max_close_line2 = (CONNECTING_LINE_CLOSE_FACTOR * $this->max_line) * (CONNECTING_LINE_CLOSE_FACTOR * $this->max_line);

        for ($i = 0; $i < $count - 1; $i++) {
            $star_i = $eligible_stars[$i];

            for ($j = $i + 1; $j < $count; $j++) {
                $star_j = $eligible_stars[$j];

                // Calculate 3D distance squared
                $x_diff = $star_i['x'] - $star_j['x'];
                $y_diff = $star_i['y'] - $star_j['y'];
                $z_diff = $star_i['z'] - $star_j['z'];
                $dist2 = $x_diff * $x_diff + $y_diff * $y_diff + $z_diff * $z_diff;

                // Draw line based on distance
                if ($dist2 < $max_close_line2) {
                    ImageLine($this->image,
                        (int)$star_i['screen_x'], (int)$star_i['screen_y'],
                        (int)$star_j['screen_x'], (int)$star_j['screen_y'],
                        $this->colors['lightblue']);
                } elseif ($dist2 < $max_mid_line2) {
                    ImageLine($this->image,
                        (int)$star_i['screen_x'], (int)$star_i['screen_y'],
                        (int)$star_j['screen_x'], (int)$star_j['screen_y'],
                        $this->colors['blue']);
                } elseif ($dist2 < $max_far_line2) {
                    ImageLine($this->image,
                        (int)$star_i['screen_x'], (int)$star_i['screen_y'],
                        (int)$star_j['screen_x'], (int)$star_j['screen_y'],
                        $this->colors['darkblue']);
                }
            }
        }
    }

    /**
     * Plot all stars with labels
     */
    private function plotStars(array $rows): void
    {
        foreach ($rows as $row) {
            $id = $row["id"];
            $x = (float)$row["x"];
            $y = (float)$row["y"];
            $z = (float)$row["z"];
            $mag = (float)$row["absmag"];

            if ($mag < $this->m_limit) {
                list($screen_x, $screen_y) = $this->screenCoords(
                    from_pc($x, $this->unit),
                    from_pc($y, $this->unit),
                    from_pc($z, $this->unit)
                );

                $starcolor = $this->getStarColor($row["spect"]);
                list($size, $labelsize) = $this->calculateStarSize($mag);

                // Plot star
                $this->plotStar($screen_x, $screen_y, $size, $starcolor, $this->select_star == $id);

                // Plot label (always label selected star, otherwise check if we should skip)
                if ($this->select_star == $id || !$this->shouldSkipLabel($row, $mag, $rows, $x, $y)) {
                    list($name, $labelcolor) = $this->getStarLabel($row, $mag);
                    $this->labelStar($name, $labelsize, $labelcolor, $screen_x, $screen_y);
                }
            }
        }
    }

    /**
     * Plot all signals
     */
    private function plotSignals(array $signal_rows): void
    {
        if (!$this->show_signals) {
            return;
        }

        // Calculate Sun's screen position once
        list($sun_sx, $sun_sy) = $this->screenCoords(0, 0, 0);

        foreach ($signal_rows as $signal) {
            list($screen_x, $screen_y) = $this->screenCoords(
                from_pc((float)$signal['x'], $this->unit),
                from_pc((float)$signal['y'], $this->unit),
                from_pc((float)$signal['z'], $this->unit)
            );

            $this->plotSignal($screen_x, $screen_y, $signal, $sun_sx, $sun_sy);
            $this->labelSignal($signal['name'], $screen_x, $screen_y);
        }
    }

    /**
     * Convert 3D galactic coordinates to 2D screen coordinates
     */
    private function screenCoords(float $x, float $y, float $z): array
    {
        $is_stereo = ($this->image_side == "left" || $this->image_side == "right");

        if ($is_stereo) {
            $screen_x = ($this->image_size / 2) - (($this->image_size / (2 * $this->xy_zoom)) * ($y - $this->y_c));
            $screen_y = ($this->image_size / 2) - (($this->image_size / (2 * $this->xy_zoom)) * ($x - $this->x_c));

            // Apply stereo offset
            $offset = STEREO_OFFSET_MULTIPLIER * (($z - $this->z_c) / $this->z_zoom);
            $screen_x += ($this->image_side == "left") ? $offset : -$offset;
        } else {
            $screen_x = ($this->image_size) - (($this->image_size / (2 * $this->xy_zoom)) * ($y - $this->y_c));
            $screen_y = ($this->image_size / 2) - (($this->image_size / (2 * $this->xy_zoom)) * ($x - $this->x_c));
        }

        return [$screen_x, $screen_y];
    }

    /**
     * Determine if a star's label should be skipped
     */
    private function shouldSkipLabel(array $row, float $mag, array $rows, float $x, float $y): bool
    {
        // First: respect user's configured magnitude cutoff
        if ($mag > $this->m_limit_label) {
            return true;
        }

        // Check if star has important name
        $has_important_name = $this->hasImportantName($row);

        // Apply density-based filtering for unnamed stars
        if (!$has_important_name && $mag > MAG_THRESHOLD_DENSE_FIELD && $row["id"] > 0) {
            return true;
        }

        if (!$has_important_name && count($rows) > 1000 && $mag > 5 && $row["id"] > 0) {
            return true;
        }

        // Check for overlap with brighter stars
        foreach ($rows as $checkrow) {
            if ((float)$checkrow['absmag'] < $mag) {
                if (abs($checkrow['x'] - $x) < $this->xy_zoom / LABEL_OVERLAP_X_DIVISOR &&
                    abs($checkrow['y'] - $y) < $this->xy_zoom / LABEL_OVERLAP_Y_DIVISOR) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if star has a traditional astronomical name
     */
    private function hasImportantName(array $row): bool
    {
        return !empty($row["proper"])
            || !empty($row["bayer"])
            || !empty($row["flam"])
            || (!empty($row["name"]) && $this->fic_names > 0);
    }

    /**
     * Get star color based on spectral class
     */
    private function getStarColor(?string $specdata): int
    {
        $spec = $this->getSpecClass($specdata);

        if ($spec == "O") {
            return $this->colors['blue'];
        } elseif ($spec == "B") {
            return $this->colors['lightblue'];
        } elseif ($spec == "F") {
            return $this->colors['lightyellow'];
        } elseif ($spec == "G") {
            return $this->colors['yellow'];
        } elseif ($spec == "K" || $spec == "R") {
            return $this->colors['orange'];
        } elseif ($spec == "M" || $spec == "C" || $spec == "N" || $spec == "S") {
            return $this->colors['red'];
        } else {
            return $this->colors['white'];
        }
    }

    /**
     * Extract spectral class from spectral data
     */
    private function getSpecClass(?string $specdata): string
    {
        if (empty($specdata)) {
            return "";
        }

        $spec = substr($specdata, 0, 1);
        if ($spec == " " || $spec == "s") {
            $spec = strtoupper(substr($specdata, 2, 1));
        }

        return $spec;
    }

    /**
     * Calculate star size and label size based on magnitude
     */
    private function calculateStarSize(float $mag): array
    {
        if ($mag > MAG_THRESHOLD_DIM) {
            $size = STAR_SIZE_MIN;
            $labelsize = LABEL_SIZE_SMALL;
        } elseif ($mag > MAG_THRESHOLD_MEDIUM) {
            $size = (int)(STAR_SIZE_BASE - STAR_SIZE_FACTOR * $mag);
            $labelsize = LABEL_SIZE_SMALL;
        } elseif ($mag > MAG_THRESHOLD_BRIGHT) {
            $size = (int)(STAR_SIZE_BASE - STAR_SIZE_FACTOR * $mag);
            $labelsize = LABEL_SIZE_MEDIUM;
        } else {
            $size = (int)(STAR_SIZE_BASE - STAR_SIZE_FACTOR * $mag);
            $labelsize = LABEL_SIZE_LARGE;
        }

        return [$size, $labelsize];
    }

    /**
     * Get star label text and color
     */
    private function getStarLabel(array $row, float $mag): array
    {
        return getStarDisplayName($row, $this->fic_names, true, $this->image_type, $mag, $this->colors);
    }

    /**
     * Plot a single star
     */
    private function plotStar(float $screen_x, float $screen_y, int $size, int $starcolor, bool $selected): void
    {
        if ($this->image_type == "printable") {
            $starcolor = $this->colors['black'];
            $boxcolor = $this->colors['darkgrey'];
        } else {
            $boxcolor = $this->colors['blue'];
        }

        ImageFilledEllipse($this->image, (int)$screen_x, (int)$screen_y, $size, $size, $starcolor);

        // Draw selection box for selected star
        if ($selected) {
            ImageRectangle($this->image,
                (int)$screen_x - SELECTED_STAR_BOX_SIZE,
                (int)$screen_y - SELECTED_STAR_BOX_SIZE,
                (int)$screen_x + SELECTED_STAR_BOX_SIZE,
                (int)$screen_y + SELECTED_STAR_BOX_SIZE,
                $boxcolor);
        }
    }

    /**
     * Draw a star label
     */
    private function labelStar(string $name, int $labelsize, int $labelcolor, float $screen_x, float $screen_y): void
    {
        ImageString($this->image, $labelsize, (int)$screen_x + 5, (int)$screen_y + 5, $name, $labelcolor);
    }

    /**
     * Plot a signal as directional concentric arcs
     */
    private function plotSignal(float $screen_x, float $screen_y, array $signal, float $sun_sx, float $sun_sy): void
    {
        // Calculate direction away from Sun
        $angle_rad = atan2($screen_y - $sun_sy, $screen_x - $sun_sx);
        $angle_deg = rad2deg($angle_rad);
        $startAngle = $angle_deg - SIGNAL_ARC_ANGLE;
        $endAngle = $angle_deg + SIGNAL_ARC_ANGLE;

        // Select colors based on signal type
        if ($signal['type'] === 'transmit') {
            $c1 = $this->colors['orange'];
            $c2 = $this->colors['red'];
            $c3 = $this->colors['darkblue'];
        } else {
            $c1 = $this->colors['lightblue'];
            $c2 = $this->colors['blue'];
            $c3 = $this->colors['darkblue'];
        }

        // Draw three concentric arcs
        ImageArc($this->image, (int)$screen_x, (int)$screen_y, SIGNAL_ARC_OUTER, SIGNAL_ARC_OUTER, (int)$startAngle, (int)$endAngle, $c3);
        ImageArc($this->image, (int)$screen_x, (int)$screen_y, SIGNAL_ARC_MIDDLE, SIGNAL_ARC_MIDDLE, (int)$startAngle, (int)$endAngle, $c2);
        ImageArc($this->image, (int)$screen_x, (int)$screen_y, SIGNAL_ARC_INNER, SIGNAL_ARC_INNER, (int)$startAngle, (int)$endAngle, $c1);
    }

    /**
     * Draw a signal label
     */
    private function labelSignal(string $name, float $screen_x, float $screen_y): void
    {
        ImageString($this->image, 2, (int)$screen_x + 12, (int)$screen_y - 8, $name, $this->colors['lightblue']);
    }

    /**
     * Create an error image with message
     */
    private function createErrorImage(string $message): void
    {
        $this->createImage();
        $errorColor = ImageColorAllocate($this->image, 255, 0, 0);
        $bgColor = ImageColorAllocate($this->image, 255, 255, 255);
        ImageFill($this->image, 0, 0, $bgColor);
        ImageString($this->image, 3, 10, 10, $message, $errorColor);
        $this->output();
    }

    /**
     * Output the image as JPEG
     */
    private function output(): void
    {
        ob_end_clean();
        ImageJPEG($this->image);
        ImageDestroy($this->image);
    }
}
