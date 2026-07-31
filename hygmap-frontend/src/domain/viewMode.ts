import type { PositionedStar } from './star'
import { galacticToScene } from './coordinates'

export type ViewMode = '2d-flat' | '3d-locked' | '3d-free'

export const DEFAULT_VIEW_MODE: ViewMode = '3d-free'

export const isLockedViewMode = (mode: ViewMode): boolean => mode !== '3d-free'
export const isFlatViewMode = (mode: ViewMode): boolean => mode === '2d-flat'

export function projectSceneCoords(
  coords: [number, number, number],
  viewMode: ViewMode
): [number, number, number] {
  if (viewMode === '2d-flat') {
    return [coords[0], coords[1], 0]
  }
  return coords
}

/**
 * Takes a PositionedStar rather than a Star on purpose.
 *
 * There is no sensible scene position for a star with no parallax, and every previous
 * attempt to produce one returned a NaN vector that moved the camera somewhere undefined.
 * Requiring the narrowed type pushes the decision out to the callers, where the right
 * answer is visible: leave the camera alone. See hasPosition() in domain/star.ts.
 */
export function projectStarToScene(
  star: PositionedStar,
  viewMode: ViewMode
): [number, number, number] {
  const sceneCoords = galacticToScene(star.x, star.y, star.z)
  return projectSceneCoords(sceneCoords, viewMode)
}

export function normalizeViewMode(value: string | null | undefined): ViewMode | undefined {
  if (!value) return undefined
  switch (value) {
    case '2d-flat':
    case '3d-locked':
    case '3d-free':
      return value
    case 'topdown':
      return '3d-locked'
    case '3d':
      return '3d-free'
    default:
      return undefined
  }
}
