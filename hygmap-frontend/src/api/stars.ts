/**
 * API client for fetching star data from backend
 *
 * Includes retry logic with exponential backoff for network failures.
 */

import type { Star, StarListResponse, StarDetailResponse, BoundingBox } from '../types/star'
import { API_URL, fetchWithRetry } from './client'

export { ApiError } from './client'

export interface FetchStarsOptions {
  bounds: BoundingBox
  limit?: number
  magMax?: number // LOD filter - only fetch stars brighter than this magnitude
  signal?: AbortSignal // For request cancellation
}

export async function fetchStars(options: FetchStarsOptions): Promise<StarListResponse> {
  const { bounds, limit = 20000, magMax, signal } = options

  const params = new URLSearchParams({
    xmin: bounds.xmin.toString(),
    xmax: bounds.xmax.toString(),
    ymin: bounds.ymin.toString(),
    ymax: bounds.ymax.toString(),
    zmin: bounds.zmin.toString(),
    zmax: bounds.zmax.toString(),
    limit: limit.toString(),
  })

  if (magMax !== undefined) {
    params.set('mag_max', magMax.toString())
  }

  const response = await fetchWithRetry(`${API_URL}/api/stars?${params}`, { signal })
  return response.json()
}

export async function fetchStarById(starId: number): Promise<StarDetailResponse> {
  const response = await fetchWithRetry(`${API_URL}/api/stars/${starId}`)
  return response.json()
}

/**
 * Resolve an AT-HYG v3.3 star id to the star it names in the current catalog.
 *
 * AT-HYG 4 renumbered every star, so a link saved before that migration points somewhere
 * else -- silently, because 99.99% of v3 ids are also a valid, different v4 id. Returns
 * null when nothing maps to that legacy id, which includes the ordinary case of a link
 * that was always a current id.
 *
 * Deliberately swallows failures: this only ever adds an informational notice, and a
 * broken hint must not stop the star the user asked for from loading.
 */
export async function fetchStarByLegacyId(v3Id: number): Promise<Star | null> {
  try {
    const response = await fetch(`${API_URL}/api/stars/legacy/${v3Id}`)
    if (!response.ok) return null
    const body = await response.json()
    return body?.data ?? null
  } catch {
    return null
  }
}

/**
 * Search stars by name or catalog ID.
 *
 * `worldId` scopes the search to a fictional universe, so "vulcan" finds Keid when Star
 * Trek is selected. It defaults to 0 (real names only) and there is no UI to change it
 * yet -- the universe selector is a separate ROADMAP item. This is the plumbing for it.
 */
export async function searchStars(
  query: string,
  limit = 20,
  worldId = 0,
): Promise<StarListResponse> {
  const params = new URLSearchParams({
    q: query,
    limit: limit.toString(),
    world_id: worldId.toString(),
  })

  const response = await fetchWithRetry(`${API_URL}/api/stars/search?${params}`)
  return response.json()
}
