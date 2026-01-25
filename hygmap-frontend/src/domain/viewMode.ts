import type { Star } from '../types/star'
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

export function projectStarToScene(star: Star, viewMode: ViewMode): [number, number, number] {
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
