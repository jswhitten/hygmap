/**
 * Star domain logic: color mapping, size calculation, etc.
 */

import * as THREE from 'three'

/**
 * Spectral type to hex color mapping
 */
const SPECTRAL_COLORS: Record<string, string> = {
  O: '#9cc9ff', // Deep blue (hottest, ~30,000K)
  B: '#bfd5ff', // Blue-white
  A: '#e8f0ff', // Cool white (~10,000K)
  F: '#fff5da', // Warm white
  G: '#ffd45c', // Golden yellow (Sun-like, ~5,500K)
  K: '#ff9b4b', // Orange (cooler giants)
  M: '#ff5c3c', // Ember red (coolest, ~3,000K)
}

/**
 * Get star color based on spectral type
 */
export function getStarColor(spectrum?: string | null): string {
  if (!spectrum) return '#ffffff'

  const type = spectrum.trim().charAt(0).toUpperCase()
  return SPECTRAL_COLORS[type] || '#ffffff'
}

/**
 * Get star color as THREE.Color
 */
export function getStarColorThree(spectrum?: string | null): THREE.Color {
  return new THREE.Color(getStarColor(spectrum))
}

/**
 * Calculate star visual size based on absolute magnitude
 * Brighter stars (lower/negative absmag) are larger
 * Uses exponential scaling for more realistic magnitude difference
 */
export function getStarSize(absmag?: number | null): number {
  const mag = absmag ?? 5 // Default to Sun-like if unknown
  // Exponential scaling: each 5 magnitudes is 10x difference
  // Base size 0.25, scaled by 10^(-mag/7.5) for visual appeal
  // This gives roughly:
  //   Arcturus (mag -0.3): ~0.38
  //   Sun (mag 4.83): ~0.12
  //   Red dwarf (mag 12): ~0.04
  const size = 0.25 * Math.pow(10, -mag / 7.5)
  return Math.max(0.03, Math.min(0.8, size))
}

/**
 * Determine if a star should show a glow effect
 * Only bright stars get glow to reduce rendering cost
 */
export function shouldShowGlow(absmag?: number | null): boolean {
  if (absmag === null || absmag === undefined) return false
  return absmag < 2 // Only stars brighter than magnitude 2
}
