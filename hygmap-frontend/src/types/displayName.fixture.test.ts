/**
 * Fixture-driven display-name tests for getStarDisplayName.
 *
 * Reads the shared table at tests/fixtures/display-names.json — the same file the API
 * and PHP suites read — so all three implementations of "what is this star called?" are
 * held to one set of expectations instead of three private ones. An audit on 2026-07-29
 * found they disagree and that no suite could see it, because each tested one catalog
 * field at a time.
 *
 * The fixture is mounted at /fixtures by the Makefile.
 */

import { describe, it, expect } from 'vitest'
import { readFileSync, existsSync } from 'node:fs'
import { getStarDisplayName } from './star'
import type { StarDetail } from './star'

const FIXTURE_PATH = '/fixtures/display-names.json'

interface FixtureCase {
  name: string
  star: Record<string, unknown>
  expected: string
  world_id?: number
}

// A missing fixture is a hard failure, not a skip. This file is one of three that hold the
// display-name rule to a single source of truth; if it quietly skips, DISPLAY-NAME-CANON's
// guarantee is void in this tier and nothing says so. That is how TestProdComposeTrustSetting
// went unexecuted in CI for two audit cycles.
if (!existsSync(FIXTURE_PATH)) {
  throw new Error(
    `shared fixture not mounted at ${FIXTURE_PATH} — see the test-frontend target in the Makefile`,
  )
}

const cases: FixtureCase[] = JSON.parse(readFileSync(FIXTURE_PATH, 'utf-8')).cases

/** Defaults so each fixture case only states the fields it exercises. */
const ROW_DEFAULTS = {
  id: 0,
  proper: null,
  bayer: null,
  flam: null,
  con: null,
  hip: null,
  hd: null,
  hr: null,
  gj: null,
  cns5: null,
  gaia: null,
  tyc: null,
  // Deliberately absent: display_name. These cases exercise the client-side fallback
  // chain, which is what can drift from the server's.
}

describe('getStarDisplayName against the shared fixture', () => {
  it('the fixture actually contains cases', () => {
    // An empty or malformed fixture would otherwise produce a green run with no tests.
    expect(cases.length).toBeGreaterThan(10)
  })

  for (const testCase of cases) {
    const expected = testCase.expected
    const label = testCase.name

    it(label, () => {
      const star = { ...ROW_DEFAULTS, ...testCase.star } as Record<string, unknown>
      // The API only populates the fictional name when a world was requested, so the
      // presence of `name` is the signal. Mirror that precondition here.
      if (!testCase.world_id) {
        star.name = null
      }
      expect(getStarDisplayName(star as unknown as StarDetail)).toBe(expected)
    })
  }

  it('prefers the server-computed display_name when present', () => {
    // The server is authoritative; the local chain is only a fallback. If this stops
    // holding, the two frontends can disagree with the API and with each other.
    const star = {
      ...ROW_DEFAULTS,
      id: 7301,
      gj: '1',
      hip: '439',
      display_name: 'HIP 439',
    } as unknown as StarDetail

    expect(getStarDisplayName(star)).toBe('HIP 439')
  })
})
