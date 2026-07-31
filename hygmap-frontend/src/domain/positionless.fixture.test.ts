/**
 * Fixture-driven tests for hasPosition and the distance helper.
 *
 * Reads tests/fixtures/positionless-stars.json — the same file the PHP and API suites read
 * — so all three stacks agree on which stars can be plotted. 25,342 catalogue stars have no
 * usable parallax and the API returns null for their coordinates; React typed them non-null
 * until 2026-07-31, so selecting one drove the camera and the distance arithmetic to NaN.
 *
 * The fixture is mounted at /fixtures by the Makefile.
 */

import { describe, it, expect } from 'vitest'
import { readFileSync, existsSync } from 'node:fs'
import { hasPosition } from './star'
import { calculateDistance } from './coordinates'
import type { Star } from '../types/star'

const FIXTURE_PATH = '/fixtures/positionless-stars.json'

interface FixtureCase {
  name: string
  star: Record<string, unknown>
  has_position: boolean
  mappable: boolean
  why: string
}

// A missing fixture fails rather than skips — see the display-name fixture tests.
if (!existsSync(FIXTURE_PATH)) {
  throw new Error(
    `shared fixture not mounted at ${FIXTURE_PATH} — see the test-frontend target in the Makefile`,
  )
}

const cases: FixtureCase[] = JSON.parse(readFileSync(FIXTURE_PATH, 'utf-8')).cases

/** Defaults so each fixture case only states the fields it exercises. */
const STAR_DEFAULTS = {
  id: 0,
  proper: null,
  bayer: null,
  flam: null,
  con: null,
  spect: null,
  absmag: null,
  x: null,
  y: null,
  z: null,
  display_name: '',
}

const build = (testCase: FixtureCase): Star =>
  ({ ...STAR_DEFAULTS, ...testCase.star }) as unknown as Star

describe('hasPosition against the shared fixture', () => {
  it('the fixture actually contains cases', () => {
    expect(cases.length).toBeGreaterThan(3)
  })

  for (const testCase of cases) {
    it(`${testCase.name} — hasPosition is ${testCase.has_position}`, () => {
      expect(hasPosition(build(testCase))).toBe(testCase.has_position)
    })
  }
})

describe('the origin is a position, not the absence of one', () => {
  /**
   * The single most important case in the fixture. The bug being fixed cast null to 0.0
   * and drew positionless stars at Sol; a helper written with a truthiness check would
   * classify Sol itself as positionless and reintroduce the confusion from the other side.
   */
  it('Sol at 0,0,0 has a position', () => {
    const sol = build(cases.find((c) => c.star.proper === 'Sol')!)
    expect(hasPosition(sol)).toBe(true)
  })

  it('a star with null coordinates does not', () => {
    expect(hasPosition(build({ star: {}, ...({} as FixtureCase) }))).toBe(false)
  })

  it('a partially positionless star is treated as positionless', () => {
    // Never occurs in the catalogue — measured, 0 rows have one coordinate without the
    // others — but a partial row must not read as plottable and produce a NaN axis.
    const partial = { ...STAR_DEFAULTS, x: 1, y: 2, z: null } as unknown as Star
    expect(hasPosition(partial)).toBe(false)
  })
})

describe('calculateDistance with a positionless star', () => {
  const positioned = build(cases.find((c) => c.has_position && c.star.proper === 'Sirius')!)
  const unpositioned = build(cases.find((c) => !c.has_position)!)

  it('returns null rather than NaN', () => {
    // NaN was the old behaviour and the reason this is null: it propagates silently
    // through every comparison and formats as "NaN" in the UI.
    const result = calculateDistance(positioned, unpositioned)
    expect(result).toBeNull()
    expect(result).not.toBeNaN()
  })

  it('returns null whichever side is positionless', () => {
    expect(calculateDistance(unpositioned, positioned)).toBeNull()
    expect(calculateDistance(unpositioned, unpositioned)).toBeNull()
  })

  it('still measures between two positioned stars', () => {
    const sol = build(cases.find((c) => c.star.proper === 'Sol')!)
    const distance = calculateDistance(sol, positioned)
    expect(distance).not.toBeNull()
    // Sirius is ~2.64 pc from Sol; the fixture carries its real coordinates.
    expect(distance!).toBeCloseTo(2.96, 1)
  })
})
