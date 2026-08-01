<?php
declare(strict_types=1);

require_once __DIR__ . '/Units.php';
require_once __DIR__ . '/StarFormatter.php';
require_once __DIR__ . '/ErrorHandler.php';
require_once __DIR__ . '/RenderingConstants.php';
require_once __DIR__ . '/ApiClient.php';

/**
 * IndexHelpers - Helper methods for index.php view
 *
 * Separates business logic from presentation by providing focused methods
 * for data fetching, transformation, and HTML generation.
 */
final class IndexHelpers
{
    /**
     * Hard cap on <area> elements in the no-JS star image map.
     *
     * Bounds page size regardless of zoom and label settings. Stars are emitted
     * brightest-first, so the cap drops the dimmest.
     */
    private const IMAGE_MAP_MAX_AREAS = 750;

    /** Shown wherever a positionless star's coordinates or distance would go. */
    public const UNKNOWN_POSITION_TEXT = 'unknown';

    /**
     * Does this star have a position that can be plotted?
     *
     * 25,342 stars in AT-HYG 4.0 have no usable parallax, so the API returns null for
     * `dist` and `x`/`y`/`z`. Casting those to float yields 0.0, which is a real
     * coordinate — Sol's — so before this check a positionless star was silently drawn at
     * the origin and, with `select_center=1`, re-centred the whole map there.
     *
     * Testing `x` alone is sufficient and that is a measured claim, not an assumption:
     * across all 2,837,262 rows, zero stars have `dist` without `x/y/z` or vice versa
     * (see .claude/features/NULL-COORDINATES.md). `array_key_exists` rather than `isset`,
     * because `isset` is false for a present-but-null key and would report a star as
     * positionless when the field is simply absent from a partial row.
     *
     * @param array<string, mixed> $star
     */
    public static function hasPosition(array $star): bool
    {
        return array_key_exists('x', $star) && $star['x'] !== null;
    }

    /** Catalog version this app's own links are stamped with (AT-HYG 4.0). */
    public const CATALOG_VERSION = 4;

    /**
     * Would this link have meant a different star before the AT-HYG 4 renumbering?
     *
     * `athyg.id` is the source catalog's row id, and AT-HYG 4 reassigned it: of the
     * 2,552,143 v3.3 stars still in the catalog, only 636 kept their id. So a link saved
     * before that migration now points at a different object — and it does so *silently*,
     * because **99.99% of v3 ids are also a valid, different v4 id**. Nothing 404s. The
     * page loads, the map centres, and it shows the wrong star.
     *
     * There is no way to tell from the id itself which catalog a link was written against,
     * so this does not try. Links this app generates now carry `c=4`; an id arriving
     * without that marker is ambiguous, and the honest response is to show the reader both
     * readings rather than pick one. Guessing is not safer: treating every unmarked id as
     * legacy would break every link saved since the migration in exactly the same way.
     *
     * Returns the star the id used to mean, or null when there is nothing to disambiguate:
     * the link is marked current, no v3 star maps to that id, or the mapping is to the very
     * same star (the 636 that kept their id — nothing changed for those).
     *
     * @param int $select_star Star ID from the URL
     * @param int $catalog_version The `c` parameter (0 when absent)
     * @param int $fic_names Fiction world ID
     * @return array|null The star this id named under AT-HYG v3.3
     */
    public static function resolveLegacyAlternative(int $select_star, int $catalog_version, int $fic_names): ?array
    {
        if (!self::shouldCheckLegacyId($select_star, $catalog_version)) {
            return null;
        }

        try {
            $legacy = ApiClient::instance()->queryLegacyStar($select_star, $fic_names);
        } catch (RuntimeException $e) {
            // A disambiguation hint is not worth failing the page over. If the API cannot
            // answer, the reader still gets the star they asked for.
            error_log("HYGMap: legacy id lookup failed for {$select_star}: {$e->getMessage()}");
            return null;
        }

        return self::legacyNoticeFor($select_star, $legacy);
    }

