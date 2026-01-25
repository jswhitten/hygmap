/**
 * Application state management with Zustand
 */

import { create } from 'zustand'
import { persist, createJSONStorage } from 'zustand/middleware'
import type { Star } from '../types/star'
import type { Signal, SignalType } from '../types/signal'
import { DEFAULT_VIEW_MODE } from '../domain/viewMode'
import type { ViewMode } from '../domain/viewMode'

interface GridSettings {
  visible: boolean
  showXY: boolean // Galactic plane
  show3D: boolean // XZ and YZ planes
  spacing: number // parsecs
}

// Measuring tool state
interface MeasurePoint {
  star?: Star // If clicked on a star
  position: [number, number, number] // Scene coordinates
}

interface MeasureState {
  active: boolean
  point1: MeasurePoint | null
  point2: MeasurePoint | null
}

type SignalFilter = 'all' | SignalType

interface AppState {
  // Star data
  stars: Star[]
  setStars: (stars: Star[]) => void

  // Signals overlay
  signals: Signal[]
  setSignals: (signals: Signal[]) => void
  showSignals: boolean
  setShowSignals: (visible: boolean) => void
  signalTypeFilter: SignalFilter
  setSignalTypeFilter: (filter: SignalFilter) => void
  selectedSignal: Signal | null
  setSelectedSignal: (signal: Signal | null) => void
  hoveredSignal: Signal | null
  setHoveredSignal: (signal: Signal | null) => void
  signalsLoading: boolean
  setSignalsLoading: (loading: boolean) => void

  // Selection
  selectedStar: Star | null
  setSelectedStar: (star: Star | null) => void
  hoveredStar: Star | null
  setHoveredStar: (star: Star | null) => void

  // View mode
  viewMode: ViewMode
  setViewMode: (mode: ViewMode) => void

  // Display unit
  unit: 'pc' | 'ly'
  setUnit: (unit: 'pc' | 'ly') => void

  // Coordinate system
  coordinateSystem: 'cartesian' | 'polar'
  setCoordinateSystem: (system: 'cartesian' | 'polar') => void

  // Grid settings
  gridSettings: GridSettings
  updateGridSettings: (settings: Partial<GridSettings>) => void

  // Filters
  magLimit: number
  setMagLimit: (limit: number) => void
  labelMagLimit: number
  setLabelMagLimit: (limit: number) => void
  showLabels: boolean
  setShowLabels: (show: boolean) => void

  // Advanced filters
  spectralFilter: string[] // Empty array = all types, or list of types to show
  setSpectralFilter: (types: string[]) => void
  maxDistance: number | null // Max distance from origin in parsecs, null = no limit
  setMaxDistance: (distance: number | null) => void
  constellationFilter: string | null // Constellation abbreviation or null for all
  setConstellationFilter: (con: string | null) => void

  // Loading state
  isLoading: boolean
  setIsLoading: (loading: boolean) => void

  // Error state
  error: string | null
  setError: (error: string | null) => void

  // Settings panel
  settingsOpen: boolean
  setSettingsOpen: (open: boolean) => void

  // Printable view
  printableView: boolean
  setPrintableView: (enabled: boolean) => void

  // Measuring tool
  measure: MeasureState
  setMeasureActive: (active: boolean) => void
  setMeasurePoint1: (point: MeasurePoint | null) => void
  setMeasurePoint2: (point: MeasurePoint | null) => void
  clearMeasure: () => void
}

const createFallbackStorage = (): Storage => {
  let store: Record<string, string> = {}
  return {
    get length() {
      return Object.keys(store).length
    },
    clear() {
      store = {}
    },
    getItem(key: string) {
      return Object.prototype.hasOwnProperty.call(store, key) ? store[key] : null
    },
    key(index: number) {
      const keys = Object.keys(store)
      return keys[index] ?? null
    },
    removeItem(key: string) {
      delete store[key]
    },
    setItem(key: string, value: string) {
      store[key] = value
    },
  }
}

const fallbackStorage = createFallbackStorage()

const isUsableStorage = (candidate: Storage | undefined | null): candidate is Storage =>
  !!candidate &&
  typeof candidate.getItem === 'function' &&
  typeof candidate.setItem === 'function' &&
  typeof candidate.removeItem === 'function'

const getActiveStorage = () => {
  if (typeof window !== 'undefined' && 'localStorage' in window && isUsableStorage(window.localStorage)) {
    return window.localStorage
  }
  return fallbackStorage
}

const stateStorage = createJSONStorage(getActiveStorage)

export const clearPersistedSettings = () => {
  const storage = getActiveStorage()
  storage.removeItem('hygmap-settings')
  if (storage === fallbackStorage) {
    fallbackStorage.clear()
  }
}

