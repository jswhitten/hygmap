import { describe, it, expect } from 'vitest'
import { projectStarToScene, projectSceneCoords, normalizeViewMode, DEFAULT_VIEW_MODE } from './viewMode'
import type { Star } from '../types/star'

const mockStar: Star = {
  id: 1,
  x: 10,
  y: -20,
  z: 5,
  display_name: 'Test Star',
}

describe('viewMode projection helpers', () => {
  it('should keep full 3D coordinates for non-flat modes', () => {
  const [x, y, z] = projectStarToScene(mockStar, '3d-free')
  const [lockedX, , lockedZ] = projectStarToScene(mockStar, '3d-locked')

    expect(x).toBeCloseTo(20)
    expect(y).toBeCloseTo(10)
    expect(z).toBeCloseTo(5)
    expect(lockedZ).toBeCloseTo(z)
    expect(lockedX).toBeCloseTo(x)
  })

  it('should flatten Z axis for 2d-flat mode', () => {
    const [, , z] = projectStarToScene(mockStar, '2d-flat')
    expect(z).toBe(0)
  })

  it('should project arbitrary scene coordinates', () => {
    const projected = projectSceneCoords([5, 6, 7], '2d-flat')
    expect(projected).toEqual([5, 6, 0])
  })

  it('should normalize legacy and default view modes', () => {
    expect(normalizeViewMode('topdown')).toBe('3d-locked')
    expect(normalizeViewMode('3d')).toBe('3d-free')
    expect(normalizeViewMode(null)).toBeUndefined()
    expect(DEFAULT_VIEW_MODE).toBe('3d-free')
  })
})