    /**
     * Is this link worth a legacy lookup at all?
     *
     * Split out from the fetch so the decision is unit-testable without an API. That is the
     * same move REPO-HYGIENE made for `MapRenderer`, for the same reason: a method that
     * reaches for `ApiClient::instance()` cannot be tested, and `ApiClient` is final.
     *
     * @param int $select_star Star ID from the URL
     * @param int $catalog_version The `c` parameter (0 when absent)
     */
    public static function shouldCheckLegacyId(int $select_star, int $catalog_version): bool
    {
        // Only the CURRENT version suppresses the check. An unrecognised marker (a future
        // c=5, or junk) is treated as unmarked rather than trusted, because this build
        // cannot vouch for a numbering it does not know.
        return $select_star > 0 && $catalog_version !== self::CATALOG_VERSION;
    }

    /**
     * Given what the legacy lookup returned, is there anything to tell the reader?
     *
     * @param int $select_star Star ID from the URL
     * @param array|null $legacy The API's answer for that id under AT-HYG v3.3
     * @return array|null The star to offer as the alternative reading
     */
    public static function legacyNoticeFor(int $select_star, ?array $legacy): ?array
    {
        if (!$legacy || !isset($legacy['id'])) {
            return null;
        }

        // 636 stars kept their id across the migration. For those an old link is still
        // correct, and a notice offering the same star would be noise.
        if ((int)$legacy['id'] === $select_star) {
            return null;
        }

        return $legacy;
    }

