/**
 * Fixture-driven spectral-colour tests for getStarColor.
 *
 * Reads the shared table at tests/fixtures/spectral-colors.json — the same file the PHP
 * suite reads — so the two renderers are held to one classification instead of two private
 * ones. An audit on 2026-07-31 found they disagreed for roughly 460 stars: React read
 * character 0 blindly (so 'sdF8:' classified as 's') and had no entry for the carbon and
 * S-type classes R, C, N and S.
 *
 * The fixture pins the CLASS and a colour NAME, not a hex value — the two UIs deliberately
 * use different palettes. What must never diverge again is which stars are grouped
 * together. See the fixture's own comment for why.
 *
 * The fixture is mounted at /fixtures by the Makefile.
 */

import { describe, it, expect } from 'vitest'
import { readFileSync, existsSync } from 'node:fs'
import { getStarColor } from './star'

const FIXTURE_PATH = '/fixtures/spectral-colors.json'

interface FixtureCase {
  spect: string | null
  class: string
  color: string
  why: string
}

// A missing fixture is a hard failure, not a skip. A guard that quietly passes when the
// thing it guards is absent is how TestProdComposeTrustSetting went unexecuted in CI for
// two audit cycles.
if (!existsSync(FIXTURE_PATH)) {
  throw new Error(
    `shared fixture not mounted at ${FIXTURE_PATH} — see the test-frontend target in the Makefile`,
  )
}

const cases: FixtureCase[] = JSON.parse(readFileSync(FIXTURE_PATH, 'utf-8')).cases

/**
 * Which fixture colour name each React hex belongs to.
 *
 * Deliberately inverted from the implementation rather than duplicating the mapping: this
 * says "these hexes mean red", so the test asserts the grouping the fixture cares about
 * without pinning React to the PHP palette.
 */
const HEX_TO_NAME: Record<string, string> = {
  '#9cc9ff': 'blue',
  '#bfd5ff': 'lightblue',
  '#fff5da': 'lightyellow',
  '#ffd45c': 'yellow',
  '#ff9b4b': 'orange',
  '#ff5c3c': 'red',
  '#e8f0ff': 'white', // React's cool white for class A; PHP falls through to plain white
  '#ffffff': 'white',
}

describe('getStarColor against the shared fixture', () => {
  it('the fixture actually contains cases', () => {
    expect(cases.length).toBeGreaterThan(10)
  })

  for (const testCase of cases) {
    const label = `${JSON.stringify(testCase.spect)} -> ${testCase.color}${
      testCase.why ? ` (${testCase.why})` : ''
    }`

    it(label, () => {
      const hex = getStarColor(testCase.spect)
      expect(HEX_TO_NAME[hex], `unmapped colour ${hex}`).toBeDefined()
      expect(HEX_TO_NAME[hex]).toBe(testCase.color)
    })
  }
})

describe('spectral classes that must share a colour', () => {
  /**
   * The specific regression: these four fell through to white in React while the 2D map
   * drew them orange or red. Asserted as equalities rather than literals so a future
   * palette change cannot silently split them apart again.
   */
  it('R is coloured as K', () => {
    expect(getStarColor('R5')).toBe(getStarColor('K1V'))
  })

  it('C, N and S are coloured as M', () => {
    expect(getStarColor('C5II')).toBe(getStarColor('M5.5Ve'))
    expect(getStarColor('N0')).toBe(getStarColor('M5.5Ve'))
    expect(getStarColor('S4,2')).toBe(getStarColor('M5.5Ve'))
  })

  it('a subdwarf is coloured by its real class, not by the prefix', () => {
    expect(getStarColor('sdF8:')).toBe(getStarColor('F5IV'))
    expect(getStarColor('sdB')).toBe(getStarColor('B8Ia'))
    expect(getStarColor(' dM4')).toBe(getStarColor('M5.5Ve'))
  })

  it('an uppercase S is an S-type star, not a subdwarf prefix', () => {
    // PHP tests for lowercase 's' only. If React upper-cased before the prefix check,
    // every S-type star would read its class from index 2 and come out wrong.
    expect(getStarColor('S4,2')).not.toBe('#ffffff')
  })
})
