<?php
declare(strict_types=1);

/**
 * IndexHelpers - Helper functions for index.php view
 *
 * Separates business logic from presentation by providing focused functions
 * for data fetching, transformation, and HTML generation.
 */

/**
 * Fetch selected star and update view center if requested
 *
 * @param int $select_star Star ID to select
 * @param int $select_center Whether to center view on star (1 = yes)
 * @param int $fic_names Fiction world ID
 * @param string $unit Distance unit for conversion
 * @param array &$view_coords Reference to coordinates array to update
 * @return array|null Selected star data or null
 */
function fetchSelectedStar(int $select_star, int $select_center, int $fic_names, string $unit, array &$view_coords): ?array
{
    if ($select_star <= 0) {
        return null;
    }

    try {
        $selected_star = Database::queryStar($select_star, $fic_names);
        if (!$selected_star) {
            return null;
        }

        // Update view center if requested
        if ($select_center == 1) {
            $view_coords['x_c'] = from_pc($selected_star["x"], $unit);
            $view_coords['y_c'] = from_pc($selected_star["y"], $unit);
            $view_coords['z_c'] = from_pc($selected_star["z"], $unit);
        }

        return $selected_star;
    } catch (PDOException $e) {
        handleError("Unable to retrieve star information from database.", $e);
        return null;
    }
}

/**
 * Build data for selected star info panel
 *
 * @param array|null $selected_star Star data from database
 * @param int $fic_names Fiction world ID
 * @param string $unit Distance unit
 * @return array Data for rendering selected star info
 */
function buildSelectedStarData(?array $selected_star, int $fic_names, string $unit): array
{
    if (!$selected_star) {
        return [
            'has_star' => false,
            'html' => '<em>No star selected.</em>'
        ];
    }

    // Calculate display name and links
    $star_name = getStarDisplayName($selected_star, 0);
    $has_catalog_name = !empty($selected_star["proper"]) || !empty($selected_star["bf"]);

    if ($has_catalog_name) {
        $display_name = '<a href="https://en.wikipedia.org/w/index.php?title=Special%3ASearch&search=' .
                       urlencode($star_name) . '">' .
                       htmlspecialchars($star_name, ENT_QUOTES) . '</a>';
    } else {
        $display_name = htmlspecialchars($star_name, ENT_QUOTES);
    }

    // Add fictional name if present
    $memory_alpha = '';
    if ($fic_names && !empty($selected_star["name"]) && $star_name != $selected_star["name"]) {
        $display_name .= " (" . htmlspecialchars($selected_star["name"], ENT_QUOTES) . ")";
        $memory_alpha = '<br/>[ <a href="https://memory-alpha.fandom.com/wiki/Special:Search?query=' .
                       urlencode($selected_star["name"]) .
                       '&scope=internal&navigationSearch=true" target="_blank">Search Memory Alpha for this star system</a> ]<br/>';
    }

    // Calculate coordinates and links
    $selected_ra_deg = $selected_star["ra"] * 360 / 24;
    $selected_dec_av = abs($selected_star["dec"]);
    $selected_dec_ns = ($selected_star["dec"] >= 0) ? 'North' : 'South';
    $selected_dec_simbad = ($selected_star["dec"] >= 0) ? '%2B' . $selected_dec_av : $selected_star["dec"];

    $distance_ui = number_format(from_pc($selected_star["dist"], $unit), 3);
    $x_ui = number_format(from_pc($selected_star["x"], $unit), 3);
    $y_ui = number_format(from_pc($selected_star["y"], $unit), 3);
    $z_ui = number_format(from_pc($selected_star["z"], $unit), 3);

    $x_ly = to_ly($selected_star["x"], "pc");
    $y_ly = to_ly($selected_star["y"], "pc");
    $z_ly = to_ly($selected_star["z"], "pc");

    return [
        'has_star' => true,
        'display_name' => $display_name,
        'absmag' => $selected_star["absmag"],
        'spect' => $selected_star["spect"],
        'distance_ui' => $distance_ui,
        'unit' => $unit,
        'x_ui' => $x_ui,
        'y_ui' => $y_ui,
        'z_ui' => $z_ui,
        'mag' => $selected_star["mag"],
        'ra' => $selected_star["ra"],
        'dec' => $selected_star["dec"],
        'x_ly' => $x_ly,
        'y_ly' => $y_ly,
        'z_ly' => $z_ly,
        'selected_ra_deg' => $selected_ra_deg,
        'selected_dec_av' => $selected_dec_av,
        'selected_dec_ns' => $selected_dec_ns,
        'selected_dec_simbad' => $selected_dec_simbad,
        'memory_alpha' => $memory_alpha,
    ];
}

