/**
 * Tests for the chunk merger logic.
 * Note: We test the merge algorithm directly, not the worker communication,
 * since worker communication is harder to test in vitest.
 */

import { describe, it, expect } from 'vitest'
import type { Star } from '../types/star'

// Extract and test the merge algorithm directly
function mergeChunks(
  chunks: Array<{ key: string; stars: Star[] }>,
  cameraPosition: { x: number; y: number; z: number },
  maxStars: number
): { stars: Star[]; uniqueCount: number } {
  // Deduplicate by star ID
  const starMap = new Map<number, Star>()
  chunks.forEach((chunk) => {
    chunk.stars.forEach((star) => {
      if (!starMap.has(star.id)) {
        starMap.set(star.id, star)
      }
    })
  })

  const merged = Array.from(starMap.values())
  let limited = merged

  // Sort by distance and limit if needed
  if (merged.length > maxStars) {
    const { x: camX, y: camY, z: camZ } = cameraPosition

    const limitedEntries = merged
      .map((star) => {
        const dx = star.x - camX
        const dy = star.y - camY
        const dz = star.z - camZ
        return {
          star,
          distSq: dx * dx + dy * dy + dz * dz,
        }
      })
      .sort((a, b) => a.distSq - b.distSq)
      .slice(0, maxStars)

    limited = limitedEntries.map((entry) => entry.star)
  }

  return { stars: limited, uniqueCount: merged.length }
}

describe('chunkMerger', () => {
  const createStar = (id: number, x: number, y: number, z: number): Star => ({
    id,
    x,
    y,
    z,
    display_name: `Star ${id}`,
  })

  describe('deduplication', () => {
    it('should deduplicate stars by ID', () => {
      const star1 = createStar(1, 0, 0, 0)
      const star2 = createStar(2, 1, 1, 1)
      const star1Dupe = createStar(1, 0, 0, 0) // Same ID as star1

      const result = mergeChunks(
        [
          { key: 'chunk1', stars: [star1, star2] },
          { key: 'chunk2', stars: [star1Dupe] },
        ],
        { x: 0, y: 0, z: 0 },
        1000
      )

      expect(result.stars.length).toBe(2)
      expect(result.uniqueCount).toBe(2)
    })

    it('should keep first occurrence when deduplicating', () => {
      const star1Original = { ...createStar(1, 0, 0, 0), display_name: 'Original' }
      const star1Dupe = { ...createStar(1, 0, 0, 0), display_name: 'Duplicate' }

      const result = mergeChunks(
        [
          { key: 'chunk1', stars: [star1Original] },
          { key: 'chunk2', stars: [star1Dupe] },
        ],
        { x: 0, y: 0, z: 0 },
        1000
      )

      expect(result.stars.length).toBe(1)
      expect(result.stars[0].display_name).toBe('Original')
    })

    it('should handle empty chunks', () => {
      const star1 = createStar(1, 0, 0, 0)

      const result = mergeChunks(
        [
          { key: 'chunk1', stars: [star1] },
          { key: 'chunk2', stars: [] },
        ],
        { x: 0, y: 0, z: 0 },
        1000
      )

      expect(result.stars.length).toBe(1)
    })
  })

  describe('distance sorting and limiting', () => {
    it('should limit stars to maxStars when exceeded', () => {
      const stars = Array.from({ length: 100 }, (_, i) => createStar(i, i, 0, 0))

      const result = mergeChunks(
        [{ key: 'chunk1', stars }],
        { x: 0, y: 0, z: 0 },
        10
      )

      expect(result.stars.length).toBe(10)
      expect(result.uniqueCount).toBe(100)
    })

    it('should keep closest stars when limiting', () => {
      const farStar = createStar(1, 100, 0, 0)
      const closeStar = createStar(2, 1, 0, 0)
      const mediumStar = createStar(3, 50, 0, 0)

      const result = mergeChunks(
        [{ key: 'chunk1', stars: [farStar, closeStar, mediumStar] }],
        { x: 0, y: 0, z: 0 },
        2
      )

      expect(result.stars.length).toBe(2)
      expect(result.stars.map((s) => s.id)).toContain(2) // closeStar
      expect(result.stars.map((s) => s.id)).toContain(3) // mediumStar
      expect(result.stars.map((s) => s.id)).not.toContain(1) // farStar excluded
    })

    it('should calculate distance from camera position', () => {
      const star1 = createStar(1, 10, 0, 0) // Distance 10 from origin
      const star2 = createStar(2, 5, 0, 0) // Distance 5 from origin

      // With camera at origin, star2 is closer
      const resultFromOrigin = mergeChunks(
        [{ key: 'chunk1', stars: [star1, star2] }],
        { x: 0, y: 0, z: 0 },
        1
      )
      expect(resultFromOrigin.stars[0].id).toBe(2)

      // With camera at x=10, star1 is closer
      const resultFromX10 = mergeChunks(
        [{ key: 'chunk1', stars: [star1, star2] }],
        { x: 10, y: 0, z: 0 },
        1
      )
      expect(resultFromX10.stars[0].id).toBe(1)
    })

    it('should not limit when under maxStars', () => {
      const stars = Array.from({ length: 5 }, (_, i) => createStar(i, i, 0, 0))

      const result = mergeChunks(
        [{ key: 'chunk1', stars }],
        { x: 0, y: 0, z: 0 },
        10
      )

      expect(result.stars.length).toBe(5)
    })
  })

  describe('multiple chunks', () => {
    it('should merge stars from multiple chunks', () => {
      const chunk1Stars = [createStar(1, 0, 0, 0), createStar(2, 1, 0, 0)]
      const chunk2Stars = [createStar(3, 2, 0, 0), createStar(4, 3, 0, 0)]

      const result = mergeChunks(
        [
          { key: 'chunk1', stars: chunk1Stars },
          { key: 'chunk2', stars: chunk2Stars },
        ],
        { x: 0, y: 0, z: 0 },
        1000
      )

      expect(result.stars.length).toBe(4)
    })

    it('should handle large number of chunks', () => {
      const chunks = Array.from({ length: 50 }, (_, i) => ({
        key: `chunk${i}`,
        stars: [createStar(i, i, 0, 0)],
      }))

      const result = mergeChunks(chunks, { x: 0, y: 0, z: 0 }, 1000)

      expect(result.stars.length).toBe(50)
    })
  })
})
