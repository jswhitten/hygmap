/**
 * Tests for coordinate system transformations
 */

import { describe, it, expect } from 'vitest'
import {
  galacticToScene,
  sceneToGalactic,
  calculateDistance,
  parsecsToLightYears,
  lightYearsToParsecs,
} from './coordinates'

describe('coordinates', () => {
  describe('galacticToScene', () => {
    it('should transform origin correctly', () => {
      const [x, y, z] = galacticToScene(0, 0, 0)
      expect(x).toBeCloseTo(0)
      expect(y).toBeCloseTo(0)
      expect(z).toBeCloseTo(0)
    })

    it('should transform galactic x to scene y', () => {
      const [x, y, z] = galacticToScene(10, 0, 0)
      expect(x).toBeCloseTo(0)
      expect(y).toBe(10)
      expect(z).toBeCloseTo(0)
    })

    it('should transform galactic y to negative scene x', () => {
      const [x, y, z] = galacticToScene(0, 10, 0)
      expect(x).toBe(-10)
      expect(y).toBeCloseTo(0)
      expect(z).toBeCloseTo(0)
    })

    it('should preserve z coordinate', () => {
      const [x, y, z] = galacticToScene(0, 0, 10)
      expect(x).toBeCloseTo(0)
      expect(y).toBeCloseTo(0)
      expect(z).toBe(10)
    })

    it('should handle negative values', () => {
      const [x, y, z] = galacticToScene(-5, -3, -2)
      expect(x).toBe(3)
      expect(y).toBe(-5)
      expect(z).toBe(-2)
    })
  })

  describe('sceneToGalactic', () => {
    it('should transform origin correctly', () => {
      const [x, y, z] = sceneToGalactic(0, 0, 0)
      expect(x).toBeCloseTo(0)
      expect(y).toBeCloseTo(0)
      expect(z).toBeCloseTo(0)
    })

    it('should be inverse of galacticToScene', () => {
      const original = [5, -3, 7] as [number, number, number]
      const scene = galacticToScene(...original)
      const back = sceneToGalactic(...scene)

      expect(back[0]).toBeCloseTo(original[0])
      expect(back[1]).toBeCloseTo(original[1])
      expect(back[2]).toBeCloseTo(original[2])
    })

    it('should handle arbitrary values', () => {
      // Test roundtrip with various values
      const testCases: [number, number, number][] = [
        [1, 2, 3],
        [-10, 20, -5],
        [0.5, -0.5, 0],
        [100, 100, 100],
      ]

      for (const original of testCases) {
        const scene = galacticToScene(...original)
        const back = sceneToGalactic(...scene)

        expect(back[0]).toBeCloseTo(original[0])
        expect(back[1]).toBeCloseTo(original[1])
        expect(back[2]).toBeCloseTo(original[2])
      }
    })
  })

  describe('calculateDistance', () => {
    it('should return 0 for same position', () => {
      const star = { id: 1, x: 5, y: 10, z: 15, display_name: 'Test' }
      expect(calculateDistance(star, star)).toBe(0)
    })

    it('should calculate distance correctly on x-axis', () => {
      const star1 = { id: 1, x: 0, y: 0, z: 0, display_name: 'A' }
      const star2 = { id: 2, x: 10, y: 0, z: 0, display_name: 'B' }
      expect(calculateDistance(star1, star2)).toBe(10)
    })

    it('should calculate distance correctly on y-axis', () => {
      const star1 = { id: 1, x: 0, y: 0, z: 0, display_name: 'A' }
      const star2 = { id: 2, x: 0, y: 5, z: 0, display_name: 'B' }
      expect(calculateDistance(star1, star2)).toBe(5)
    })

    it('should calculate distance correctly on z-axis', () => {
      const star1 = { id: 1, x: 0, y: 0, z: 0, display_name: 'A' }
      const star2 = { id: 2, x: 0, y: 0, z: 8, display_name: 'B' }
      expect(calculateDistance(star1, star2)).toBe(8)
    })

    it('should calculate 3D distance correctly', () => {
      const star1 = { id: 1, x: 0, y: 0, z: 0, display_name: 'A' }
      const star2 = { id: 2, x: 3, y: 4, z: 0, display_name: 'B' }
      expect(calculateDistance(star1, star2)).toBe(5) // 3-4-5 triangle
    })

    it('should handle negative coordinates', () => {
      const star1 = { id: 1, x: -5, y: -5, z: -5, display_name: 'A' }
      const star2 = { id: 2, x: 5, y: 5, z: 5, display_name: 'B' }
      const expected = Math.sqrt(10 * 10 + 10 * 10 + 10 * 10)
      expect(calculateDistance(star1, star2)).toBeCloseTo(expected)
    })

    it('should be commutative', () => {
      const star1 = { id: 1, x: 1, y: 2, z: 3, display_name: 'A' }
      const star2 = { id: 2, x: 4, y: 5, z: 6, display_name: 'B' }
      expect(calculateDistance(star1, star2)).toBe(calculateDistance(star2, star1))
    })
  })

  describe('parsecsToLightYears', () => {
    it('should convert 0 parsecs to 0 light-years', () => {
      expect(parsecsToLightYears(0)).toBe(0)
    })

    it('should convert 1 parsec to approximately 3.26 light-years', () => {
      expect(parsecsToLightYears(1)).toBeCloseTo(3.26156, 4)
    })

    it('should convert 10 parsecs correctly', () => {
      expect(parsecsToLightYears(10)).toBeCloseTo(32.6156, 3)
    })

    it('should handle negative values', () => {
      expect(parsecsToLightYears(-1)).toBeCloseTo(-3.26156, 4)
    })
  })

  describe('lightYearsToParsecs', () => {
    it('should convert 0 light-years to 0 parsecs', () => {
      expect(lightYearsToParsecs(0)).toBe(0)
    })

    it('should convert 3.26 light-years to approximately 1 parsec', () => {
      expect(lightYearsToParsecs(3.26156)).toBeCloseTo(1, 4)
    })

    it('should be inverse of parsecsToLightYears', () => {
      const parsecs = 42
      const ly = parsecsToLightYears(parsecs)
      expect(lightYearsToParsecs(ly)).toBeCloseTo(parsecs, 10)
    })
  })
})
