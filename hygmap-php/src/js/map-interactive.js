/**
 * HYGMap Interactive Map
 *
 * Provides hover tooltips, click-to-select, and shift+click distance measurement
 * for stars on the map image.
 */
(function() {
    'use strict';

    // Configuration
    const HOVER_RADIUS = 15;  // Pixels - how close mouse must be to star center
    const TOOLTIP_OFFSET_X = 15;
    const TOOLTIP_OFFSET_Y = 10;

    // State
    let currentStar = null;
    let measureFromStar = null;

    // Get data from PHP
    const data = window.HYGMapData || { stars: [], selectedStarId: 0, unit: 'pc' };
    const stars = data.stars;
    const selectedStarId = data.selectedStarId;
    const unit = data.unit;

    // DOM elements
    const container = document.getElementById('map-container');
    const tooltip = document.getElementById('star-tooltip');
    const distanceDisplay = document.getElementById('distance-display');
    const distanceValue = document.getElementById('distance-value');
    const clearDistanceBtn = document.getElementById('clear-distance');

    if (!container || !tooltip || stars.length === 0) {
        return; // Exit if elements not found or no star data
    }

    // Get the map image(s)
    const mapImages = container.querySelectorAll('img');
    if (mapImages.length === 0) return;

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
     * Calculate 3D distance between two stars
     * @param {object} star1
     * @param {object} star2
     * @returns {number} - Distance in current units
     */
    function calculate3DDistance(star1, star2) {
        const dx = star1.x - star2.x;
        const dy = star1.y - star2.y;
        const dz = star1.z - star2.z;
        return Math.sqrt(dx * dx + dy * dy + dz * dz);
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
        html += `Mag: ${star.mag}<br>`;
        html += `Coords: ${star.x}, ${star.y}, ${star.z}`;

        if (star.spect) {
            html += `<br>Spectral: ${escapeHtml(star.spect)}`;
        }

        // Show distance from selected star if one is selected
        if (selectedStarId && selectedStarId !== star.id) {
            const selectedStar = stars.find(s => s.id === selectedStarId);
            if (selectedStar) {
                const dist = calculate3DDistance(star, selectedStar);
                html += `<br><em>${dist.toFixed(3)} ${unit} from selected</em>`;
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
     * Show distance measurement between two stars
     * @param {object} star1
     * @param {object} star2
     */
    function showDistance(star1, star2) {
        const dist = calculate3DDistance(star1, star2);
        distanceValue.textContent = `${dist.toFixed(3)} (${star1.name} to ${star2.name})`;
        distanceDisplay.style.display = 'block';
    }

    /**
     * Clear distance measurement
     */
    function clearDistance() {
        measureFromStar = null;
        distanceDisplay.style.display = 'none';
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
        // For stereo mode, we need to handle left/right images differently
        // Left image is index 0, right image is index 1
        // The star screen coords are calculated for non-stereo mode,
        // so for stereo we'd need separate coords. For now, tooltips work
        // best with normal/printable modes.

        img.addEventListener('mousemove', function(e) {
            const pos = getMousePosition(e, img);
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
            img.style.cursor = 'default';
        });

        img.addEventListener('click', function(e) {
            const pos = getMousePosition(e, img);
            const star = findNearestStar(pos.x, pos.y);

            if (!star) return;

            if (e.shiftKey) {
                // Shift+click: measure distance
                if (measureFromStar) {
                    // Second star clicked - show distance
                    showDistance(measureFromStar, star);
                    measureFromStar = null;
                } else if (selectedStarId) {
                    // Use currently selected star as first point
                    const selectedStar = stars.find(s => s.id === selectedStarId);
                    if (selectedStar && selectedStar.id !== star.id) {
                        showDistance(selectedStar, star);
                    }
                } else {
                    // No star selected, use this as first point
                    measureFromStar = star;
                    distanceValue.textContent = `Click another star to measure from ${star.name}`;
                    distanceDisplay.style.display = 'block';
                }
            } else {
                // Regular click: select and center star
                window.location.href = `?select_star=${star.id}&select_center=1`;
            }
        });
    });

    // Clear distance button handler
    if (clearDistanceBtn) {
        clearDistanceBtn.addEventListener('click', clearDistance);
    }

    // Keyboard shortcut: Escape to clear distance measurement
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            clearDistance();
        }
    });

})();
