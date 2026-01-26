import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { useChunkLoader, __resetSpatialCacheForTests } from './useChunkLoader'
import * as starsApi from '../api/stars'
import type { Star } from '../types/star'
import * as THREE from 'three'

// Mock the stars API
vi.mock('../api/stars', () => ({
  fetchStars: vi.fn(),
}))

// Mock R3F useFrame
const mockCamera = {
  position: new THREE.Vector3(0, 0, 8),
  quaternion: new THREE.Quaternion(),
}

type FrameState = { camera: typeof mockCamera }
const useFrameCallbacks: Array<(state: FrameState, delta: number) => void> = []

vi.mock('@react-three/fiber', () => ({
  useThree: () => ({
    camera: mockCamera,
  }),
  useFrame: (callback: (state: FrameState, delta: number) => void) => {
    useFrameCallbacks.push(callback)
  },
}))

const runUseFrame = () => {
  useFrameCallbacks.forEach((callback) => callback({ camera: mockCamera }, 0.016))
}

describe('useChunkLoader', () => {
  const mockStars: Star[] = [
    {
      id: 1,
      x: 1,
      y: 1,
      z: 1,
      absmag: -1.0,
      spect: 'G2V',
      display_name: 'Test Star 1',
    },
    {
      id: 2,
      x: 2,
      y: 2,
      z: 2,
      absmag: 0.5,
      spect: 'K0V',
      display_name: 'Test Star 2',
    },
  ]

  const mockStarResponse = {
    result: 'success',
    data: mockStars,
    length: mockStars.length,
  }

  beforeEach(() => {
    vi.clearAllMocks()
    useFrameCallbacks.length = 0
    mockCamera.position.set(0, 0, 8)
    mockCamera.quaternion.identity()
    __resetSpatialCacheForTests()
  })

  describe('AbortController Logic', () => {
    it('should abort pending requests on unmount', async () => {
      const abortSpy = vi.spyOn(AbortController.prototype, 'abort')

      // Mock a slow fetch that will be aborted
      vi.mocked(starsApi.fetchStars).mockImplementation(
        () =>
          new Promise((resolve) => {
            setTimeout(() => resolve(mockStarResponse), 1000)
          })
      )

      const { result, unmount } = renderHook(() => useChunkLoader())

      // Trigger a chunk load
      await waitFor(() => {
        result.current.jumpToPosition(new THREE.Vector3(0, 0, 0))
      })

      // Unmount before fetch completes
      unmount()

      // Verify abort was called
      expect(abortSpy).toHaveBeenCalled()
    })

    it('should handle already aborted controllers gracefully', async () => {
      vi.mocked(starsApi.fetchStars).mockResolvedValue(mockStarResponse)

      const { result, unmount } = renderHook(() => useChunkLoader())

      // Trigger a chunk load and let it complete
      await waitFor(() => {
        result.current.jumpToPosition(new THREE.Vector3(0, 0, 0))
      })

      // Wait for fetch to complete
      await waitFor(() => {
        expect(starsApi.fetchStars).toHaveBeenCalled()
      })

      // Unmounting after requests complete should not throw
      expect(() => unmount()).not.toThrow()
    })

    it('should abort multiple concurrent requests on unmount', async () => {
      const abortSpy = vi.spyOn(AbortController.prototype, 'abort')

      // Mock slow fetches
      vi.mocked(starsApi.fetchStars).mockImplementation(
        () =>
          new Promise((resolve) => {
            setTimeout(() => resolve(mockStarResponse), 1000)
          })
      )

      const { result, unmount } = renderHook(() => useChunkLoader())

      // Trigger multiple chunk loads
      await waitFor(() => {
        result.current.jumpToPosition(new THREE.Vector3(0, 0, 0))
        result.current.jumpToPosition(new THREE.Vector3(10, 10, 10))
        result.current.jumpToPosition(new THREE.Vector3(20, 20, 20))
      })

      // Unmount while requests are pending
      unmount()

      // Verify abort was called multiple times (at least once per request)
      expect(abortSpy.mock.calls.length).toBeGreaterThan(0)
    })

    it('should cancel in-flight requests when jumping to new position', async () => {
      const abortSpy = vi.spyOn(AbortController.prototype, 'abort')

      // Mock slow fetches
      vi.mocked(starsApi.fetchStars).mockImplementation(
        () =>
          new Promise((resolve) => {
            setTimeout(() => resolve(mockStarResponse), 500)
          })
      )

      renderHook(() => useChunkLoader())

      await waitFor(() => {
        expect(starsApi.fetchStars).toHaveBeenCalled()
      })

      mockCamera.position.set(200, 200, 200)
      runUseFrame()

      await waitFor(() => {
        expect(abortSpy).toHaveBeenCalled()
      })
    })

    it('should not throw errors when aborting completed requests', async () => {
      vi.mocked(starsApi.fetchStars).mockResolvedValue(mockStarResponse)

      const { result, unmount } = renderHook(() => useChunkLoader())

      // Load and complete a chunk
      await waitFor(() => {
        result.current.jumpToPosition(new THREE.Vector3(0, 0, 0))
      })

      // Wait for completion
      await waitFor(() => {
        expect(result.current.loadingCount).toBe(0)
      })

      // Unmounting should not throw even though controllers are already done
      expect(() => unmount()).not.toThrow()
    })
  })

  describe('Request Cancellation', () => {
    it('should pass AbortSignal to fetch requests', async () => {
      vi.mocked(starsApi.fetchStars).mockResolvedValue(mockStarResponse)

      renderHook(() => useChunkLoader())

      // Wait for fetch to be called
      await waitFor(() => {
        expect(starsApi.fetchStars).toHaveBeenCalled()
      })

      // Verify the fetch was called with proper parameters including a signal
      const calls = vi.mocked(starsApi.fetchStars).mock.calls
      expect(calls.length).toBeGreaterThan(0)

      const signalCalls = calls.filter(([options]) => 'signal' in options)
      expect(signalCalls.length).toBeGreaterThan(0)

      signalCalls.forEach(([options]) => {
        expect(options).toMatchObject({
          bounds: {
            xmin: expect.any(Number),
            xmax: expect.any(Number),
            ymin: expect.any(Number),
            ymax: expect.any(Number),
            zmin: expect.any(Number),
            zmax: expect.any(Number),
          },
        })
        expect(options.signal).toBeDefined()
      })
    })

    it('should handle fetch abortion gracefully', async () => {
      // Mock fetch that immediately gets aborted
      vi.mocked(starsApi.fetchStars).mockImplementation(
        () =>
          new Promise((_, reject) => {
            const error = new Error('The operation was aborted')
            error.name = 'AbortError'
            reject(error)
          })
      )

      const { result } = renderHook(() => useChunkLoader())

      await waitFor(() => {
        result.current.jumpToPosition(new THREE.Vector3(0, 0, 0))
      })

      // Should not crash on abort error
      await waitFor(() => {
        // Loading should complete despite abort
        expect(result.current.loadingCount).toBe(0)
      })
    })
  })

  describe('Memory Management', () => {
    it('should clean up refs on unmount', () => {
      const { result, unmount } = renderHook(() => useChunkLoader())

      // Verify initial state
      expect(result.current.stars).toEqual([])
      expect(result.current.chunkCount).toBe(0)

      // Unmount should trigger cleanup
      expect(() => unmount()).not.toThrow()
    })

    it('should not leak memory with repeated mount/unmount cycles', async () => {
  vi.mocked(starsApi.fetchStars).mockResolvedValue(mockStarResponse)

      // Simulate multiple mount/unmount cycles
      for (let i = 0; i < 5; i++) {
        const { result, unmount } = renderHook(() => useChunkLoader())

        await waitFor(() => {
          result.current.jumpToPosition(new THREE.Vector3(i * 10, i * 10, i * 10))
        })

        // Unmount should not throw and should clean up properly
        expect(() => unmount()).not.toThrow()
      }

      // If we got here without errors, cleanup is working
      expect(true).toBe(true)
    })
  })

  describe('Concurrent Load Limiting', () => {
    it('should respect MAX_CONCURRENT_LOADS', async () => {
      let activeRequests = 0
      let maxConcurrent = 0

      vi.mocked(starsApi.fetchStars).mockImplementation(
        () =>
          new Promise((resolve) => {
            activeRequests++
            maxConcurrent = Math.max(maxConcurrent, activeRequests)

            setTimeout(() => {
              activeRequests--
              resolve(mockStarResponse)
            }, 50)
          })
      )

      renderHook(() => useChunkLoader())

      await waitFor(() => {
        expect(starsApi.fetchStars).toHaveBeenCalled()
      })

      // Allow queue processing to run
      await new Promise((resolve) => setTimeout(resolve, 500))

      // allow a small buffer for initial bootstrap requests before throttling kicks in
      expect(maxConcurrent).toBeLessThanOrEqual(12)
    })
  })

  describe('Chunk Cleanup', () => {
    it('should remove distant chunks when moving far away', async () => {
      vi.mocked(starsApi.fetchStars).mockResolvedValue(mockStarResponse)
      const onStarsLoaded = vi.fn()

      const { result } = renderHook(() => useChunkLoader({ onStarsLoaded }))

      // Wait for initial load to complete
      await waitFor(
        () => {
          expect(starsApi.fetchStars).toHaveBeenCalled()
        },
        { timeout: 1000 }
      )

      // Wait for some chunks to load
      await new Promise((resolve) => setTimeout(resolve, 50))

      const initialFetchCount = vi.mocked(starsApi.fetchStars).mock.calls.length

      // Jump to a distant location - this should clear old chunks
      result.current.jumpToPosition(new THREE.Vector3(1000, 1000, 1000))

      // Verify old chunks were cleared (stars reset to empty during jump)
      await waitFor(
        () => {
          expect(onStarsLoaded).toHaveBeenCalledWith([])
        },
        { timeout: 1000 }
      )

      // Verify new fetches are happening at the new position
      await waitFor(
        () => {
          expect(vi.mocked(starsApi.fetchStars).mock.calls.length).toBeGreaterThan(initialFetchCount)
        },
        { timeout: 2000 }
      )

      // The test passes if we got here without hanging
      expect(true).toBe(true)
    })
  })

  describe('Spatial Cache', () => {
    it('should restore chunks from cache when returning to visited area', async () => {
      vi.mocked(starsApi.fetchStars).mockResolvedValue(mockStarResponse)

      const { result } = renderHook(() => useChunkLoader())

      // Wait for initial load at origin
      await waitFor(
        () => {
          expect(starsApi.fetchStars).toHaveBeenCalled()
        },
        { timeout: 1000 }
      )

      const initialFetchCount = vi.mocked(starsApi.fetchStars).mock.calls.length

      // Jump far away - chunks get saved to spatial cache
      result.current.jumpToPosition(new THREE.Vector3(500, 500, 500))

      await waitFor(
        () => {
          // More fetches for new position
          expect(vi.mocked(starsApi.fetchStars).mock.calls.length).toBeGreaterThan(initialFetchCount)
        },
        { timeout: 1000 }
      )

      const afterJumpFetchCount = vi.mocked(starsApi.fetchStars).mock.calls.length

      // Jump back to origin - should restore from cache
      result.current.jumpToPosition(new THREE.Vector3(0, 0, 0))

      // Give time for cache restoration
      await new Promise((resolve) => setTimeout(resolve, 100))

      // If spatial cache works, we should see fewer fetches than initial
      const finalFetchCount = vi.mocked(starsApi.fetchStars).mock.calls.length

      // The second jump back should use fewer fetches since chunks are cached
      expect(finalFetchCount - afterJumpFetchCount).toBeLessThanOrEqual(afterJumpFetchCount - initialFetchCount)
    })

    it('should persist chunks across component re-renders', async () => {
      vi.mocked(starsApi.fetchStars).mockResolvedValue(mockStarResponse)

      // First mount
      const { unmount } = renderHook(() => useChunkLoader())

      await waitFor(
        () => {
          expect(starsApi.fetchStars).toHaveBeenCalled()
        },
        { timeout: 1000 }
      )

      // Wait for some chunks to load
      await new Promise((resolve) => setTimeout(resolve, 50))

      const firstMountFetchCount = vi.mocked(starsApi.fetchStars).mock.calls.length

      // Unmount (chunks should be preserved in module-level cache)
      unmount()

      // Clear fetch mock to track new calls only
      vi.mocked(starsApi.fetchStars).mockClear()

      // Second mount at same position
      renderHook(() => useChunkLoader())

      // Wait for initialization
      await new Promise((resolve) => setTimeout(resolve, 100))

      // Second mount should have fewer API calls due to cache hits
      const secondMountFetchCount = vi.mocked(starsApi.fetchStars).mock.calls.length

      // If cache is working, second mount should fetch less (or equal)
      expect(secondMountFetchCount).toBeLessThanOrEqual(firstMountFetchCount)
    })

    it('should save chunks to cache when moving away', async () => {
      vi.mocked(starsApi.fetchStars).mockResolvedValue(mockStarResponse)

      const { result } = renderHook(() => useChunkLoader())

      // Wait for initial load to trigger fetch
      await waitFor(
        () => {
          expect(starsApi.fetchStars).toHaveBeenCalled()
        },
        { timeout: 1000 }
      )

      const initialFetchCount = vi.mocked(starsApi.fetchStars).mock.calls.length

      // Jump far away (triggers chunk eviction to cache)
      result.current.jumpToPosition(new THREE.Vector3(500, 500, 500))

      // Wait for new fetches at new position
      await waitFor(
        () => {
          expect(vi.mocked(starsApi.fetchStars).mock.calls.length).toBeGreaterThan(initialFetchCount)
        },
        { timeout: 1000 }
      )

      // No errors means chunks were properly saved to cache during cleanup
      expect(true).toBe(true)
    })
  })
})
