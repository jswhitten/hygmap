/**
 * HYGMap Interactive Map
 *
 * Provides hover tooltips with star info and distances, click-to-select,
 * cursor coordinate display, and keyboard navigation.
 */
(function() {
    'use strict';

    // Configuration
    const HOVER_RADIUS = 15;  // Pixels - how close mouse must be to star center
    const TOOLTIP_OFFSET_X = 15;
    const TOOLTIP_OFFSET_Y = 10;

    // Navigation configuration
    const PAN_FRACTION = 0.25;   // Pan by 25% of current zoom
    const ZOOM_FACTOR = 1.5;     // Zoom in/out by 50%
    const MIN_ZOOM = 0.1;
    const MAX_ZOOM = 10000;

    // State
    let currentStar = null;

    // Get data from PHP
    const data = window.HYGMapData || { stars: [], selectedStarId: 0, unit: 'pc', view: {} };
    const stars = data.stars;
    const selectedStarId = data.selectedStarId;
    const unit = data.unit;
    const view = data.view || {};

    // DOM elements
    const container = document.getElementById('map-container');
    const tooltip = document.getElementById('star-tooltip');
    const cursorCoordsEl = document.getElementById('cursor-coords');

    if (!container || !tooltip) {
        return; // Exit if elements not found
    }

    // Get the map image(s)
    const mapImages = container.querySelectorAll('img');
    if (mapImages.length === 0) return;

    /**
     * Convert screen coordinates to galactic coordinates
     * @param {number} screenX - Screen X position
     * @param {number} screenY - Screen Y position
     * @returns {object} - {x, y} galactic coordinates
     */
    function screenToGalactic(screenX, screenY) {
        const imageSize = view.imageSize || 600;
        const xy_zoom = view.xy_zoom || 25;
        const x_c = view.x_c || 0;
        const y_c = view.y_c || 0;

        // Reverse the transformation from MapRenderer::screenCoords()
        // screen_x = imageSize - ((imageSize / (2 * xy_zoom)) * (y - y_c))
        // screen_y = (imageSize / 2) - ((imageSize / (2 * xy_zoom)) * (x - x_c))
        const scale = imageSize / (2 * xy_zoom);
        const galY = y_c + (imageSize - screenX) / scale;
        const galX = x_c + ((imageSize / 2) - screenY) / scale;

        return { x: galX, y: galY };
    }

    /**
     * Find the nearest star to the given screen coordinates
     * @param {number} x - Screen X relative to image
     * @param {number} y - Screen Y relative to image
     * @returns {object|null} - Star object or null if none within range
     */
    function findNearestStar(x, y) {
        let nearest = null;
        let nearestDist = HOVER_RADIUS;

        for (const star of stars) {
            const dx = star.sx - x;
            const dy = star.sy - y;
            const dist = Math.sqrt(dx * dx + dy * dy);

            if (dist < nearestDist) {
                nearestDist = dist;
                nearest = star;
            }
        }

        return nearest;
    }

    /**
     * Calculate 3D distance between two points
     * @param {object} point1 - {x, y, z}
     * @param {object} point2 - {x, y, z}
     * @returns {number} - Distance in current units
     */
    function calculate3DDistance(point1, point2) {
        const dx = point1.x - point2.x;
        const dy = point1.y - point2.y;
        const dz = point1.z - point2.z;
        return Math.sqrt(dx * dx + dy * dy + dz * dz);
    }

    /**
     * Calculate distance from Sun (origin)
     * @param {object} star - Star with x, y, z coordinates
     * @returns {number} - Distance from Sun
     */
    function distanceFromSun(star) {
        return Math.sqrt(star.x * star.x + star.y * star.y + star.z * star.z);
    }

    /**
     * Show tooltip at the given position
     * @param {object} star - Star data object
     * @param {number} pageX - Page X coordinate
     * @param {number} pageY - Page Y coordinate
     */
    function showTooltip(star, pageX, pageY) {
        // Build tooltip content
        let html = `<strong>${escapeHtml(star.name)}</strong><br>`;
        html += `Mag: ${star.mag}`;
        if (star.spect) {
            html += ` • ${escapeHtml(star.spect)}`;
        }
        html += `<br>Coords: ${star.x}, ${star.y}, ${star.z}`;

        // Distance from Sun
        const sunDist = distanceFromSun(star);
        html += `<br>From Sol: ${sunDist.toFixed(3)} ${unit}`;

        // Show distance from selected star if one is selected
        if (selectedStarId && selectedStarId !== star.id) {
            const selectedStar = stars.find(s => s.id === selectedStarId);
            if (selectedStar) {
                const dist = calculate3DDistance(star, selectedStar);
                html += `<br><em>From selected: ${dist.toFixed(3)} ${unit}</em>`;
            }
        }

        tooltip.innerHTML = html;
        tooltip.style.display = 'block';

        // Position tooltip, keeping it on screen
        const rect = container.getBoundingClientRect();
        let left = pageX - rect.left - window.scrollX + TOOLTIP_OFFSET_X;
        let top = pageY - rect.top - window.scrollY + TOOLTIP_OFFSET_Y;

        // Adjust if tooltip would go off right edge
        const tooltipWidth = tooltip.offsetWidth;
        if (left + tooltipWidth > rect.width) {
            left = pageX - rect.left - window.scrollX - tooltipWidth - TOOLTIP_OFFSET_X;
        }

        // Adjust if tooltip would go off bottom
        const tooltipHeight = tooltip.offsetHeight;
        if (top + tooltipHeight > rect.height) {
            top = pageY - rect.top - window.scrollY - tooltipHeight - TOOLTIP_OFFSET_Y;
        }

        tooltip.style.left = left + 'px';
        tooltip.style.top = top + 'px';
    }

    /**
     * Hide the tooltip
     */
    function hideTooltip() {
        tooltip.style.display = 'none';
        currentStar = null;
    }

    /**
     * Update cursor coordinates display
     * @param {number} screenX - Screen X position
     * @param {number} screenY - Screen Y position
     */
    function updateCursorCoords(screenX, screenY) {
        if (!cursorCoordsEl) return;

        const coords = screenToGalactic(screenX, screenY);
        cursorCoordsEl.textContent = `X: ${coords.x.toFixed(2)}, Y: ${coords.y.toFixed(2)}`;
    }

    /**
     * Clear cursor coordinates display
     */
    function clearCursorCoords() {
        if (cursorCoordsEl) {
            cursorCoordsEl.textContent = '';
        }
    }

    /**
     * Escape HTML special characters
     * @param {string} str
     * @returns {string}
     */
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Get mouse position relative to image
     * @param {MouseEvent} e
     * @param {HTMLElement} img
     * @returns {object} - {x, y}
     */
    function getMousePosition(e, img) {
        const rect = img.getBoundingClientRect();
        return {
            x: e.clientX - rect.left,
            y: e.clientY - rect.top
        };
    }

    // Event handlers for each map image
    mapImages.forEach((img, index) => {
        img.addEventListener('mousemove', function(e) {
            const pos = getMousePosition(e, img);

            // Update cursor coordinates
            updateCursorCoords(pos.x, pos.y);

            // Find and show star tooltip
            const star = findNearestStar(pos.x, pos.y);

            if (star) {
                if (star !== currentStar) {
                    currentStar = star;
                    showTooltip(star, e.pageX, e.pageY);
                } else {
                    // Update position as mouse moves
                    showTooltip(star, e.pageX, e.pageY);
                }
                img.style.cursor = 'pointer';
            } else {
                hideTooltip();
                img.style.cursor = 'crosshair';
            }
        });

        img.addEventListener('mouseleave', function() {
            hideTooltip();
            clearCursorCoords();
            img.style.cursor = 'default';
        });

        img.addEventListener('click', function(e) {
            const pos = getMousePosition(e, img);
            const star = findNearestStar(pos.x, pos.y);

            if (star) {
                // Click: select and center star
                window.location.href = `?select_star=${star.id}&select_center=1`;
            }
        });
    });

    // =========================================================================
    // Keyboard Navigation
    // =========================================================================

    /**
     * Navigate to new view coordinates
     * @param {object} params - Navigation parameters to change
     */
    function navigateTo(params) {
        const url = new URL(window.location.href);

        // Update parameters
        for (const [key, value] of Object.entries(params)) {
            url.searchParams.set(key, value);
        }

        // Preserve selected star if any
        if (selectedStarId && !params.select_star) {
            url.searchParams.set('select_star', selectedStarId);
        }

        window.location.href = url.toString();
    }

    /**
     * Pan the view in a direction
     * @param {string} direction - 'up', 'down', 'left', 'right', 'zup', 'zdown'
     */
    function pan(direction) {
        const xy_zoom = view.xy_zoom || 25;
        const z_zoom = view.z_zoom || 25;
        const panAmount = xy_zoom * PAN_FRACTION;
        const zPanAmount = z_zoom * PAN_FRACTION;

        let x_c = view.x_c || 0;
        let y_c = view.y_c || 0;
        let z_c = view.z_c || 0;

        switch (direction) {
            case 'up':    x_c += panAmount; break;
            case 'down':  x_c -= panAmount; break;
            case 'left':  y_c += panAmount; break;
            case 'right': y_c -= panAmount; break;
            case 'zup':   z_c += zPanAmount; break;
            case 'zdown': z_c -= zPanAmount; break;
        }

        navigateTo({ x_c, y_c, z_c });
    }

    /**
     * Zoom the view in or out
     * @param {string} direction - 'in' or 'out'
     */
    function zoom(direction) {
        let xy_zoom = view.xy_zoom || 25;
        let z_zoom = view.z_zoom || 25;

        if (direction === 'in') {
            xy_zoom = Math.max(MIN_ZOOM, xy_zoom / ZOOM_FACTOR);
            z_zoom = Math.max(MIN_ZOOM, z_zoom / ZOOM_FACTOR);
        } else {
            xy_zoom = Math.min(MAX_ZOOM, xy_zoom * ZOOM_FACTOR);
            z_zoom = Math.min(MAX_ZOOM, z_zoom * ZOOM_FACTOR);
        }

        navigateTo({ xy_zoom, z_zoom });
    }

    /**
     * Return to Sol (origin)
     */
    function goHome() {
        navigateTo({ x_c: 0, y_c: 0, z_c: 0 });
    }

    /**
     * Handle keyboard navigation
     * @param {KeyboardEvent} e
     */
    function handleKeyboard(e) {
        // Ignore if user is typing in an input field
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
            return;
        }

        let handled = true;

        switch (e.key) {
            // Pan with arrow keys
            case 'ArrowUp':    pan('up'); break;
            case 'ArrowDown':  pan('down'); break;
            case 'ArrowLeft':  pan('left'); break;
            case 'ArrowRight': pan('right'); break;

            // Z-axis pan with Page Up/Down
            case 'PageUp':   pan('zup'); break;
            case 'PageDown': pan('zdown'); break;

            // Zoom with +/- (and = for + without shift)
            case '+':
            case '=': zoom('in'); break;
            case '-': zoom('out'); break;

            // Home key returns to Sol
            case 'Home': goHome(); break;

            default:
                handled = false;
        }

        if (handled) {
            e.preventDefault();
        }
    }

    // Add keyboard event listener
    document.addEventListener('keydown', handleKeyboard);

})();