export const useAppStore = create<AppState>()(persist((set) => ({
  // Star data
  stars: [],
  setStars: (stars) => set({ stars }),

  // Signals overlay
  signals: [],
  setSignals: (signals) => set({ signals }),
  showSignals: false,
  setShowSignals: (visible) => set({ showSignals: visible }),
  signalTypeFilter: 'all',
  setSignalTypeFilter: (filter) => set({ signalTypeFilter: filter }),
  selectedSignal: null,
  setSelectedSignal: (signal) => set({ selectedSignal: signal }),
  hoveredSignal: null,
  setHoveredSignal: (signal) => set({ hoveredSignal: signal }),
  signalsLoading: false,
  setSignalsLoading: (loading) => set({ signalsLoading: loading }),

  // Selection
  selectedStar: null,
  setSelectedStar: (star) => set({ selectedStar: star }),
  hoveredStar: null,
  setHoveredStar: (star) => set({ hoveredStar: star }),

  // View mode
  viewMode: DEFAULT_VIEW_MODE,
  setViewMode: (mode) => set({ viewMode: mode }),

  // Display unit
  unit: 'pc',
  setUnit: (unit) => set({ unit }),

  // Coordinate system
  coordinateSystem: 'cartesian',
  setCoordinateSystem: (coordinateSystem) => set({ coordinateSystem }),

  // Grid settings
  gridSettings: {
    visible: true,
    showXY: true,
    show3D: false,
    spacing: 10,
  },
  updateGridSettings: (settings) =>
    set((state) => ({
      gridSettings: { ...state.gridSettings, ...settings },
    })),

  // Filters
  magLimit: 10,
  setMagLimit: (limit) => set({ magLimit: limit }),
  labelMagLimit: 6,
  setLabelMagLimit: (limit) => set({ labelMagLimit: limit }),
  showLabels: true,
  setShowLabels: (show) => set({ showLabels: show }),

  // Advanced filters
  spectralFilter: [], // Empty = show all
  setSpectralFilter: (types) => set({ spectralFilter: types }),
  maxDistance: null, // No limit by default
  setMaxDistance: (distance) => set({ maxDistance: distance }),
  constellationFilter: null, // Show all constellations
  setConstellationFilter: (con) => set({ constellationFilter: con }),

  // Loading state
  isLoading: false,
  setIsLoading: (loading) => set({ isLoading: loading }),

  // Error state
  error: null,
  setError: (error) => set({ error: error }),

  // Settings panel
  settingsOpen: false,
  setSettingsOpen: (open) => set({ settingsOpen: open }),

  // Printable view
  printableView: false,
  setPrintableView: (enabled) => set({ printableView: enabled }),

  // Measuring tool
  measure: {
    active: false,
    point1: null,
    point2: null,
  },
  setMeasureActive: (active) =>
    set((state) => ({
      measure: { ...state.measure, active, point1: null, point2: null },
    })),
  setMeasurePoint1: (point) =>
    set((state) => ({
      measure: { ...state.measure, point1: point },
    })),
  setMeasurePoint2: (point) =>
    set((state) => ({
      measure: { ...state.measure, point2: point },
    })),
  clearMeasure: () =>
    set((state) => ({
      measure: { ...state.measure, point1: null, point2: null },
    })),
}), {
  name: 'hygmap-settings',
  storage: stateStorage,
  version: 1,
  partialize: (state) => ({
    viewMode: state.viewMode,
    unit: state.unit,
    coordinateSystem: state.coordinateSystem,
    gridSettings: state.gridSettings,
    magLimit: state.magLimit,
    labelMagLimit: state.labelMagLimit,
    showLabels: state.showLabels,
    spectralFilter: state.spectralFilter,
    maxDistance: state.maxDistance,
    constellationFilter: state.constellationFilter,
    printableView: state.printableView,
    showSignals: state.showSignals,
    signalTypeFilter: state.signalTypeFilter,
  }),
  merge: (persistedState, currentState) => {
    const persisted = persistedState as Partial<AppState>
    return {
      ...currentState,
      ...persisted,
      gridSettings: {
        ...currentState.gridSettings,
        ...(persisted.gridSettings ?? {}),
      },
    }
  },
}))

// Export types for external use
export type { MeasurePoint, MeasureState }

// Selector hooks to prevent unnecessary re-renders
// Use these instead of destructuring the entire store
//
// WHY: Zustand re-renders components when ANY subscribed state changes.
// Using selectors ensures components only re-render when their specific data changes.
//
// BEFORE (causes unnecessary re-renders):
//   const { stars, setStars, magLimit, ... } = useAppStore()
//
// AFTER (optimal performance):
//   const stars = useStars()
//   const setStars = useSetStars()
//
// Example: If magLimit changes but your component only uses stars,
// the BEFORE approach re-renders unnecessarily, AFTER approach doesn't.

