/**
 * Star domain logic: color mapping, size calculation, etc.
 */

import * as THREE from 'three'

/**
 * Spectral type to hex color mapping.
 *
 * The palette is intentionally NOT the same as the PHP map's — that renderer draws flat GD
 * colours on white, this one drives an emissive material on black. What must agree between
 * the two is the classification, not the hex value. `tests/fixtures/spectral-colors.json`
 * is the shared source of truth for that, and both renderers are tested against it.
 *
 * R shares K's colour and C/N/S share M's, matching `MapRenderer::getStarColor`. Carbon
 * (C, and the older R and N classifications) and S-type stars are cool and strongly
 * reddened; grouping them with K and M is the same call the 2D map already made.
 */
const SPECTRAL_COLORS: Record<string, string> = {
  O: '#9cc9ff', // Deep blue (hottest, ~30,000K)
  B: '#bfd5ff', // Blue-white
  A: '#e8f0ff', // Cool white (~10,000K)
  F: '#fff5da', // Warm white
  G: '#ffd45c', // Golden yellow (Sun-like, ~5,500K)
  K: '#ff9b4b', // Orange (cooler giants)
  R: '#ff9b4b', // Carbon star, older R classification — as K
  M: '#ff5c3c', // Ember red (coolest, ~3,000K)
  C: '#ff5c3c', // Carbon star — as M
  N: '#ff5c3c', // Carbon star, older N classification — as M
  S: '#ff5c3c', // Zirconium-oxide star — as M
}

/**
 * Extract the spectral class letter from a spectral type string.
 *
 * Mirrors `MapRenderer::getSpecClass`. The prefix handling is the part that matters: a
 * luminosity-class prefix of `sd` (subdwarf) or a leading space followed by `d` puts the
 * actual class letter at index 2, so reading character 0 yields `s` and the star falls
 * through to the default colour. 53 stars in the catalogue have a `spect` beginning `sd`.
 */
function getSpectralClass(spectrum?: string | null): string {
  if (!spectrum) return ''

  const first = spectrum.charAt(0)
  if (first === ' ' || first === 's') {
    return spectrum.charAt(2).toUpperCase()
  }
  return first.toUpperCase()
}

/**
 * Get star color based on spectral type
 */
export function getStarColor(spectrum?: string | null): string {
  return SPECTRAL_COLORS[getSpectralClass(spectrum)] || '#ffffff'
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
