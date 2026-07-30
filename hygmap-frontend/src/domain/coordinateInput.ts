/**
 * Parsing and validation for direct coordinate entry.
 *
 * Kept out of the component so the rules are testable on their own — the interesting part of
 * "jump to X/Y/Z" is not the form, it is what counts as a valid destination.
 *
 * Coordinates are entered in GALACTIC coordinates, the same frame the HUD and the star info
 * panel display: +X toward the galactic centre, +Y toward galactic longitude 90 degrees
 * (Cygnus), +Z toward the north galactic pole, with Sol at the origin. That is what a user
 * reading a star's coordinates off the panel will type back in, so accepting anything else
 * would be a trap.
 *
 * Values are in whichever unit the app is currently displaying (parsecs or light-years).
 */

import { lightYearsToParsecs, parsecsToLightYears } from './coordinates'

/**
 * Maximum distance from Sol, in parsecs, that the app can actually show.
 *
 * Mirrors MAX_COORDINATE_VALUE in hygmap-api/app/api/stars.py: the API rejects a bounding
 * box beyond this, so jumping further produces a view that cannot load. Better to refuse
 * with an explanation than to fly the camera somewhere permanently empty.
 */
export const MAX_COORDINATE_PC = 10000

export interface ParsedCoordinates {
  /** Galactic coordinates in parsecs, whatever unit was typed. */
  galacticPc: [number, number, number]
}

export type CoordinateParseResult =
  | { ok: true; value: ParsedCoordinates }
  | { ok: false; error: string; field?: 'x' | 'y' | 'z' }

const AXES = ['x', 'y', 'z'] as const

/**
 * Parse and validate three coordinate strings.
 *
 * @param raw   the three field values as typed
 * @param unit  the unit those values are in
 */
export function parseCoordinateInput(
  raw: { x: string; y: string; z: string },
  unit: 'pc' | 'ly'
): CoordinateParseResult {
  const parsed: number[] = []

  for (const axis of AXES) {
    const text = raw[axis].trim()

    if (text === '') {
      return { ok: false, error: `Enter a ${axis.toUpperCase()} coordinate.`, field: axis }
    }

    // Number() accepts '' and whitespace as 0, which is why the blank check comes first.
    // It also accepts '1e3' and '-2.5', both of which are legitimate here.
    const value = Number(text)
    if (!Number.isFinite(value)) {
      return {
        ok: false,
        error: `${axis.toUpperCase()} must be a number — "${text}" is not one.`,
        field: axis,
      }
    }

    parsed.push(value)
  }

  const galacticPc = parsed.map((v) => (unit === 'ly' ? lightYearsToParsecs(v) : v)) as [
    number,
    number,
    number,
  ]

  for (let i = 0; i < AXES.length; i++) {
    if (Math.abs(galacticPc[i]) > MAX_COORDINATE_PC) {
      const limit = unit === 'ly' ? Math.floor(parsecsToLightYears(MAX_COORDINATE_PC)) : MAX_COORDINATE_PC
      return {
        ok: false,
        error: `${AXES[i].toUpperCase()} is outside the mapped region — keep it within ±${limit.toLocaleString()} ${unit}.`,
        field: AXES[i],
      }
    }
  }

  return { ok: true, value: { galacticPc } }
}
