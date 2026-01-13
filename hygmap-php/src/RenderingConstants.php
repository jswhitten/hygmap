<?php
declare(strict_types=1);

/**
 * Constants for map rendering
 *
 * Contains all configurable values used for rendering the star map,
 * including size thresholds, colors, and visual parameters.
 */
final class RenderingConstants
{
    // --- Star Size and Magnitude Thresholds ---

    /** Base size for star rendering */
    public const STAR_SIZE_BASE = 20;

    /** Factor for calculating star sizes */
    public const STAR_SIZE_FACTOR = 2;

    /** Minimum star size in pixels */
    public const STAR_SIZE_MIN = 2;

    /** Stars dimmer than this magnitude use minimum size */
    public const MAG_THRESHOLD_DIM = 8.0;

    /** Medium brightness star threshold */
    public const MAG_THRESHOLD_MEDIUM = 6.0;

    /** Bright stars get larger labels above this threshold */
    public const MAG_THRESHOLD_BRIGHT = 3.0;

    /** Skip labels in dense fields above this magnitude */
    public const MAG_THRESHOLD_DENSE_FIELD = 5.0;

    // --- Star Label Sizes ---

    /** Small label size (font size) */
    public const LABEL_SIZE_SMALL = 1;

    /** Medium label size (font size) */
    public const LABEL_SIZE_MEDIUM = 2;

    /** Large label size (font size) */
    public const LABEL_SIZE_LARGE = 4;

    // --- Label Overlap Detection ---

    /** X overlap threshold divisor (xy_zoom / 50) */
    public const LABEL_OVERLAP_X_DIVISOR = 50;

    /** Y overlap threshold divisor (xy_zoom / 20) */
    public const LABEL_OVERLAP_Y_DIVISOR = 20;

    // --- Connecting Lines ---

    /** Distance factor for very close stars */
    public const CONNECTING_LINE_CLOSE_FACTOR = 0.5;

    /** Distance factor for medium distance stars */
    public const CONNECTING_LINE_MID_FACTOR = 0.75;

    // --- Stereoscopic 3D Rendering ---

    /** Horizontal offset multiplier for 3D effect */
    public const STEREO_OFFSET_MULTIPLIER = 4;

    // --- Signal Visualization ---

    /** Outer arc diameter for signal indicator */
    public const SIGNAL_ARC_OUTER = 18;

    /** Middle arc diameter for signal indicator */
    public const SIGNAL_ARC_MIDDLE = 14;

    /** Inner arc diameter for signal indicator */
    public const SIGNAL_ARC_INNER = 10;

    /** Half-angle of signal arc in degrees */
    public const SIGNAL_ARC_ANGLE = 45;

    // --- Selected Star Indicator ---

    /** Selection box half-width in pixels */
    public const SELECTED_STAR_BOX_SIZE = 20;

    // --- Color Definitions (RGB arrays) ---

    /** @var array{int, int, int} */
    public const COLOR_WHITE = [255, 255, 255];

    /** @var array{int, int, int} */
    public const COLOR_GREY = [204, 204, 204];

    /** @var array{int, int, int} */
    public const COLOR_DARK_GREY = [102, 102, 102];

    /** @var array{int, int, int} */
    public const COLOR_GREEN = [0, 150, 50];

    /** @var array{int, int, int} */
    public const COLOR_RED = [255, 64, 0];

    /** @var array{int, int, int} */
    public const COLOR_ORANGE = [255, 128, 0];

    /** @var array{int, int, int} */
    public const COLOR_LIGHT_YELLOW = [255, 255, 160];

    /** @var array{int, int, int} */
    public const COLOR_YELLOW = [255, 255, 0];

    /** @var array{int, int, int} */
    public const COLOR_LIGHT_BLUE = [128, 204, 255];

    /** @var array{int, int, int} */
    public const COLOR_BLUE = [64, 128, 255];

    /** @var array{int, int, int} */
    public const COLOR_DARK_BLUE = [0, 64, 128];

    /** @var array{int, int, int} */
    public const COLOR_BLACK = [0, 0, 0];
}
