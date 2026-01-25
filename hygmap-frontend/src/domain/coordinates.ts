/**
 * Coordinate system transformations and utilities
 *
 * Galactic coordinates (database):
 * - x: parsecs toward galactic center
 * - y: parsecs toward 90° galactic longitude (Cygnus)
 * - z: parsecs above galactic plane
 * - Origin: Sol (0, 0, 0)
 *
 * Scene coordinates (Three.js):
 * - Transformed for intuitive top-down view
 * - XY plane = galactic plane
 * - +Z = "up" from plane
 */

import type { Star, BoundingBox } from '../types/star'

/**
 * Convert galactic coordinates to Three.js scene coordinates
 */
export function galacticToScene(
  x: number,
  y: number,
  z: number
): [number, number, number] {
  return [-y, x, z]
}

/**
 * Convert Three.js scene coordinates back to galactic
 */
export function sceneToGalactic(
  sceneX: number,
  sceneY: number,
  sceneZ: number
): [number, number, number] {
  return [sceneY, -sceneX, sceneZ]
}

/**
 * Convert a Three.js scene-space bounding box to galactic-space bounds
 */
export function sceneBoundsToGalactic(bounds: BoundingBox): BoundingBox {
  return {
    xmin: bounds.ymin,
    xmax: bounds.ymax,
    ymin: -bounds.xmax,
    ymax: -bounds.xmin,
    zmin: bounds.zmin,
    zmax: bounds.zmax,
  }
}

/**
 * Calculate 3D distance between two stars in parsecs
 */
export function calculateDistance(star1: Star, star2: Star): number {
  const dx = star1.x - star2.x
  const dy = star1.y - star2.y
  const dz = star1.z - star2.z
  return Math.sqrt(dx * dx + dy * dy + dz * dz)
}

/**
 * Convert parsecs to light-years
 */
export function parsecsToLightYears(parsecs: number): number {
  return parsecs * 3.26156
}

/**
 * Convert light-years to parsecs
 */
export function lightYearsToParsecs(ly: number): number {
  return ly / 3.26156
}
