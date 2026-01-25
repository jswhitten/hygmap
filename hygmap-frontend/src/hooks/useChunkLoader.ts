/**
 * useChunkLoader - Dynamic spatial loading with LOD
 *
 * Loads stars in chunks based on camera or target position.
 * Implements LOD by adjusting magnitude limit based on distance.
 * Prioritizes loading chunks closest to center first.
 * Supports immediate loading around a target position (for search/centering).
 */

import { useRef, useCallback, useEffect } from 'react'
import { useFrame, useThree } from '@react-three/fiber'
import * as THREE from 'three'
import { fetchStars } from '../api/stars'
import type { Star, BoundingBox } from '../types/star'
import { MAX_RENDERED_STARS } from '../constants/rendering'
import { sceneToGalactic, sceneBoundsToGalactic } from '../domain/coordinates'
import { useChunkMergerWorker } from './useChunkMergerWorker'

// Chunk configuration
const CHUNK_SIZE = 40 // parsecs per chunk side
const VIEW_DISTANCE = 80 // base max distance to load chunks (expanded dynamically when zoomed out)
const UPDATE_THRESHOLD = 5 // minimum camera movement to trigger update
const JUMP_THRESHOLD = 50 // distance to consider a "jump" (clears old chunks)
const MAX_CONCURRENT_LOADS = 6 // concurrent chunk requests

// Spatial cache configuration
const SPATIAL_CACHE_MAX_CHUNKS = 200 // Maximum chunks to keep in spatial cache
const SPATIAL_CACHE_MAX_STARS = 100000 // Maximum total stars in spatial cache

// LOD configuration - magnitude limits by distance
const LOD_LEVELS = [
  { distance: 40, magMax: 12 },   // Close: show most stars
  { distance: 80, magMax: 8 },    // Medium: moderate detail
  { distance: 120, magMax: 5 },    // Far: only brighter stars
  { distance: Infinity, magMax: 3 }, // Very far: only brightest
]

// Chunk key format: "x_y_z_lod"
type ChunkKey = string

interface ChunkData {
  stars: Star[]
  lodLevel: number
  timestamp: number
}

/**
 * Spatial cache for visited regions.
 * Module-level singleton so chunks persist when navigating away and back.
 * Uses LRU eviction when cache exceeds limits.
 */
class SpatialCache {
  private cache = new Map<ChunkKey, ChunkData>()
  private accessOrder: ChunkKey[] = [] // Most recent at end

  get(key: ChunkKey): ChunkData | undefined {
    const data = this.cache.get(key)
    if (data) {
      // Move to end of access order (most recently used)
      this.accessOrder = this.accessOrder.filter((k) => k !== key)
      this.accessOrder.push(key)
    }
    return data
  }

  set(key: ChunkKey, data: ChunkData): void {
    // If updating existing key, remove from access order first
    if (this.cache.has(key)) {
      this.accessOrder = this.accessOrder.filter((k) => k !== key)
    }

    this.cache.set(key, data)
    this.accessOrder.push(key)

    // Evict if over chunk limit
    while (this.cache.size > SPATIAL_CACHE_MAX_CHUNKS) {
      this.evictOldest()
    }

    // Evict if over star limit
    while (this.totalStars() > SPATIAL_CACHE_MAX_STARS && this.cache.size > 1) {
      this.evictOldest()
    }
  }

  has(key: ChunkKey): boolean {
    return this.cache.has(key)
  }

  delete(key: ChunkKey): void {
    this.cache.delete(key)
    this.accessOrder = this.accessOrder.filter((k) => k !== key)
  }

