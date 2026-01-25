import { useCallback, useMemo, useRef, useEffect, useState } from 'react'
import * as THREE from 'three'
import { useFrame, useThree } from '@react-three/fiber'
import { useDebounce } from '../hooks/useDebounce'
import {
  useConstellationFilter,
  useCoordinateSystem,
  useGridSettings,
  useHoveredSignal,
  useLabelMagLimit,
  useMagLimit,
  useMaxDistance,
  useMeasure,
  usePrintableView,
  useSelectedSignal,
  useSelectedStar,
  useSetHoveredSignal,
  useSetHoveredStar,
  useSetIsLoading,
  useSetMeasurePoint1,
  useSetMeasurePoint2,
  useSetSelectedSignal,
  useSetSelectedStar,
  useSetSignalsData,
  useSetSignalsLoading,
  useSetStars,
  useShowLabels,
  useShowSignals,
  useSignalTypeFilter,
  useSignalsData,
  useSpectralFilter,
  useStars,
  useUnit,
  useViewMode,
} from '../state/store'
import type { MeasurePoint } from '../state/store'
import { useChunkLoader } from '../hooks/useChunkLoader'
import { InstancedStars, StarGlow, GPUPicker, GalacticGrid, StarLabels, SelectionIndicator, SignalsLayer, MilkyWayGlow } from '../render'
import MeasureLine from '../render/MeasureLine'
import { galacticToScene, sceneBoundsToGalactic } from '../domain/coordinates'
import { projectSceneCoords, projectStarToScene } from '../domain/viewMode'
import type { Star, BoundingBox } from '../types/star'
import type { Signal } from '../types/signal'
import { fetchSignals } from '../api/signals'
import ErrorBoundary from './ErrorBoundary'

/**
 * StarField component - manages star data loading, rendering, and selection
 *
 * Uses chunk-based dynamic loading with LOD for efficient rendering.
 */
const SIGNAL_FETCH_RADIUS = 160
const SIGNAL_UPDATE_THRESHOLD = 35

