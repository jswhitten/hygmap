/**
 * Tests for direct coordinate entry parsing.
 */

import { describe, it, expect } from 'vitest'
import { parseCoordinateInput, MAX_COORDINATE_PC } from './coordinateInput'

const at = (x: string, y: string, z: string) => ({ x, y, z })

describe('parseCoordinateInput', () => {
  it('accepts plain parsec coordinates', () => {
    const result = parseCoordinateInput(at('1.5', '-2', '0'), 'pc')
    expect(result.ok).toBe(true)
    if (result.ok) expect(result.value.galacticPc).toEqual([1.5, -2, 0])
  })

  it('accepts the origin', () => {
    const result = parseCoordinateInput(at('0', '0', '0'), 'pc')
    expect(result.ok).toBe(true)
  })

  it('tolerates surrounding whitespace', () => {
    const result = parseCoordinateInput(at('  4 ', ' 5', '6  '), 'pc')
    expect(result.ok).toBe(true)
    if (result.ok) expect(result.value.galacticPc).toEqual([4, 5, 6])
  })

  it('accepts exponent notation', () => {
    const result = parseCoordinateInput(at('1e3', '0', '0'), 'pc')
    expect(result.ok).toBe(true)
    if (result.ok) expect(result.value.galacticPc[0]).toBe(1000)
  })

  it('converts light-years to parsecs', () => {
    // The form shows whatever unit the app is displaying, so the values must be converted
    // before they reach the camera, which works in parsecs.
    const result = parseCoordinateInput(at('3.26156', '0', '0'), 'ly')
    expect(result.ok).toBe(true)
    if (result.ok) expect(result.value.galacticPc[0]).toBeCloseTo(1, 5)
  })

  describe('rejections', () => {
    it('rejects a blank field and says which', () => {
      const result = parseCoordinateInput(at('1', '', '3'), 'pc')
      expect(result.ok).toBe(false)
      if (!result.ok) {
        expect(result.field).toBe('y')
        expect(result.error).toContain('Y')
      }
    })

    it('rejects non-numeric text and quotes it back', () => {
      // Number('abc') is NaN, but Number('') is 0 — the blank check has to come first, and
      // this pins that ordering.
      const result = parseCoordinateInput(at('abc', '0', '0'), 'pc')
      expect(result.ok).toBe(false)
      if (!result.ok) {
        expect(result.field).toBe('x')
        expect(result.error).toContain('abc')
      }
    })

    it('rejects Infinity', () => {
      const result = parseCoordinateInput(at('Infinity', '0', '0'), 'pc')
      expect(result.ok).toBe(false)
    })

    it('rejects coordinates beyond the mapped region', () => {
      const result = parseCoordinateInput(at(String(MAX_COORDINATE_PC + 1), '0', '0'), 'pc')
      expect(result.ok).toBe(false)
      if (!result.ok) expect(result.error).toContain('outside the mapped region')
    })

    it('accepts a coordinate exactly on the limit', () => {
      const result = parseCoordinateInput(at(String(MAX_COORDINATE_PC), '0', '0'), 'pc')
      expect(result.ok).toBe(true)
    })

    it('applies the limit after unit conversion, not before', () => {
      // 20,000 ly is about 6,131 pc — inside the limit. Checking the bound against the raw
      // number would wrongly reject it.
      const result = parseCoordinateInput(at('20000', '0', '0'), 'ly')
      expect(result.ok).toBe(true)

      // And the error message quotes the limit in the unit the user is typing.
      const tooFar = parseCoordinateInput(at('40000', '0', '0'), 'ly')
      expect(tooFar.ok).toBe(false)
      if (!tooFar.ok) expect(tooFar.error).toContain('ly')
    })

    it('checks every axis, not just the first', () => {
      const result = parseCoordinateInput(at('0', '0', String(MAX_COORDINATE_PC + 5)), 'pc')
      expect(result.ok).toBe(false)
      if (!result.ok) expect(result.field).toBe('z')
    })
  })
})