  /**
   * Get all cached chunks within a distance of a position.
   * Used to restore chunks when returning to a previously visited area.
   */
  getChunksNear(position: THREE.Vector3, maxDistance: number): Map<ChunkKey, ChunkData> {
    const result = new Map<ChunkKey, ChunkData>()

    this.cache.forEach((data, key) => {
      // Skip immediate area chunks (they have different key format)
      if (key.startsWith('immediate_')) {
        // Parse immediate chunk position
        const parts = key.replace('immediate_', '').split('_').map(Number)
        if (parts.length >= 3 && !parts.some(isNaN)) {
          const [ix, iy, iz] = parts
          const chunkPos = new THREE.Vector3(ix, iy, iz)
          if (horizontalDistance(position, chunkPos) <= maxDistance) {
            result.set(key, data)
            // Update access time
            this.accessOrder = this.accessOrder.filter((k) => k !== key)
            this.accessOrder.push(key)
          }
        }
        return
      }

      const parsed = parseChunkKey(key)
      if (!parsed) return

      const chunkCenter = getChunkCenter(parsed.cx, parsed.cy, parsed.cz)
      if (horizontalDistance(position, chunkCenter) <= maxDistance) {
        result.set(key, data)
        // Update access time
        this.accessOrder = this.accessOrder.filter((k) => k !== key)
        this.accessOrder.push(key)
      }
    })

    return result
  }

  private evictOldest(): void {
    const oldest = this.accessOrder.shift()
    if (oldest) {
      this.cache.delete(oldest)
      if (import.meta.env.DEV) {
        console.log(`SpatialCache: evicted chunk ${oldest}`)
      }
    }
  }

  private totalStars(): number {
    let total = 0
    this.cache.forEach((data) => {
      total += data.stars.length
    })
    return total
  }

  get size(): number {
    return this.cache.size
  }

  get starCount(): number {
    return this.totalStars()
  }

  clear(): void {
    this.cache.clear()
    this.accessOrder = []
  }
}

// Module-level singleton for spatial cache
const spatialCache = new SpatialCache()

interface ChunkCandidate {
  cx: number
  cy: number
  cz: number
  distance: number
  lodLevel: number
}

interface UseChunkLoaderOptions {
  enabled?: boolean
  onStarsLoaded?: (stars: Star[]) => void
}

interface UseChunkLoaderReturn {
  stars: Star[]
  chunkCount: number
  loadingCount: number
  jumpToPosition: (position: THREE.Vector3) => void
}

function getChunkKey(cx: number, cy: number, cz: number, lod: number): ChunkKey {
  return `${cx}_${cy}_${cz}_${lod}`
}

function getChunkCoords(position: THREE.Vector3): { cx: number; cy: number; cz: number } {
  return {
    cx: Math.floor(position.x / CHUNK_SIZE),
    cy: Math.floor(position.y / CHUNK_SIZE),
    cz: Math.floor(position.z / CHUNK_SIZE),
  }
}

function getChunkBoundsScene(cx: number, cy: number, cz: number): BoundingBox {
  // Returns bounds in scene space
  return {
    xmin: cx * CHUNK_SIZE,
    xmax: (cx + 1) * CHUNK_SIZE,
    ymin: cy * CHUNK_SIZE,
    ymax: (cy + 1) * CHUNK_SIZE,
    zmin: cz * CHUNK_SIZE,
    zmax: (cz + 1) * CHUNK_SIZE,
  }
}

// Convert scene-space bounds to galactic-space bounds for API calls
// Scene transform is [-y, x, z] so inverse is [sceneY, -sceneX, sceneZ]
function getLodLevel(distance: number): number {
  for (let i = 0; i < LOD_LEVELS.length; i++) {
    if (distance < LOD_LEVELS[i].distance) {
      return i
    }
  }
  return LOD_LEVELS.length - 1
}

// Reusable Vector3 for chunk center calculations (avoids GC pressure in hot paths)
const tempChunkCenter = new THREE.Vector3()

function getChunkCenter(cx: number, cy: number, cz: number): THREE.Vector3 {
  return tempChunkCenter.set(
    (cx + 0.5) * CHUNK_SIZE,
    (cy + 0.5) * CHUNK_SIZE,
    (cz + 0.5) * CHUNK_SIZE
  )
}

// Horizontal distance helper (ignore altitude so zooming out does not block loading)
function horizontalDistance(a: THREE.Vector3, b: THREE.Vector3): number {
  const dx = a.x - b.x
  const dy = a.y - b.y
  return Math.hypot(dx, dy)
}

// Expand view distance when zoomed out so distant camera heights still load chunks
function getEffectiveViewDistance(centerPos: THREE.Vector3): number {
  const altitudeBuffer = Math.abs(centerPos.z) + VIEW_DISTANCE / 2
  return Math.max(VIEW_DISTANCE, altitudeBuffer)
}

