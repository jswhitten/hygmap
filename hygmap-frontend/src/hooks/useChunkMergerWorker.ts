/**
 * Hook to manage the chunk merger web worker.
 * Provides async interface for offloading merge operations to background thread.
 */

import { useRef, useEffect, useCallback } from 'react'
import type { Star } from '../types/star'
import type {
  MergeRequest,
  MergeResponse,
} from '../workers/chunkMerger.worker'

interface MergeResult {
  stars: Star[]
  stats: MergeResponse['stats']
}

interface UseChunkMergerWorkerReturn {
  merge: (
    chunks: Array<{ key: string; stars: Star[] }>,
    cameraPosition: { x: number; y: number; z: number },
    maxStars: number
  ) => Promise<MergeResult>
  isSupported: boolean
}

export function useChunkMergerWorker(): UseChunkMergerWorkerReturn {
  const workerRef = useRef<Worker | null>(null)
  const pendingRef = useRef<{
    resolve: (result: MergeResult) => void
    reject: (error: Error) => void
  } | null>(null)

  // Check if workers are supported
  const isSupported = typeof Worker !== 'undefined'

  useEffect(() => {
    if (!isSupported) return

    // Create worker using Vite's worker import syntax
    workerRef.current = new Worker(
      new URL('../workers/chunkMerger.worker.ts', import.meta.url),
      { type: 'module' }
    )

    workerRef.current.onmessage = (event: MessageEvent<MergeResponse>) => {
      const response = event.data
      if (response.type === 'merge-result' && pendingRef.current) {
        pendingRef.current.resolve({
          stars: response.stars,
          stats: response.stats,
        })
        pendingRef.current = null
      }
    }

    workerRef.current.onerror = (error) => {
      if (pendingRef.current) {
        pendingRef.current.reject(new Error(`Worker error: ${error.message}`))
        pendingRef.current = null
      }
    }

    return () => {
      workerRef.current?.terminate()
      workerRef.current = null
    }
  }, [isSupported])

  const merge = useCallback(
    (
      chunks: Array<{ key: string; stars: Star[] }>,
      cameraPosition: { x: number; y: number; z: number },
      maxStars: number
    ): Promise<MergeResult> => {
      return new Promise((resolve, reject) => {
        if (!workerRef.current) {
          // Fallback to sync merge if worker not available
          reject(new Error('Worker not available'))
          return
        }

        // Cancel any pending request
        if (pendingRef.current) {
          pendingRef.current.reject(new Error('Cancelled by new request'))
        }

        pendingRef.current = { resolve, reject }

        const request: MergeRequest = {
          type: 'merge',
          chunks,
          cameraPosition,
          maxStars,
        }

        workerRef.current.postMessage(request)
      })
    },
    []
  )

  return { merge, isSupported }
}