export default function StarField() {
  const stars = useStars()
  const setStars = useSetStars()
  const setIsLoading = useSetIsLoading()
  const setHoveredStar = useSetHoveredStar()
  const setSelectedStar = useSetSelectedStar()
  const selectedStar = useSelectedStar()
  const magLimit = useMagLimit()
  const labelMagLimit = useLabelMagLimit()
  const showLabels = useShowLabels()
  const gridSettings = useGridSettings()
  const coordinateSystem = useCoordinateSystem()
  const measure = useMeasure()
  const setMeasurePoint1 = useSetMeasurePoint1()
  const setMeasurePoint2 = useSetMeasurePoint2()
  const spectralFilter = useSpectralFilter()
  const maxDistance = useMaxDistance()
  const constellationFilter = useConstellationFilter()
  const unit = useUnit()
  const viewMode = useViewMode()
  const printableView = usePrintableView()
  const signals = useSignalsData()
  const setSignals = useSetSignalsData()
  const showSignals = useShowSignals()
  const signalTypeFilter = useSignalTypeFilter()
  const selectedSignal = useSelectedSignal()
  const setSelectedSignal = useSetSelectedSignal()
  const hoveredSignal = useHoveredSignal()
  const setHoveredSignal = useSetHoveredSignal()
  const setSignalsLoading = useSetSignalsLoading()

  const isFirstLoad = useRef(true)
  const lastSelectedId = useRef<number | null>(null)
  const [gridCenter, setGridCenter] = useState<[number, number, number]>([0, 0, 0])
  const lastGridUpdateRef = useRef<THREE.Vector3 | null>(null) // null = needs init
  const gridInitialized = useRef(false)

  // Shared ref to track when star disks are initialized
  // Prevents race condition where glows could render before disks
  const starsInitializedRef = useRef(false)

  const { camera } = useThree()
  const signalAbortRef = useRef<AbortController | null>(null)
  const lastSignalFetchRef = useRef<THREE.Vector3 | null>(null)

  // Handle stars loaded from chunk loader
  const handleStarsLoaded = useCallback(
    (loadedStars: Star[]) => {
      if (import.meta.env.DEV) {
        console.log(`handleStarsLoaded: received ${loadedStars.length} stars`)
      }
      setStars(loadedStars)
      if (isFirstLoad.current) {
        if (import.meta.env.DEV) {
          console.log(`Initial load: ${loadedStars.length} stars`)
        }
        setIsLoading(false)
        isFirstLoad.current = false
      }
    },
    [setStars, setIsLoading]
  )

  const requestSignals = useCallback((center: THREE.Vector3) => {
    if (!showSignals) {
      return
    }

    const sceneBounds: BoundingBox = {
      xmin: center.x - SIGNAL_FETCH_RADIUS,
      xmax: center.x + SIGNAL_FETCH_RADIUS,
      ymin: center.y - SIGNAL_FETCH_RADIUS,
      ymax: center.y + SIGNAL_FETCH_RADIUS,
      zmin: center.z - SIGNAL_FETCH_RADIUS,
      zmax: center.z + SIGNAL_FETCH_RADIUS,
    }

    const galacticBounds = sceneBoundsToGalactic(sceneBounds)
    signalAbortRef.current?.abort()
    const controller = new AbortController()
    signalAbortRef.current = controller
    setSignalsLoading(true)

    fetchSignals({
      bounds: galacticBounds,
      signalType: signalTypeFilter === 'all' ? undefined : signalTypeFilter,
      signal: controller.signal,
    })
      .then((response) => {
        setSignals(response.data)
        if (import.meta.env.DEV) {
          console.log(`Loaded ${response.data.length} signals`)
        }
      })
      .catch((error: Error) => {
        if (error.name === 'AbortError') {
          return
        }
        console.error('Failed to load signals:', error)
      })
      .finally(() => {
        if (signalAbortRef.current === controller) {
          signalAbortRef.current = null
        }
        setSignalsLoading(false)
      })
  }, [showSignals, signalTypeFilter, setSignalsLoading, setSignals])

  useEffect(() => {
    return () => {
      signalAbortRef.current?.abort()
    }
  }, [])

  useEffect(() => {
    if (!showSignals) {
      signalAbortRef.current?.abort()
      setSignals([])
      setSignalsLoading(false)
      lastSignalFetchRef.current = null
      setHoveredSignal(null)
      return
    }

    const center = camera.position.clone()
    lastSignalFetchRef.current = center
    requestSignals(center)
  }, [showSignals, signalTypeFilter, camera, requestSignals, setSignals, setSignalsLoading, setHoveredSignal])

  // Set loading state on mount
  useEffect(() => {
    setIsLoading(true)
  }, [setIsLoading])

  // Use chunk-based loading with LOD
  const { jumpToPosition } = useChunkLoader({
    enabled: true,
    onStarsLoaded: handleStarsLoaded,
  })

  // When selectedStar changes to a new distant star, jump to load chunks around it
  useEffect(() => {
    if (selectedStar && selectedStar.id !== lastSelectedId.current) {
      lastSelectedId.current = selectedStar.id
      const [x, y, z] = galacticToScene(selectedStar.x, selectedStar.y, selectedStar.z)
      const starPos = new THREE.Vector3(x, y, z)

      // Check if star is far from origin (where we likely are)
      const distFromOrigin = starPos.length()
      if (distFromOrigin > 30) {
        if (import.meta.env.DEV) {
          console.log(`Selected distant star ${selectedStar.display_name} at ${distFromOrigin.toFixed(1)}pc - loading chunks`)
        }
        jumpToPosition(starPos)
      }
    }
  }, [selectedStar, jumpToPosition])

  // Update grid center when camera moves significantly
  useFrame(({ camera }: { camera: THREE.Camera }) => {
    // Initialize grid at camera position on first frame
    if (!gridInitialized.current) {
      gridInitialized.current = true
      lastGridUpdateRef.current = camera.position.clone()
      setGridCenter([camera.position.x, camera.position.y, camera.position.z])
      return
    }

    if (!lastGridUpdateRef.current) return

    const dist = camera.position.distanceTo(lastGridUpdateRef.current)
    if (dist > 15) { // Update grid when camera moves more than 15pc
      lastGridUpdateRef.current.copy(camera.position)
      setGridCenter([camera.position.x, camera.position.y, camera.position.z])
    }
  })

  useFrame(({ camera }: { camera: THREE.Camera }) => {
    if (!showSignals) {
      return
    }

    const lastCenter = lastSignalFetchRef.current
    if (!lastCenter) {
      const center = camera.position.clone()
      lastSignalFetchRef.current = center
      requestSignals(center)
      return
    }

    const distance = camera.position.distanceTo(lastCenter)
    if (distance > SIGNAL_UPDATE_THRESHOLD) {
      const center = camera.position.clone()
      lastSignalFetchRef.current = center
      requestSignals(center)
    }
  })

  // Conversion factor for distance filter
  const PC_TO_LY = 3.26156

  // Debounce filter values to prevent excessive re-filtering on slider adjustments
  // 300ms delay provides smooth UX while reducing CPU usage
  const filterValues = useMemo(
    () => ({
      magLimit,
      spectralFilter,
      maxDistance,
      constellationFilter,
    }),
    [magLimit, spectralFilter, maxDistance, constellationFilter]
  )

  const [debouncedFilters] = useDebounce(filterValues, 300)

  // Filter stars by magnitude, spectral type, distance, and constellation
  const filteredStars = useMemo(() => {
    // Convert maxDistance to parsecs if needed
    const maxDistPc = debouncedFilters.maxDistance !== null
      ? (unit === 'ly' ? debouncedFilters.maxDistance / PC_TO_LY : debouncedFilters.maxDistance)
      : null

    const filtered = stars.filter((star) => {
      // Magnitude filter
      const mag = star.absmag ?? 20
      if (mag > debouncedFilters.magLimit) return false

      // Spectral type filter
      if (debouncedFilters.spectralFilter.length > 0 && star.spect) {
        // Get first character of spectral type (O, B, A, F, G, K, M)
        const spectralClass = star.spect.charAt(0).toUpperCase()
        if (!debouncedFilters.spectralFilter.includes(spectralClass)) return false
      } else if (debouncedFilters.spectralFilter.length > 0 && !star.spect) {
        // If filter is active but star has no spectral type, exclude it
        return false
      }

      // Distance filter (distance from origin in parsecs)
      if (maxDistPc !== null) {
        const dist = Math.sqrt(star.x * star.x + star.y * star.y + star.z * star.z)
        if (dist > maxDistPc) return false
      }

      // Constellation filter
      if (debouncedFilters.constellationFilter !== null && star.con !== debouncedFilters.constellationFilter) {
        return false
      }

      return true
    })
    if (import.meta.env.DEV) {
      console.log(`Filtered stars: ${filtered.length} of ${stars.length} (magLimit=${debouncedFilters.magLimit}, spectral=${debouncedFilters.spectralFilter.length > 0 ? debouncedFilters.spectralFilter.join(',') : 'all'}, maxDist=${maxDistPc?.toFixed(1) ?? 'none'}, con=${debouncedFilters.constellationFilter ?? 'all'})`)
    }
    return filtered
  }, [stars, debouncedFilters, unit])

  // Handle star hover
  const handleHover = useCallback(
    (star: Star | null) => {
      setHoveredStar(star)
    },
    [setHoveredStar]
  )

  // Handle star click
  const handleClick = useCallback(
    (star: Star | null) => {
      // Handle measure mode
      if (measure.active && star) {
        const [x, y, z] = projectStarToScene(star, viewMode)
        const point = { star, position: [x, y, z] as [number, number, number] }

        if (!measure.point1) {
          setMeasurePoint1(point)
        } else if (!measure.point2) {
          setMeasurePoint2(point)
        } else {
          // Both points set, start new measurement
          setMeasurePoint1(point)
          setMeasurePoint2(null)
        }
        return
      }

      // Normal selection mode
      setSelectedStar(star)
      if (!measure.active) {
        setSelectedSignal(null)
      }
      if (import.meta.env.DEV && star) {
        console.log('Selected star:', star.display_name, star)
      }
    },
    [setSelectedStar, setSelectedSignal, measure.active, measure.point1, measure.point2, setMeasurePoint1, setMeasurePoint2, viewMode]
  )

  const handleSignalSelect = useCallback(
    (signal: Signal | null) => {
      setSelectedSignal(signal)
      if (signal) {
        setSelectedStar(null)
      }
    },
    [setSelectedSignal, setSelectedStar]
  )

  const handleSignalHover = useCallback(
    (signal: Signal | null) => {
      setHoveredSignal(signal)
    },
    [setHoveredSignal]
  )

  const positionsEqual = useCallback((a: [number, number, number], b: [number, number, number]) => {
    return a[0] === b[0] && a[1] === b[1] && a[2] === b[2]
  }, [])

  const reprojectMeasurePoint = useCallback(
    (point: MeasurePoint | null): MeasurePoint | null => {
      if (!point) return null

      if (point.star) {
        const projected = projectStarToScene(point.star, viewMode)
        if (positionsEqual(point.position, projected)) {
          return point
        }
        return { ...point, position: projected }
      }

      const projected = projectSceneCoords(point.position, viewMode)
      if (positionsEqual(point.position, projected)) {
        return point
      }
      return { ...point, position: projected }
    },
    [positionsEqual, viewMode]
  )

  useEffect(() => {
    const nextPoint1 = reprojectMeasurePoint(measure.point1)
    if (nextPoint1 !== measure.point1) {
      setMeasurePoint1(nextPoint1)
    }

    const nextPoint2 = reprojectMeasurePoint(measure.point2)
    if (nextPoint2 !== measure.point2) {
      setMeasurePoint2(nextPoint2)
    }
  }, [measure.point1, measure.point2, reprojectMeasurePoint, setMeasurePoint1, setMeasurePoint2])

  return (
    <>
      {gridSettings.visible && (
        <GalacticGrid
          size={100}
          spacing={gridSettings.spacing}
          show3D={gridSettings.show3D}
          coordinateSystem={coordinateSystem}
          center={gridCenter}
          color={printableView ? '#5c5c5c' : undefined}
          color3D={printableView ? '#9a9a9a' : undefined}
          opacity={printableView ? 0.4 : 0.8}
        />
      )}
      {!printableView && (
        <MilkyWayGlow />
      )}
      {!printableView && (
        <StarGlow
          stars={filteredStars}
          starsInitializedRef={starsInitializedRef}
          viewMode={viewMode}
        />
      )}
      <ErrorBoundary fallback={<group />}>
        <InstancedStars
          stars={filteredStars}
          viewMode={viewMode}
          printableView={printableView}
          onInitialized={() => {
            starsInitializedRef.current = true
          }}
        />
      </ErrorBoundary>
      <SelectionIndicator star={selectedStar} viewMode={viewMode} />
      {!printableView && showSignals && (
        <ErrorBoundary fallback={<group />}>
          <SignalsLayer
            signals={signals}
            selectedSignalId={selectedSignal?.id ?? null}
            hoveredSignalId={hoveredSignal?.id ?? null}
            onSelect={handleSignalSelect}
            onHover={handleSignalHover}
          />
        </ErrorBoundary>
      )}
      {showLabels && (
        <StarLabels
          stars={filteredStars}
          labelMagLimit={labelMagLimit}
          selectedStar={selectedStar}
          viewMode={viewMode}
        />
      )}
      <GPUPicker
        stars={filteredStars}
        viewMode={viewMode}
        onHover={handleHover}
        onClick={handleClick}
      />
      {measure.active && <MeasureLine />}
    </>
  )
}
