/**
 * API client for SETI signal overlay
 */

import type { BoundingBox } from '../types/star'
import type { SignalListResponse, SignalType } from '../types/signal'
import { API_URL, fetchWithRetry } from './client'

export interface FetchSignalsOptions {
  bounds: BoundingBox
  limit?: number
  order?: 'time asc' | 'time desc' | 'name asc' | 'name desc' | 'frequency asc' | 'frequency desc'
  signalType?: SignalType
  signal?: AbortSignal
}

export async function fetchSignals({
  bounds,
  limit = 1000,
  order = 'time desc',
  signalType,
  signal,
}: FetchSignalsOptions): Promise<SignalListResponse> {
  const params = new URLSearchParams({
    xmin: bounds.xmin.toString(),
    xmax: bounds.xmax.toString(),
    ymin: bounds.ymin.toString(),
    ymax: bounds.ymax.toString(),
    zmin: bounds.zmin.toString(),
    zmax: bounds.zmax.toString(),
    limit: limit.toString(),
    order,
  })

  if (signalType) {
    params.set('signal_type', signalType)
  }

  const response = await fetchWithRetry(`${API_URL}/api/signals?${params.toString()}`, { signal })
  return response.json()
}
