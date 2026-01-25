/**
 * Web Worker for chunk merging and star deduplication.
 * Offloads CPU-intensive work from main thread to keep UI responsive.
 */

import type { Star } from '../types/star'

// Message types for worker communication
export interface MergeRequest {
  type: 'merge'
  chunks: Array<{ key: string; stars: Star[] }>
  cameraPosition: { x: number; y: number; z: number } // Galactic coordinates
  maxStars: number
}

export interface MergeResponse {
  type: 'merge-result'
  stars: Star[]
  stats: {
    inputChunks: number
    inputStars: number
    uniqueStars: number
    outputStars: number
    dedupeTimeMs: number
    sortTimeMs: number
    totalTimeMs: number
  }
}

export type WorkerMessage = MergeRequest
export type WorkerResponse = MergeResponse

// Worker message handler
self.onmessage = (event: MessageEvent<WorkerMessage>) => {
  const message = event.data

  if (message.type === 'merge') {
    const result = mergeChunks(message)
    self.postMessage(result)
  }
}

function mergeChunks(request: MergeRequest): MergeResponse {
  const startTime = performance.now()

  // Count input stars
  let inputStars = 0
  request.chunks.forEach((chunk) => {
    inputStars += chunk.stars.length
  })

  // Deduplicate by star ID
  const starMap = new Map<number, Star>()
  request.chunks.forEach((chunk) => {
    chunk.stars.forEach((star) => {
      if (!starMap.has(star.id)) {
        starMap.set(star.id, star)
      }
    })
  })

  const dedupeTime = performance.now() - startTime
  const merged = Array.from(starMap.values())

  let sortTime = 0
  let limited = merged

  // Sort by distance and limit if needed
  if (merged.length > request.maxStars) {
    const sortStart = performance.now()
    const { x: camX, y: camY, z: camZ } = request.cameraPosition

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
      .slice(0, request.maxStars)

    limited = limitedEntries.map((entry) => entry.star)
    sortTime = performance.now() - sortStart
  }

  const totalTime = performance.now() - startTime

  return {
    type: 'merge-result',
    stars: limited,
    stats: {
      inputChunks: request.chunks.length,
      inputStars,
      uniqueStars: merged.length,
      outputStars: limited.length,
      dedupeTimeMs: dedupeTime,
      sortTimeMs: sortTime,
      totalTimeMs: totalTime,
    },
  }
}