// Star data selectors
export const useStars = () => useAppStore((state) => state.stars)
export const useSetStars = () => useAppStore((state) => state.setStars)
export const useSignalsData = () => useAppStore((state) => state.signals)
export const useSetSignalsData = () => useAppStore((state) => state.setSignals)
export const useShowSignals = () => useAppStore((state) => state.showSignals)
export const useSetShowSignals = () => useAppStore((state) => state.setShowSignals)
export const useSignalTypeFilter = () => useAppStore((state) => state.signalTypeFilter)
export const useSetSignalTypeFilter = () => useAppStore((state) => state.setSignalTypeFilter)
export const useSelectedSignal = () => useAppStore((state) => state.selectedSignal)
export const useSetSelectedSignal = () => useAppStore((state) => state.setSelectedSignal)
export const useHoveredSignal = () => useAppStore((state) => state.hoveredSignal)
export const useSetHoveredSignal = () => useAppStore((state) => state.setHoveredSignal)
export const useSignalsLoading = () => useAppStore((state) => state.signalsLoading)
export const useSetSignalsLoading = () => useAppStore((state) => state.setSignalsLoading)

// Selection selectors
export const useSelectedStar = () => useAppStore((state) => state.selectedStar)
export const useSetSelectedStar = () => useAppStore((state) => state.setSelectedStar)
export const useHoveredStar = () => useAppStore((state) => state.hoveredStar)
export const useSetHoveredStar = () => useAppStore((state) => state.setHoveredStar)

// View mode selectors
export const useViewMode = () => useAppStore((state) => state.viewMode)
export const useSetViewMode = () => useAppStore((state) => state.setViewMode)

// Display settings selectors
export const useUnit = () => useAppStore((state) => state.unit)
export const useSetUnit = () => useAppStore((state) => state.setUnit)
export const useCoordinateSystem = () => useAppStore((state) => state.coordinateSystem)
export const useSetCoordinateSystem = () => useAppStore((state) => state.setCoordinateSystem)

// Grid selectors
export const useGridSettings = () => useAppStore((state) => state.gridSettings)
export const useUpdateGridSettings = () => useAppStore((state) => state.updateGridSettings)

// Filter selectors (grouped for common usage patterns)
export const useFilters = () =>
  useAppStore((state) => ({
    magLimit: state.magLimit,
    spectralFilter: state.spectralFilter,
    maxDistance: state.maxDistance,
    constellationFilter: state.constellationFilter,
  }))

export const useMagLimit = () => useAppStore((state) => state.magLimit)
export const useSetMagLimit = () => useAppStore((state) => state.setMagLimit)
export const useSpectralFilter = () => useAppStore((state) => state.spectralFilter)
export const useSetSpectralFilter = () => useAppStore((state) => state.setSpectralFilter)
export const useMaxDistance = () => useAppStore((state) => state.maxDistance)
export const useSetMaxDistance = () => useAppStore((state) => state.setMaxDistance)
export const useConstellationFilter = () => useAppStore((state) => state.constellationFilter)
export const useSetConstellationFilter = () => useAppStore((state) => state.setConstellationFilter)

// Label selectors
export const useLabelMagLimit = () => useAppStore((state) => state.labelMagLimit)
export const useSetLabelMagLimit = () => useAppStore((state) => state.setLabelMagLimit)
export const useShowLabels = () => useAppStore((state) => state.showLabels)
export const useSetShowLabels = () => useAppStore((state) => state.setShowLabels)

// Loading/Error selectors
export const useIsLoading = () => useAppStore((state) => state.isLoading)
export const useSetIsLoading = () => useAppStore((state) => state.setIsLoading)
export const useError = () => useAppStore((state) => state.error)
export const useSetError = () => useAppStore((state) => state.setError)

// Settings panel selectors
export const useSettingsOpen = () => useAppStore((state) => state.settingsOpen)
export const useSetSettingsOpen = () => useAppStore((state) => state.setSettingsOpen)

// Printable view selectors
export const usePrintableView = () => useAppStore((state) => state.printableView)
export const useSetPrintableView = () => useAppStore((state) => state.setPrintableView)

// Measure tool selectors
export const useMeasure = () => useAppStore((state) => state.measure)
export const useSetMeasureActive = () => useAppStore((state) => state.setMeasureActive)
export const useSetMeasurePoint1 = () => useAppStore((state) => state.setMeasurePoint1)
export const useSetMeasurePoint2 = () => useAppStore((state) => state.setMeasurePoint2)
export const useClearMeasure = () => useAppStore((state) => state.clearMeasure)