    /**
     * Fetch selected star and update view center if requested
     *
     * @param int $select_star Star ID to select
     * @param int $select_center Whether to center view on star (1 = yes)
     * @param int $fic_names Fiction world ID
     * @param string $unit Distance unit for conversion
     * @param array &$view_coords Reference to coordinates array to update
     * @param array|null $legacy_star The star this id named under AT-HYG v3.3, if any.
     *        Used only to improve the 404 message: when the current id resolves to nothing
     *        but the legacy id resolves to a star, the link is unambiguously an old one and
     *        we can say so instead of the generic "ids change" hedge.
     * @return array|null Selected star data or null
     */
    public static function fetchSelectedStar(int $select_star, int $select_center, int $fic_names, string $unit, array &$view_coords, ?array $legacy_star = null): ?array
    {
        if ($select_star <= 0) {
            return null;
        }

        try {
            $selected_star = ApiClient::instance()->queryStar($select_star, $fic_names);
            if (!$selected_star) {
                return null;
            }

            // Update view center if requested.
            //
            // Skipped for a positionless star: there is nothing to centre on, and casting
            // its null coordinates to float would move the view to 0,0,0 — Sol — as though
            // the user had asked to look at the Sun. The view stays where it was, and the
            // info panel says the position is unknown.
            if ($select_center == 1 && self::hasPosition($selected_star)) {
                $view_coords['x_c'] = Units::fromParsecs((float)$selected_star["x"], $unit);
                $view_coords['y_c'] = Units::fromParsecs((float)$selected_star["y"], $unit);
                $view_coords['z_c'] = Units::fromParsecs((float)$selected_star["z"], $unit);
            }

            return $selected_star;
        } catch (ApiNotFoundException $e) {
            // A bad id in the URL, or a bookmark from before a catalog renumbering. The
            // service is fine, so say what is actually wrong and answer 404.
            error_log("HYGMap: star not found: {$select_star} ({$e->getMessage()})");

            // The unambiguous case: nothing has this id in the current catalog, but a v3.3
            // star did. There is no competing reading to weigh, so name the star outright
            // rather than offering the generic "ids change" hedge.
            if ($legacy_star !== null && isset($legacy_star['id'])) {
                ErrorHandler::handleNotFound(sprintf(
                    'No star with id %d in the current catalog. This link predates the AT-HYG 4 '
                    . 'update, where it meant %s — now at id %d.',
                    $select_star,
                    (string)StarFormatter::getDisplayName($legacy_star, $fic_names),
                    (int)$legacy_star['id']
                ));
            }

            ErrorHandler::handleNotFound(sprintf(
                'No star with id %d. It may have been a stale link — star ids change when the '
                . 'catalog is updated. Try searching for the star by name instead.',
                $select_star
            ));
        } catch (RuntimeException $e) {
            ErrorHandler::handleError('Unable to retrieve star information: the star database is not responding.', $e);
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
    public static function buildSelectedStarData(?array $selected_star, int $fic_names, string $unit): array
    {
        if (!$selected_star) {
            return [
                'has_star' => false,
                'html' => '<em>No star selected.</em>'
            ];
        }

        // Calculate display name and links.
        //
        // The panel leads with the canonical name -- the same one the API's `display_name`,
        // the map overlay and the star table give, which means a fictional name wins
        // outright when a naming layer is on. This used to pass a hardcoded 0 here, so a
        // single page load could show four different names for one star.
        //
        // The catalog name is still worth showing and is still what the Wikipedia link has
        // to point at: "Alpha" is a Star Trek name, "37 Cet" is the thing Wikipedia knows.
        $star_name = StarFormatter::getDisplayName($selected_star, $fic_names);
        $catalog_name = StarFormatter::getDisplayName($selected_star, 0);
        $has_catalog_name = !empty($selected_star["proper"]) || !empty($selected_star["bf"]);
        $wikipedia_link = '<a href="https://en.wikipedia.org/w/index.php?title=Special%3ASearch&search=' .
                          urlencode($catalog_name) . '">' .
                          htmlspecialchars($catalog_name, ENT_QUOTES) . '</a>';

        if ($star_name === $catalog_name) {
            $display_name = $has_catalog_name
                ? $wikipedia_link
                : htmlspecialchars($star_name, ENT_QUOTES);
        } else {
            // A fictional name is in front. Keep the catalog name alongside it as context.
            $display_name = htmlspecialchars($star_name, ENT_QUOTES) . ' ('
                . ($has_catalog_name ? $wikipedia_link : htmlspecialchars($catalog_name, ENT_QUOTES))
                . ')';
        }

        // Link out to the fiction wiki whenever a fictional name is in play -- including
        // when it is the primary name, which is the common case with a layer on.
        $memory_alpha = '';
        if ($fic_names && !empty($selected_star["name"])) {
            $memory_alpha = '<br/>[ <a href="https://memory-alpha.fandom.com/wiki/Special:Search?query=' .
                           urlencode($selected_star["name"]) .
                           '&scope=internal&navigationSearch=true" target="_blank">Search Memory Alpha for this star system</a> ]<br/>';
        }

        // Calculate coordinates and links
        $selected_ra_deg = (float)$selected_star["ra"] * 360 / 24;
        $selected_dec_av = abs((float)$selected_star["dec"]);
        $selected_dec_ns = ((float)$selected_star["dec"] >= 0) ? 'North' : 'South';

        // Build SIMBAD URL using catalog IDs (preferred), proper name, or coordinates
        $simbad_url = null;
        if (!empty($selected_star["hip"])) {
            $simbad_url = 'https://simbad.cds.unistra.fr/simbad/sim-id?Ident=' . urlencode('HIP ' . $selected_star["hip"]);
        } elseif (!empty($selected_star["hd"])) {
            $simbad_url = 'https://simbad.cds.unistra.fr/simbad/sim-id?Ident=' . urlencode('HD ' . $selected_star["hd"]);
        } elseif (!empty($selected_star["proper"])) {
            $simbad_url = 'https://simbad.cds.unistra.fr/simbad/sim-id?Ident=' . urlencode($selected_star["proper"]);
        } else {
            $simbad_url = 'https://simbad.cds.unistra.fr/simbad/sim-coo?Coord='
                . urlencode($selected_ra_deg . ' ' . (float)$selected_star["dec"])
                . '&CooFrame=FK5&CooEpoch=2000&CooEqui=2000&Radius=1&Radius.unit=arcmin';
        }

        // A star with no usable parallax has no distance, no coordinates and no absolute
        // magnitude. Formatting those nulls would print "0.000" and "0.0000" — which is
        // not "unknown", it is a specific and wrong claim that the star sits at Sol. What
        // it does have is a real sky position (RA/Dec) and an apparent magnitude, both
        // measured, so those are still shown.
        $has_position = self::hasPosition($selected_star);
        $unknown = self::UNKNOWN_POSITION_TEXT;

        $distance_ui = $has_position
            ? number_format(Units::fromParsecs((float)$selected_star["dist"], $unit), 3)
            : $unknown;
        $x_ui = $has_position ? number_format(Units::fromParsecs((float)$selected_star["x"], $unit), 4) : $unknown;
        $y_ui = $has_position ? number_format(Units::fromParsecs((float)$selected_star["y"], $unit), 4) : $unknown;
        $z_ui = $has_position ? number_format(Units::fromParsecs((float)$selected_star["z"], $unit), 4) : $unknown;

        $x_ly = $has_position ? Units::toLightYears((float)$selected_star["x"], "pc") : null;
        $y_ly = $has_position ? Units::toLightYears((float)$selected_star["y"], "pc") : null;
        $z_ly = $has_position ? Units::toLightYears((float)$selected_star["z"], "pc") : null;

        return [
            'has_star' => true,
            'has_position' => $has_position,
            // Why the map cannot show it. The decision that these stars stay findable but
            // inert is only defensible if the UI says so — a result that silently does
            // nothing is worse than either hiding it or plotting it.
            'position_note' => $has_position
                ? null
                : 'No parallax measurement exists for this star, so its distance and position are unknown. It cannot be shown on the map.',
            'display_name' => $display_name,
            'absmag' => $selected_star["absmag"] === null
                ? $unknown
                : number_format((float)$selected_star["absmag"], 2),
            'spect' => $selected_star["spect"] ?? '',
            'distance_ui' => $distance_ui,
            'unit' => $unit,
            'x_ui' => $x_ui,
            'y_ui' => $y_ui,
            'z_ui' => $z_ui,
            'mag' => number_format((float)$selected_star["mag"], 2),
            'ra' => number_format((float)$selected_star["ra"], 4),
            'dec' => number_format((float)$selected_star["dec"], 4),
            'x_ly' => $x_ly,
            'y_ly' => $y_ly,
            'z_ly' => $z_ly,
            'selected_ra_deg' => $selected_ra_deg,
            'selected_dec_av' => $selected_dec_av,
            'selected_dec_ns' => $selected_dec_ns,
            'simbad_url' => $simbad_url,
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
    public static function buildMapHtml(
        string $image_type,
        int $image_size,
        array $baseParams,
        ?string $usemap = null
    ): string {
        // Build descriptive alt text for accessibility
        $x_c = $baseParams['x_c'] ?? 0;
        $y_c = $baseParams['y_c'] ?? 0;
        $z_c = $baseParams['z_c'] ?? 0;
        $xy_zoom = $baseParams['xy_zoom'] ?? 25;
        $altText = sprintf(
            'Star map centered at X:%.1f Y:%.1f Z:%.1f with zoom %.1f',
            $x_c, $y_c, $z_c, $xy_zoom
        );

        if ($image_type === 'stereo') {
            $leftSrc = 'map.php?' . http_build_query($baseParams + ['image_side' => 'left']);
            $rightSrc = 'map.php?' . http_build_query($baseParams + ['image_side' => 'right']);

            return '<img src="' . htmlspecialchars($leftSrc) . '" ' .
                   'alt="' . htmlspecialchars($altText . ' (left eye)') . '" ' .
                   'width="' . $image_size . '" height="' . $image_size . '">&nbsp;' .
                   '<img src="' . htmlspecialchars($rightSrc) . '" ' .
                   'alt="' . htmlspecialchars($altText . ' (right eye)') . '" ' .
                   'width="' . $image_size . '" height="' . $image_size . '">';
        } else {
            $src = 'map.php?' . http_build_query($baseParams);
            // usemap gives a no-JavaScript path to star selection; see buildStarImageMap.
            $usemapAttr = $usemap !== null
                ? ' usemap="#' . htmlspecialchars($usemap, ENT_QUOTES) . '"'
                : '';
            return '<img src="' . htmlspecialchars($src) . '" ' .
                   'alt="' . htmlspecialchars($altText) . '" ' .
                   'width="' . ($image_size * 2) . '" height="' . $image_size . '"' .
                   $usemapAttr . '>';
        }
    }

    /**
     * Build an HTML image map so stars can be selected without JavaScript.
     *
     * Clicking a star was implemented only as a JS click handler on the map image
     * (js/map-interactive.js), so with scripting off the classic UI's headline
     * interaction simply did not exist. The sidebar forms still worked, but the thing
     * the app is for did not.
     *
     * This reuses the screen positions already computed for the JS overlay — no new
     * geometry — and emits one <area> per star pointing at the same URL the click
     * handler would have navigated to. The JS path is untouched and still provides hover
     * tooltips and cursor feedback, which an image map cannot.
     *
     * Only non-stereo layouts get a map: in stereo mode the page shows two images and a
     * single set of screen coordinates does not address either of them unambiguously.
     *
     * Scope: only stars bright enough to be labelled, and never more than
     * IMAGE_MAP_MAX_AREAS of them. See the inline notes for the measurements behind that.
     *
     * @param array $stars Interactive star data from buildInteractiveStarData()
     * @param float $magLimitLabel Label magnitude limit; stars dimmer than this are skipped
     * @param string $name Map name, referenced by the img usemap attribute
     * @param int $radius Click radius in pixels; matches HOVER_RADIUS in the JS
     * @param int $maxAreas Hard cap on emitted areas, so page size stays bounded
     *
     * One behavioural difference worth knowing: the JS picks the *nearest* star within
     * the radius, while overlapping <area> elements resolve to the *first* in document
     * order. Stars are emitted brightest-first (the query orders by absolute magnitude),
     * so in a crowded field the no-JS path favours the brighter star — a defensible
     * tie-break, but not identical to the scripted one.
     * @return string HTML <map> element, or '' if there is nothing to map
     */
    public static function buildStarImageMap(
        array $stars,
        float $magLimitLabel = 8.0,
        string $name = 'starmap',
        int $radius = 15,
        int $maxAreas = self::IMAGE_MAP_MAX_AREAS
    ): string {
        if ($stars === []) {
            return '';
        }

        $areas = [];
        foreach ($stars as $star) {
            $id = (int)($star['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            // Only labelled stars get an <area>. Emitting one per rendered star cost
            // 10,000 elements and 1.3MB of HTML at wide zoom (measured 2026-07-29) --
            // more than a quarter of the page for stars the user cannot see a name for.
            // A star with a visible label is the one a no-JS user is trying to click.
            if ((float)($star['absmag'] ?? 99.0) >= $magLimitLabel) {
                continue;
            }

            if (count($areas) >= $maxAreas) {
                break;
            }

            $sx = (int)round((float)($star['sx'] ?? 0));
            $sy = (int)round((float)($star['sy'] ?? 0));
            $label = (string)($star['name'] ?? ('Star ' . $id));

            $areas[] = sprintf(
                '<area shape="circle" coords="%d,%d,%d" href="?select_star=%d&amp;select_center=1&amp;c='
                . self::CATALOG_VERSION . '" alt="%s" title="%s">',
                $sx,
                $sy,
                $radius,
                $id,
                htmlspecialchars($label, ENT_QUOTES),
                htmlspecialchars($label, ENT_QUOTES)
            );
        }

        if ($areas === []) {
            return '';
        }

        return '<map name="' . htmlspecialchars($name, ENT_QUOTES) . '">'
            . implode('', $areas)
            . '</map>';
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
    public static function fetchStarTableData(array $bbox, float $m_limit, float $m_limit_label, int $fic_names, string $unit, array $view_coords): array
    {
        try {
            $rows = ApiClient::instance()->queryAll($bbox, $m_limit, $fic_names, 'absmag asc');
        } catch (RuntimeException $e) {
            ErrorHandler::handleError('Unable to query stars in the current map area: the star database is not responding.', $e);
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

            $x_ui = Units::fromParsecs((float)$row["x"], $unit);
            $y_ui = Units::fromParsecs((float)$row["y"], $unit);
            $z_ui = Units::fromParsecs((float)$row["z"], $unit);

            $distance_from_center = sqrt(
                pow($x_ui - $view_coords['x_c'], 2) +
                pow($y_ui - $view_coords['y_c'], 2) +
                pow($z_ui - $view_coords['z_c'], 2)
            );

            $star_data[] = [
                'id' => $row['id'],
                'name' => StarFormatter::getDisplayName($row, $fic_names),
                'con' => $row["con"] ?? '',
                'spect' => $row["spect"] ?? '',
                'absmag' => number_format((float)$row["absmag"], 2),
                'distance' => number_format(Units::fromParsecs((float)$row["dist"], $unit), 3),
                'distance_from_center' => number_format($distance_from_center, 3),
                'x' => number_format($x_ui, 4),
                'y' => number_format($y_ui, 4),
                'z' => number_format($z_ui, 4),
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
    public static function buildSelectOptions(array $items, string $value_key, string $label_key): string
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
    public static function fetchProperStarOptions(): string
    {
        try {
            $rows = ApiClient::instance()->queryProperNames();
            return self::buildSelectOptions($rows, 'id', 'proper');
        } catch (RuntimeException $e) {
            ErrorHandler::handleError("Unable to load star catalog.", $e);
        }
    }

    /**
     * Fetch fictional star names for dropdown
     *
     * @param int $fic_names Fiction world ID
     * @return string HTML option tags
     */
    public static function fetchFictionalStarOptions(int $fic_names): string
    {
        try {
            $rows = ApiClient::instance()->queryFiction($fic_names);
            return self::buildSelectOptions($rows, 'star_id', 'name');
        } catch (RuntimeException $e) {
            ErrorHandler::handleError("Unable to load fictional star names.", $e);
        }
    }

    /**
     * Calculate screen coordinates for a star position
     *
     * This mirrors the logic in MapRenderer::screenCoords() to ensure consistency
     * between the rendered image and the interactive overlay.
     *
     * @param float $x Star X coordinate in UI units
     * @param float $y Star Y coordinate in UI units
     * @param float $z Star Z coordinate in UI units
     * @param float $x_c View center X
     * @param float $y_c View center Y
     * @param float $z_c View center Z (used for stereo offset)
     * @param float $xy_zoom Zoom level
     * @param float $z_zoom Z zoom level (used for stereo offset)
     * @param int $image_size Image size in pixels
     * @param string $image_type Image type ('stereo', 'normal', 'printable')
     * @param string $image_side Side for stereo ('left', 'right', '')
     * @return array [screen_x, screen_y]
     */
    public static function calculateScreenCoords(
        float $x, float $y, float $z,
        float $x_c, float $y_c, float $z_c,
        float $xy_zoom, float $z_zoom,
        int $image_size,
        string $image_type,
        string $image_side = ''
    ): array {
        $is_stereo = ($image_side === 'left' || $image_side === 'right');

        if ($is_stereo) {
            $screen_x = ($image_size / 2) - (($image_size / (2 * $xy_zoom)) * ($y - $y_c));
            $screen_y = ($image_size / 2) - (($image_size / (2 * $xy_zoom)) * ($x - $x_c));

            // Apply stereo offset
            $offset = RenderingConstants::STEREO_OFFSET_MULTIPLIER * (($z - $z_c) / $z_zoom);
            $screen_x += ($image_side === 'left') ? $offset : -$offset;
        } else {
            $screen_x = $image_size - (($image_size / (2 * $xy_zoom)) * ($y - $y_c));
            $screen_y = ($image_size / 2) - (($image_size / (2 * $xy_zoom)) * ($x - $x_c));
        }

        return [$screen_x, $screen_y];
    }

    /**
     * Build star data for interactive map overlay
     *
     * Returns an array of stars with their screen positions for use in JavaScript.
     * Includes all stars in the current view for tooltip and click interaction.
     *
     * @param array $bbox Bounding box for query
     * @param float $m_limit Magnitude limit for display
     * @param float $m_limit_label Magnitude limit for labels (unused, kept for API compatibility)
     * @param int $fic_names Fiction world ID
     * @param string $unit Distance unit
     * @param array $view_coords View center coordinates
     * @param float $xy_zoom XY zoom level
     * @param float $z_zoom Z zoom level
     * @param int $image_size Image size in pixels
     * @param string $image_type Image type
     * @return array Star data with screen positions
     */
    public static function buildInteractiveStarData(
        array $bbox,
        float $m_limit,
        float $m_limit_label,
        int $fic_names,
        string $unit,
        array $view_coords,
        float $xy_zoom,
        float $z_zoom,
        int $image_size,
        string $image_type
    ): array {
        try {
            $rows = ApiClient::instance()->queryAll($bbox, $m_limit, $fic_names, 'absmag asc');
        } catch (RuntimeException $e) {
            return [];
        }

        $stars = [];

        foreach ($rows as $row) {
            $x_ui = Units::fromParsecs((float)$row["x"], $unit);
            $y_ui = Units::fromParsecs((float)$row["y"], $unit);
            $z_ui = Units::fromParsecs((float)$row["z"], $unit);

            // Calculate screen position
            list($screen_x, $screen_y) = self::calculateScreenCoords(
                $x_ui, $y_ui, $z_ui,
                $view_coords['x_c'], $view_coords['y_c'], $view_coords['z_c'],
                $xy_zoom, $z_zoom,
                $image_size,
                $image_type
            );

            // Skip stars that are off-screen
            $max_x = ($image_type === 'stereo') ? $image_size : $image_size * 2;
            if ($screen_x < 0 || $screen_x > $max_x || $screen_y < 0 || $screen_y > $image_size) {
                continue;
            }

            $stars[] = [
                'id' => (int)$row['id'],
                'name' => StarFormatter::getDisplayName($row, $fic_names),
                'x' => round($x_ui, 3),
                'y' => round($y_ui, 3),
                'z' => round($z_ui, 3),
                'sx' => round($screen_x, 1),
                'sy' => round($screen_y, 1),
                // Named absmag, not mag: this is absolute magnitude, and the tooltip
                // labels it as such. The old key 'mag' invited the confusion.
                'absmag' => round((float)$row['absmag'], 2),
                'spect' => $row['spect'] ?? '',
            ];
        }

        return $stars;
    }
}

// =============================================================================
// Backwards-compatible function wrappers
// =============================================================================

function fetchSelectedStar(int $select_star, int $select_center, int $fic_names, string $unit, array &$view_coords, ?array $legacy_star = null): ?array
{
    return IndexHelpers::fetchSelectedStar($select_star, $select_center, $fic_names, $unit, $view_coords, $legacy_star);
}

function buildSelectedStarData(?array $selected_star, int $fic_names, string $unit): array
{
    return IndexHelpers::buildSelectedStarData($selected_star, $fic_names, $unit);
}

function resolveLegacyAlternative(int $select_star, int $catalog_version, int $fic_names): ?array
{
    return IndexHelpers::resolveLegacyAlternative($select_star, $catalog_version, $fic_names);
}

function buildMapHtml(string $image_type, int $image_size, array $baseParams, ?string $usemap = null): string
{
    return IndexHelpers::buildMapHtml($image_type, $image_size, $baseParams, $usemap);
}

function buildStarImageMap(array $stars, float $magLimitLabel = 8.0, string $name = 'starmap'): string
{
    return IndexHelpers::buildStarImageMap($stars, $magLimitLabel, $name);
}

function fetchStarTableData(array $bbox, float $m_limit, float $m_limit_label, int $fic_names, string $unit, array $view_coords): array
{
    return IndexHelpers::fetchStarTableData($bbox, $m_limit, $m_limit_label, $fic_names, $unit, $view_coords);
}

function buildSelectOptions(array $items, string $value_key, string $label_key): string
{
    return IndexHelpers::buildSelectOptions($items, $value_key, $label_key);
}

function fetchProperStarOptions(): string
{
    return IndexHelpers::fetchProperStarOptions();
}

function fetchFictionalStarOptions(int $fic_names): string
{
    return IndexHelpers::fetchFictionalStarOptions($fic_names);
}

function calculateScreenCoords(
    float $x, float $y, float $z,
    float $x_c, float $y_c, float $z_c,
    float $xy_zoom, float $z_zoom,
    int $image_size,
    string $image_type,
    string $image_side = ''
): array {
    return IndexHelpers::calculateScreenCoords($x, $y, $z, $x_c, $y_c, $z_c, $xy_zoom, $z_zoom, $image_size, $image_type, $image_side);
}

function buildInteractiveStarData(
    array $bbox,
    float $m_limit,
    float $m_limit_label,
    int $fic_names,
    string $unit,
    array $view_coords,
    float $xy_zoom,
    float $z_zoom,
    int $image_size,
    string $image_type
): array {
    return IndexHelpers::buildInteractiveStarData($bbox, $m_limit, $m_limit_label, $fic_names, $unit, $view_coords, $xy_zoom, $z_zoom, $image_size, $image_type);
}
