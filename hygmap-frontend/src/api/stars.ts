/**
 * API client for fetching star data from backend
 *
 * Includes retry logic with exponential backoff for network failures.
 */

import type { StarListResponse, StarDetailResponse, BoundingBox } from '../types/star'
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

export async function searchStars(query: string, limit = 20): Promise<StarListResponse> {
  const params = new URLSearchParams({
    q: query,
    limit: limit.toString(),
  })

  const response = await fetchWithRetry(`${API_URL}/api/stars/search?${params}`)
  return response.json()
}
