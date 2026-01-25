/**
 * Tests for URL state encoding/decoding
 */

import { describe, it, expect } from 'vitest'
import { encodeStateToURL, decodeStateFromURL, generateShareURL } from './urlState'

describe('urlState', () => {
  describe('encodeStateToURL', () => {
    it('should encode camera position', () => {
      const result = encodeStateToURL({ cx: 10, cy: 20, cz: 30 })
      expect(result).toContain('cx=10')
      expect(result).toContain('cy=20')
      expect(result).toContain('cz=30')
    })

    it('should encode camera target', () => {
      const result = encodeStateToURL({ tx: 5, ty: 10, tz: 15 })
      expect(result).toContain('tx=5')
      expect(result).toContain('ty=10')
      expect(result).toContain('tz=15')
    })

    it('should encode star ID', () => {
      const result = encodeStateToURL({ star: 12345 })
      expect(result).toContain('star=12345')
    })

    it('should encode unit', () => {
      const result = encodeStateToURL({ unit: 'ly' })
      expect(result).toContain('unit=ly')
    })

    it('should encode view mode', () => {
      const result = encodeStateToURL({ view: '3d-locked' })
      expect(result).toContain('view=3d-locked')
    })

    it('should encode coordinate system', () => {
      const result = encodeStateToURL({ coords: 'polar' })
      expect(result).toContain('coords=polar')
    })

    it('should round decimal values', () => {
      const result = encodeStateToURL({ cx: 10.123456789 })
      expect(result).toContain('cx=10.12')
    })

    it('should handle empty state', () => {
      const result = encodeStateToURL({})
      expect(result).toBe('')
    })
  })

  describe('decodeStateFromURL', () => {
    it('should decode camera position', () => {
      const result = decodeStateFromURL('?cx=10&cy=20&cz=30')
      expect(result.cx).toBe(10)
      expect(result.cy).toBe(20)
      expect(result.cz).toBe(30)
    })

    it('should decode camera target', () => {
      const result = decodeStateFromURL('?tx=5&ty=10&tz=15')
      expect(result.tx).toBe(5)
      expect(result.ty).toBe(10)
      expect(result.tz).toBe(15)
    })

    it('should decode star ID', () => {
      const result = decodeStateFromURL('?star=12345')
      expect(result.star).toBe(12345)
    })

    it('should decode unit', () => {
      const result = decodeStateFromURL('?unit=ly')
      expect(result.unit).toBe('ly')
    })

    it('should decode view mode', () => {
      const result = decodeStateFromURL('?view=3d-locked')
      expect(result.view).toBe('3d-locked')
    })

    it('should map legacy view values', () => {
      const legacyTopdown = decodeStateFromURL('?view=topdown')
      expect(legacyTopdown.view).toBe('3d-locked')

      const legacy3d = decodeStateFromURL('?view=3d')
      expect(legacy3d.view).toBe('3d-free')
    })

    it('should decode coordinate system', () => {
      const result = decodeStateFromURL('?coords=polar')
      expect(result.coords).toBe('polar')
    })

    it('should handle empty search string', () => {
      const result = decodeStateFromURL('')
      expect(result).toEqual({})
    })

    it('should handle invalid values', () => {
      const result = decodeStateFromURL('?cx=invalid&unit=invalid')
      // parseFloat('invalid') returns NaN
      expect(Number.isNaN(result.cx)).toBe(true)
      // Invalid unit values are ignored (only 'pc' or 'ly' accepted)
      expect(result.unit).toBeUndefined()
    })
  })

  describe('generateShareURL', () => {
    it('should generate full URL with state', () => {
      // Mock window.location
      const originalLocation = window.location
      Object.defineProperty(window, 'location', {
        value: { origin: 'https://example.com', pathname: '/map' },
        writable: true,
      })

      const result = generateShareURL({ cx: 10, cy: 20, cz: 30 })
      expect(result).toContain('https://example.com/map')
      expect(result).toContain('cx=10')

      // Restore
      Object.defineProperty(window, 'location', {
        value: originalLocation,
        writable: true,
      })
    })
  })

  describe('roundtrip', () => {
    it('should preserve non-default state through encode/decode cycle', () => {
      // Note: Default values (pc, 3d, cartesian) are NOT encoded to keep URLs short
      const original = {
        cx: 10.5,
        cy: -20.25,
        cz: 30,
        tx: 0,
        ty: 0,
        tz: 0,
        star: 12345,
        unit: 'ly' as const,      // Non-default
  view: '2d-flat' as const, // Non-default
        coords: 'polar' as const, // Non-default
      }

      const encoded = encodeStateToURL(original)
      const decoded = decodeStateFromURL('?' + encoded)

      // Numbers are rounded to 2 decimal places
      expect(decoded.cx).toBeCloseTo(10.5, 1)
      expect(decoded.cy).toBeCloseTo(-20.25, 1)
      expect(decoded.cz).toBe(30)
      expect(decoded.star).toBe(12345)
      expect(decoded.unit).toBe('ly')
  expect(decoded.view).toBe('2d-flat')
      expect(decoded.coords).toBe('polar')
    })

    it('should not include default values in encoded URL', () => {
      const original = {
        cx: 10,
        unit: 'pc' as const,   // Default - should not be included
  view: '3d-free' as const,   // Default - should not be included
        coords: 'cartesian' as const, // Default - should not be included
      }

      const encoded = encodeStateToURL(original)
      expect(encoded).not.toContain('unit=')
      expect(encoded).not.toContain('view=')
      expect(encoded).not.toContain('coords=')
      expect(encoded).toContain('cx=10')
    })
  })
})
