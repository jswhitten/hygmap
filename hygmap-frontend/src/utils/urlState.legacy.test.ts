import { describe, it, expect } from 'vitest'
import {
  CURRENT_CATALOG_VERSION,
  decodeStateFromURL,
  encodeStateToURL,
  shouldCheckLegacyId,
} from './urlState'

/**
 * The catalog-version marker on star links.
 *
 * AT-HYG 4 renumbered every star, so a link saved before that migration points at a
 * different object — silently, because 99.99% of v3 ids are also a valid, different v4
 * id. The marker is the only thing that distinguishes a link written now from one written
 * before, and it is what makes the next renumbering survivable.
 */
describe('catalog version marker', () => {
  it('stamps generated star links with the current catalog', () => {
    const encoded = encodeStateToURL({ star: 7323 })
    const params = new URLSearchParams(encoded)

    expect(params.get('star')).toBe('7323')
    expect(params.get('c')).toBe(String(CURRENT_CATALOG_VERSION))
  })

  it('does not stamp a link that selects no star', () => {
    const params = new URLSearchParams(encodeStateToURL({ cx: 1, cy: 2, cz: 3 }))
    expect(params.get('c')).toBeNull()
  })

  it('round-trips the marker', () => {
    const state = decodeStateFromURL(encodeStateToURL({ star: 7323 }))
    expect(state.star).toBe(7323)
    expect(state.catalog).toBe(CURRENT_CATALOG_VERSION)
  })

  it('reads a link that has no marker', () => {
    const state = decodeStateFromURL('?star=7301')
    expect(state.star).toBe(7301)
    expect(state.catalog).toBeUndefined()
  })

  it('ignores a non-numeric marker rather than storing NaN', () => {
    expect(decodeStateFromURL('?star=1&c=abc').catalog).toBeUndefined()
  })
})

describe('shouldCheckLegacyId', () => {
  it('checks an unmarked star link', () => {
    // Every link saved before the marker existed is in this state.
    expect(shouldCheckLegacyId({ star: 7301 })).toBe(true)
  })

  it('does not check a link this app generated', () => {
    expect(shouldCheckLegacyId({ star: 7301, catalog: CURRENT_CATALOG_VERSION })).toBe(false)
  })

  it('treats an unrecognised marker as unmarked', () => {
    // This build cannot vouch for a numbering it does not know, so a future c=5 must not
    // silently suppress disambiguation.
    expect(shouldCheckLegacyId({ star: 7301, catalog: 5 })).toBe(true)
    expect(shouldCheckLegacyId({ star: 7301, catalog: 3 })).toBe(true)
  })

  it('has nothing to check when no star is selected', () => {
    expect(shouldCheckLegacyId({})).toBe(false)
    expect(shouldCheckLegacyId({ cx: 1 })).toBe(false)
  })

  it('round-trips its own output as not needing a check', () => {
    // The property that keeps the notice off correctly-generated links: anything this app
    // encodes must decode to "no check needed". If CURRENT_CATALOG_VERSION and the encoder
    // ever drift, every shared link starts showing an old-link notice.
    const state = decodeStateFromURL(encodeStateToURL({ star: 7323 }))
    expect(shouldCheckLegacyId(state)).toBe(false)
  })
})