/**
 * Generate HTML for map image tags
 *
 * @param string $image_type Type of map ('stereo', 'normal', 'printable')
 * @param int $image_size Size in pixels
 * @param array $baseParams Query parameters for map.php
 * @return string HTML for map image(s)
 */
function buildMapHtml(string $image_type, int $image_size, array $baseParams): string
{
    if ($image_type === 'stereo') {
        $leftSrc = 'map.php?' . http_build_query($baseParams + ['image_side' => 'left']);
        $rightSrc = 'map.php?' . http_build_query($baseParams + ['image_side' => 'right']);

        return '<img src="' . htmlspecialchars($leftSrc) . '" ' .
               'width="' . $image_size . '" height="' . $image_size . '">&nbsp;' .
               '<img src="' . htmlspecialchars($rightSrc) . '" ' .
               'width="' . $image_size . '" height="' . $image_size . '">';
    } else {
        $src = 'map.php?' . http_build_query($baseParams);
        return '<img src="' . htmlspecialchars($src) . '" ' .
               'width="' . ($image_size * 2) . '" height="' . $image_size . '">';
    }
}

/**
 * Fetch and transform star table data
 *
 * @param array $bbox Bounding box for query
 * @param float $m_limit Magnitude limit
 * @param float $m_limit_label Label magnitude limit
 * @param int $fic_names Fiction world ID
 * @param string $unit Distance unit
 * @param array $view_coords View center coordinates
 * @return array Star table data with counts
 */
function fetchStarTableData(array $bbox, float $m_limit, float $m_limit_label, int $fic_names, string $unit, array $view_coords): array
{
    try {
        $rows = Database::queryAll($bbox, $m_limit, $fic_names, 'absmag asc');
    } catch (PDOException $e) {
        handleError("Unable to query stars in the current map area.", $e);
        return ['rows' => [], 'total_count' => 0, 'displayed_count' => 0];
    }

    $star_data = [];
    $total_count = 0;
    $displayed_count = 0;

    foreach ($rows as $row) {
        $total_count++;

        if ($row['absmag'] >= $m_limit_label) {
            continue;
        }

        $displayed_count++;

        $x_ui = from_pc($row["x"], $unit);
        $y_ui = from_pc($row["y"], $unit);
        $z_ui = from_pc($row["z"], $unit);

        $distance_from_center = sqrt(
            pow($x_ui - $view_coords['x_c'], 2) +
            pow($y_ui - $view_coords['y_c'], 2) +
            pow($z_ui - $view_coords['z_c'], 2)
        );

        $star_data[] = [
            'id' => $row['id'],
            'name' => getStarDisplayName($row, 0),
            'con' => $row["con"] ?? '',
            'spect' => $row["spect"] ?? '',
            'absmag' => $row["absmag"],
            'distance' => number_format(from_pc($row["dist"], $unit), 3),
            'distance_from_center' => number_format($distance_from_center, 3),
            'x' => number_format($x_ui, 3),
            'y' => number_format($y_ui, 3),
            'z' => number_format($z_ui, 3),
        ];
    }

    return [
        'rows' => $star_data,
        'total_count' => $total_count,
        'displayed_count' => $displayed_count,
    ];
}

/**
 * Build HTML option tags from array of items
 *
 * @param array $items Array of items with id/value fields
 * @param string $value_key Key for option value
 * @param string $label_key Key for option label
 * @return string HTML option tags
 */
function buildSelectOptions(array $items, string $value_key, string $label_key): string
{
    $options = [];
    foreach ($items as $item) {
        $value = htmlspecialchars((string)$item[$value_key], ENT_QUOTES);
        $label = htmlspecialchars($item[$label_key], ENT_QUOTES);
        $options[] = "<option value=\"{$value}\">{$label}</option>";
    }
    return implode("\n", $options);
}

/**
 * Fetch proper star names for dropdown
 *
 * @return string HTML option tags
 */
function fetchProperStarOptions(): string
{
    try {
        $rows = Database::queryProperNames();
        return buildSelectOptions($rows, 'id', 'proper');
    } catch (PDOException $e) {
        handleError("Unable to load star catalog.", $e);
        return '';
    }
}

/**
 * Fetch fictional star names for dropdown
 *
 * @param int $fic_names Fiction world ID
 * @return string HTML option tags
 */
function fetchFictionalStarOptions(int $fic_names): string
{
    try {
        $rows = Database::queryFiction($fic_names);
        return buildSelectOptions($rows, 'star_id', 'name');
    } catch (PDOException $e) {
        handleError("Unable to load fictional star names.", $e);
        return '';
    }
}