/**
 * Parse chunk coordinates from a chunk key.
 * Returns null if the key is malformed.
 */
function parseChunkKey(key: string): { cx: number; cy: number; cz: number; lod: number } | null {
  const parts = key.split('_')
  if (parts.length !== 4) return null

  const [cxStr, cyStr, czStr, lodStr] = parts
  const cx = Number(cxStr)
  const cy = Number(cyStr)
  const cz = Number(czStr)
  const lod = Number(lodStr)

  if (isNaN(cx) || isNaN(cy) || isNaN(cz) || isNaN(lod)) return null

  return { cx, cy, cz, lod }
}

export function useChunkLoader(options: UseChunkLoaderOptions = {}): UseChunkLoaderReturn {
  const { enabled = true, onStarsLoaded } = options
  const { camera } = useThree()

  // Web worker for offloading merge operations
  const { merge: workerMerge, isSupported: workerSupported } = useChunkMergerWorker()
  const mergeInProgressRef = useRef(false)
  const pendingMergeRef = useRef(false)

  // Chunk cache
  const chunksRef = useRef<Map<ChunkKey, ChunkData>>(new Map())
  const loadingRef = useRef<Set<ChunkKey>>(new Set())
  const abortControllersRef = useRef<Map<ChunkKey, AbortController>>(new Map()) // Track abort controllers
  const pendingQueueRef = useRef<ChunkCandidate[]>([])
  const lastUpdatePos = useRef<THREE.Vector3>(new THREE.Vector3())
  const lastUpdateQuat = useRef<THREE.Quaternion>(new THREE.Quaternion())
  const targetPosRef = useRef<THREE.Vector3 | null>(null)
  const isJumpingRef = useRef(false) // Prevent useFrame updates during jump
  const allStarsRef = useRef<Star[]>([])

  // Synchronous merge fallback (used when worker not available)
  const mergeSynchronous = useCallback(() => {
    const mergeStart = performance.now()
    const starMap = new Map<number, Star>() // Dedupe by ID

    chunksRef.current.forEach((chunk) => {
      chunk.stars.forEach((star) => {
        const existing = starMap.get(star.id)
        if (!existing) {
          starMap.set(star.id, star)
        }
      })
    })

    const dedupeTime = performance.now() - mergeStart
    const merged = Array.from(starMap.values())

    let limited = merged
    let sortTime = 0
    if (merged.length > MAX_RENDERED_STARS) {
      const sortStart = performance.now()
      const [camX, camY, camZ] = sceneToGalactic(
        camera.position.x,
        camera.position.y,
        camera.position.z
      )
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
        .slice(0, MAX_RENDERED_STARS)

      limited = limitedEntries.map((entry) => entry.star)
      sortTime = performance.now() - sortStart
      if (import.meta.env.DEV) {
        console.log(
          `mergeChunks (sync): trimmed ${merged.length} stars to closest ${limited.length} within capacity`
        )
      }
    }

    allStarsRef.current = limited
    const totalTime = performance.now() - mergeStart
    if (import.meta.env.DEV) {
      console.log(
        `Merged ${chunksRef.current.size} chunks into ${limited.length} stars (sync) ` +
        `(dedupe: ${dedupeTime.toFixed(1)}ms, sort: ${sortTime.toFixed(1)}ms, total: ${totalTime.toFixed(1)}ms)`
      )
    }
    onStarsLoaded?.(limited)
  }, [onStarsLoaded, camera])

  // Merge using web worker (async)
  const mergeWithWorker = useCallback(async () => {
    // Prevent concurrent merges - queue if one is in progress
    if (mergeInProgressRef.current) {
      pendingMergeRef.current = true
      return
    }

    mergeInProgressRef.current = true

    try {
      // Prepare chunk data for worker
      const chunks = Array.from(chunksRef.current.entries()).map(([key, data]) => ({
        key,
        stars: data.stars,
      }))

      // Get camera position in galactic coordinates
      const [camX, camY, camZ] = sceneToGalactic(
        camera.position.x,
        camera.position.y,
        camera.position.z
      )

      const result = await workerMerge(
        chunks,
        { x: camX, y: camY, z: camZ },
        MAX_RENDERED_STARS
      )

      allStarsRef.current = result.stars

      if (import.meta.env.DEV) {
        const { stats } = result
        console.log(
          `Merged ${stats.inputChunks} chunks into ${stats.outputStars} stars (worker) ` +
          `(dedupe: ${stats.dedupeTimeMs.toFixed(1)}ms, sort: ${stats.sortTimeMs.toFixed(1)}ms, total: ${stats.totalTimeMs.toFixed(1)}ms)`
        )
      }

      onStarsLoaded?.(result.stars)
    } catch (error) {
      // Worker failed, fall back to sync
      if (import.meta.env.DEV) {
        console.warn('Worker merge failed, falling back to sync:', error)
      }
      mergeSynchronous()
    } finally {
      mergeInProgressRef.current = false

      // Process any pending merge request
      if (pendingMergeRef.current) {
        pendingMergeRef.current = false
        // Use setTimeout to avoid stack overflow on rapid merges
        setTimeout(() => mergeWithWorker(), 0)
      }
    }
  }, [workerMerge, camera, onStarsLoaded, mergeSynchronous])

  // Merge all chunks into a single star array
  const mergeChunks = useCallback(() => {
    if (workerSupported) {
      mergeWithWorker()
    } else {
      mergeSynchronous()
    }
  }, [workerSupported, mergeWithWorker, mergeSynchronous])

  // Process the pending queue with concurrency limit
  const processQueue = useCallback(() => {
    while (pendingQueueRef.current.length > 0 && loadingRef.current.size < MAX_CONCURRENT_LOADS) {
      const candidate = pendingQueueRef.current.shift()
      if (!candidate) break

      const { cx, cy, cz, lodLevel } = candidate
      const key = getChunkKey(cx, cy, cz, lodLevel)

      // Skip if already loaded or loading
      if (chunksRef.current.has(key) || loadingRef.current.has(key)) {
        continue
      }

      loadingRef.current.add(key)

      // Create AbortController for this request
      const abortController = new AbortController()
      abortControllersRef.current.set(key, abortController)

      // Start the fetch (don't await - let it run in background)
      // Convert scene-space chunk bounds to galactic-space for API
      const sceneBounds = getChunkBoundsScene(cx, cy, cz)
      const galacticBounds = sceneBoundsToGalactic(sceneBounds)
      const fetchStart = performance.now()
      fetchStars({
        bounds: galacticBounds,
        magMax: LOD_LEVELS[lodLevel].magMax,
        limit: 10000,
        signal: abortController.signal,
      })
        .then((response) => {
          const fetchTime = performance.now() - fetchStart
          if (import.meta.env.DEV) {
            console.log(
              `Chunk ${key}: loaded ${response.data.length} stars in ${fetchTime.toFixed(0)}ms`
            )
          }
          chunksRef.current.set(key, {
            stars: response.data,
            lodLevel,
            timestamp: Date.now(),
          })

          // Remove lower LOD versions of this chunk
          for (let i = lodLevel + 1; i < LOD_LEVELS.length; i++) {
            const lowerKey = getChunkKey(cx, cy, cz, i)
            chunksRef.current.delete(lowerKey)
          }

          mergeChunks()
        })
        .catch((error) => {
          // Ignore abort errors (expected when canceling requests)
          if (error.name === 'AbortError') {
            if (import.meta.env.DEV) {
              console.log(`Chunk ${key}: load cancelled`)
            }
            return
          }
          console.error(`Failed to load chunk ${key}:`, error)
        })
        .finally(() => {
          loadingRef.current.delete(key)
          abortControllersRef.current.delete(key)
          // Process more from queue when this one finishes
          processQueue()
        })
    }
  }, [mergeChunks])

  // Queue a chunk for loading (returns true if restored from cache)
  const queueChunk = useCallback(
    (cx: number, cy: number, cz: number, lodLevel: number, distance: number): boolean => {
      const key = getChunkKey(cx, cy, cz, lodLevel)

      // Skip if already loaded or loading
      if (chunksRef.current.has(key) || loadingRef.current.has(key)) {
        return false
      }

      // Check if we have this chunk in the spatial cache
      const cached = spatialCache.get(key)
      if (cached) {
        // Restore from cache instead of fetching
        chunksRef.current.set(key, cached)
        if (import.meta.env.DEV) {
          console.log(`Chunk ${key}: restored from spatial cache (${cached.stars.length} stars)`)
        }
        return true
      }

      // Check if already in queue
      const alreadyQueued = pendingQueueRef.current.some(
        (c) => c.cx === cx && c.cy === cy && c.cz === cz && c.lodLevel === lodLevel
      )
      if (alreadyQueued) return false

      pendingQueueRef.current.push({ cx, cy, cz, distance, lodLevel })
      return false
    },
    []
  )

  // Cancel in-flight requests for chunks that are now too far away
  const cancelDistantRequests = useCallback((centerPos: THREE.Vector3) => {
    let actualCancelled = 0

    // Cancel requests for chunks outside the view distance + buffer
    const cancelDistance = getEffectiveViewDistance(centerPos) * 1.5

    abortControllersRef.current.forEach((controller, key) => {
      const parsed = parseChunkKey(key)
      if (!parsed) return // Skip malformed keys

      const chunkCenter = getChunkCenter(parsed.cx, parsed.cy, parsed.cz)
      const distance = horizontalDistance(centerPos, chunkCenter)

      if (distance > cancelDistance) {
        controller.abort()
        actualCancelled++
      }
    })

    if (actualCancelled > 0 && import.meta.env.DEV) {
      console.log(`Cancelled ${actualCancelled} distant chunk requests`)
    }
  }, [])

  // Load chunks around a position
  const loadChunksAroundPosition = useCallback((centerPos: THREE.Vector3, clearOld: boolean) => {
    const camChunk = getChunkCoords(centerPos)
    const viewDistance = getEffectiveViewDistance(centerPos)

    // If jumping to new location, save current chunks to spatial cache before clearing
    if (clearOld) {
      // Save current chunks to spatial cache before clearing
      chunksRef.current.forEach((data, key) => {
        spatialCache.set(key, data)
      })

      // Cancel all in-flight requests
      abortControllersRef.current.forEach((controller) => controller.abort())
      abortControllersRef.current.clear()

      chunksRef.current.clear()
      loadingRef.current.clear()
      pendingQueueRef.current = []
      allStarsRef.current = []
      onStarsLoaded?.([])

      // Restore any cached chunks that are near the new position
      const cachedNearby = spatialCache.getChunksNear(centerPos, viewDistance)
      if (cachedNearby.size > 0) {
        cachedNearby.forEach((data, key) => {
          chunksRef.current.set(key, data)
        })
        if (import.meta.env.DEV) {
          console.log(`Restored ${cachedNearby.size} chunks from spatial cache`)
        }
        mergeChunks()
      }
    } else {
      // Cancel distant requests to avoid wasting bandwidth
      cancelDistantRequests(centerPos)
    }

    // Calculate view range in chunks
    const rangeChunks = Math.ceil(viewDistance / CHUNK_SIZE)

    // Collect all chunk candidates with their distances
    const candidates: ChunkCandidate[] = []

    for (let dx = -rangeChunks; dx <= rangeChunks; dx++) {
      for (let dy = -rangeChunks; dy <= rangeChunks; dy++) {
        for (let dz = -rangeChunks; dz <= rangeChunks; dz++) {
          const cx = camChunk.cx + dx
          const cy = camChunk.cy + dy
          const cz = camChunk.cz + dz

          const chunkCenter = getChunkCenter(cx, cy, cz)
          const distance = horizontalDistance(centerPos, chunkCenter)

          // Skip if too far
          if (distance > viewDistance) continue

          const lodLevel = getLodLevel(distance)

          // Check if we need to load or upgrade this chunk
          const existingKey = Array.from(chunksRef.current.keys()).find((k) =>
            k.startsWith(`${cx}_${cy}_${cz}_`)
          )

          if (existingKey) {
            const existingLod = parseInt(existingKey.split('_')[3])
            // Only reload if we need higher detail (lower LOD number)
            if (lodLevel >= existingLod) continue
          }

          candidates.push({ cx, cy, cz, distance, lodLevel })
        }
      }
    }

    // Sort by distance (closest first) to prioritize center of view
    candidates.sort((a, b) => a.distance - b.distance)

    if (import.meta.env.DEV) {
      console.log(`loadChunksAroundPosition: ${candidates.length} candidates, clearOld=${clearOld}`)
    }

    // Queue chunks for loading (with concurrency limit)
    let restoredCount = 0
    for (const { cx, cy, cz, lodLevel, distance } of candidates) {
      if (queueChunk(cx, cy, cz, lodLevel, distance)) {
        restoredCount++
      }
    }

    // If we restored any chunks from cache, trigger a merge
    if (restoredCount > 0) {
      if (import.meta.env.DEV) {
        console.log(`Restored ${restoredCount} chunks from spatial cache during load`)
      }
      mergeChunks()
    }

    // Start processing the queue
    processQueue()

    // Clean up chunks that are too far away (only if not clearing)
    if (!clearOld) {
      const keysToRemove: ChunkKey[] = []
      chunksRef.current.forEach((data, key) => {
        // Handle immediate area chunks - parse position from key
        if (key.startsWith('immediate_')) {
          const parts = key.replace('immediate_', '').split('_').map(Number)
          if (parts.length >= 3 && !parts.some(isNaN)) {
            const [ix, iy, iz] = parts
            const immediateCenter = new THREE.Vector3(ix, iy, iz)
            const distance = horizontalDistance(centerPos, immediateCenter)
            // Remove immediate chunks that are too far away
            if (distance > viewDistance * 2) {
              // Save to spatial cache before removing
              spatialCache.set(key, data)
              keysToRemove.push(key)
            }
          }
          return
        }

        const parsed = parseChunkKey(key)
        if (!parsed) return

        const chunkCenter = getChunkCenter(parsed.cx, parsed.cy, parsed.cz)
        const distance = horizontalDistance(centerPos, chunkCenter)

        if (distance > viewDistance * 1.5) {
          // Save to spatial cache before removing
          spatialCache.set(key, data)
          keysToRemove.push(key)
        }
      })

      if (keysToRemove.length > 0) {
        if (import.meta.env.DEV) {
          console.log(`Saved ${keysToRemove.length} distant chunks to spatial cache (cache size: ${spatialCache.size} chunks, ${spatialCache.starCount} stars)`)
        }
        keysToRemove.forEach((key) => chunksRef.current.delete(key))
        mergeChunks()
      }
    }
  }, [queueChunk, processQueue, mergeChunks, onStarsLoaded, cancelDistantRequests])

  // Load a large immediate area first (single request for fast initial view)
  const loadImmediateArea = useCallback(async (position: THREE.Vector3) => {
    // Position is in scene space, create scene bounds first
    const sceneBounds: BoundingBox = {
      xmin: position.x - 40,
      xmax: position.x + 40,
      ymin: position.y - 40,
      ymax: position.y + 40,
      zmin: position.z - 40,
      zmax: position.z + 40,
    }

    // Convert to galactic space for API call
    const galacticBounds = sceneBoundsToGalactic(sceneBounds)

    if (import.meta.env.DEV) {
      console.log(`loadImmediateArea: scene pos (${position.x.toFixed(0)}, ${position.y.toFixed(0)}, ${position.z.toFixed(0)})`)
      console.log(`  galactic bounds x[${galacticBounds.xmin.toFixed(0)},${galacticBounds.xmax.toFixed(0)}] y[${galacticBounds.ymin.toFixed(0)},${galacticBounds.ymax.toFixed(0)}] z[${galacticBounds.zmin.toFixed(0)},${galacticBounds.zmax.toFixed(0)}]`)
    }

    try {
      const response = await fetchStars({
        bounds: galacticBounds,
        magMax: 10, // Good detail level
        limit: 10000,
      })

      // Create a synthetic chunk for this area
      const key = `immediate_${Math.floor(position.x)}_${Math.floor(position.y)}_${Math.floor(position.z)}`
      chunksRef.current.set(key, {
        stars: response.data,
        lodLevel: 0,
        timestamp: Date.now(),
      })

      mergeChunks()
      if (import.meta.env.DEV) {
        console.log(`Loaded immediate area: ${response.data.length} stars`)
      }
    } catch (error) {
      console.error('Failed to load immediate area:', error)
    }
  }, [mergeChunks])

  // Jump to a new position immediately (for search/centering)
  const jumpToPosition = useCallback((position: THREE.Vector3) => {
    if (import.meta.env.DEV) {
      console.log(`Jumping to position: ${position.x.toFixed(1)}, ${position.y.toFixed(1)}, ${position.z.toFixed(1)}`)
    }

    // Prevent useFrame from triggering updates during jump
    isJumpingRef.current = true

    // Clear everything
    chunksRef.current.clear()
    loadingRef.current.clear()
    pendingQueueRef.current = []
    allStarsRef.current = []
    onStarsLoaded?.([])

    targetPosRef.current = position.clone()
    // Don't update lastUpdatePos here - keep it at current camera position
    // This prevents false "jump" detection when isJumpingRef becomes false

    // Load immediate area - keep isJumpingRef true until camera reaches target
    // useFrame will set it false when camera arrives
    loadImmediateArea(position)
  }, [loadImmediateArea, onStarsLoaded])

  // Initial load on mount only
  const initialLoadDone = useRef(false)
  useEffect(() => {
    if (enabled && !initialLoadDone.current) {
      initialLoadDone.current = true
      lastUpdatePos.current.copy(camera.position)
      lastUpdateQuat.current.copy(camera.quaternion)
      loadChunksAroundPosition(camera.position, false)
    }
  }, [enabled, loadChunksAroundPosition, camera])

  // Update chunks when camera moves significantly
  useFrame(() => {
    if (!enabled) return

    // Handle jump completion - wait for camera to reach target
    if (isJumpingRef.current && targetPosRef.current) {
      const distToTarget = camera.position.distanceTo(targetPosRef.current)
      if (distToTarget < 10) {
        // Camera has arrived - resume normal chunk loading
        if (import.meta.env.DEV) {
          console.log('Camera reached target, resuming chunk updates')
        }
        isJumpingRef.current = false
        targetPosRef.current = null
        lastUpdatePos.current.copy(camera.position)
        lastUpdateQuat.current.copy(camera.quaternion)
        // Load chunks around new position (incremental, don't clear immediate area)
        loadChunksAroundPosition(camera.position, false)
      }
      return // Don't do other updates while jumping
    }

    if (isJumpingRef.current) return

    // Use camera position for normal updates
    const currentPos = camera.position
    const moved = currentPos.distanceTo(lastUpdatePos.current)

    if (moved > JUMP_THRESHOLD) {
      // Camera jumped far (not via jumpToPosition) - clear old chunks and load new area
      if (import.meta.env.DEV) {
        console.log(`Camera jumped ${moved.toFixed(1)}pc, loading new area`)
      }
      lastUpdatePos.current.copy(currentPos)
      lastUpdateQuat.current.copy(camera.quaternion)
      loadChunksAroundPosition(currentPos, true)
    } else if (moved > UPDATE_THRESHOLD) {
      // Normal movement - incremental update
      if (import.meta.env.DEV) {
        console.log(`Camera moved ${moved.toFixed(1)}pc, loading chunks around ${currentPos.x.toFixed(1)}, ${currentPos.y.toFixed(1)}, ${currentPos.z.toFixed(1)}`)
      }
      lastUpdatePos.current.copy(currentPos)
      lastUpdateQuat.current.copy(camera.quaternion)
      loadChunksAroundPosition(currentPos, false)
    }
  })

  // Cleanup: Cancel all in-flight requests on unmount
    useEffect(() => {
      const controllers = abortControllersRef.current
      return () => {
        controllers.forEach((controller) => controller.abort())
        controllers.clear()
      }
    }, [])

  return {
    stars: allStarsRef.current,
    chunkCount: chunksRef.current.size,
    loadingCount: loadingRef.current.size,
    jumpToPosition,
  }
}

export function __resetSpatialCacheForTests(): void {
  spatialCache.clear()
}
