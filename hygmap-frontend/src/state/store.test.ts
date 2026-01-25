/**
 * Tests for Zustand store
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { useAppStore, clearPersistedSettings } from './store'

describe('useAppStore', () => {
  beforeEach(() => {
  clearPersistedSettings()
  // Reset store to initial state before each test
    useAppStore.setState({
      stars: [],
      signals: [],
      selectedStar: null,
      hoveredStar: null,
      selectedSignal: null,
      hoveredSignal: null,
      showSignals: false,
      signalTypeFilter: 'all',
      signalsLoading: false,
      viewMode: '3d-free',
      unit: 'pc',
      coordinateSystem: 'cartesian',
      magLimit: 10,
      labelMagLimit: 6,
      showLabels: true,
      spectralFilter: [],
      maxDistance: null,
      constellationFilter: null,
      isLoading: false,
      error: null,
      settingsOpen: false,
      measure: { active: false, point1: null, point2: null },
      printableView: false,
    })
  })

  describe('view settings', () => {
    it('should set view mode', () => {
      useAppStore.getState().setViewMode('3d-locked')
      expect(useAppStore.getState().viewMode).toBe('3d-locked')
    })

    it('should set unit', () => {
      useAppStore.getState().setUnit('ly')
      expect(useAppStore.getState().unit).toBe('ly')
    })

    it('should set coordinate system', () => {
      useAppStore.getState().setCoordinateSystem('polar')
      expect(useAppStore.getState().coordinateSystem).toBe('polar')
    })
  })

  describe('filters', () => {
    it('should set magnitude limit', () => {
      useAppStore.getState().setMagLimit(8)
      expect(useAppStore.getState().magLimit).toBe(8)
    })

    it('should set spectral filter', () => {
      useAppStore.getState().setSpectralFilter(['G', 'K', 'M'])
      expect(useAppStore.getState().spectralFilter).toEqual(['G', 'K', 'M'])
    })

    it('should set max distance', () => {
      useAppStore.getState().setMaxDistance(50)
      expect(useAppStore.getState().maxDistance).toBe(50)
    })

    it('should set constellation filter', () => {
      useAppStore.getState().setConstellationFilter('Ori')
      expect(useAppStore.getState().constellationFilter).toBe('Ori')
    })

    it('should clear constellation filter', () => {
      useAppStore.getState().setConstellationFilter('Ori')
      useAppStore.getState().setConstellationFilter(null)
      expect(useAppStore.getState().constellationFilter).toBeNull()
    })
  })

  describe('star selection', () => {
    const mockStar = {
      id: 1,
      x: 0,
      y: 0,
      z: 0,
      display_name: 'Sol',
      absmag: 4.83,
    }

    it('should set selected star', () => {
      useAppStore.getState().setSelectedStar(mockStar)
      expect(useAppStore.getState().selectedStar).toEqual(mockStar)
    })

    it('should clear selected star', () => {
      useAppStore.getState().setSelectedStar(mockStar)
      useAppStore.getState().setSelectedStar(null)
      expect(useAppStore.getState().selectedStar).toBeNull()
    })

    it('should set hovered star', () => {
      useAppStore.getState().setHoveredStar(mockStar)
      expect(useAppStore.getState().hoveredStar).toEqual(mockStar)
    })
  })

  describe('signals overlay', () => {
    const mockSignal = {
      id: 42,
      name: 'Test Signal',
      type: 'transmit' as const,
      x: 1,
      y: 2,
      z: 3,
      display_name: 'Test Signal',
    }

    it('should set signals array', () => {
      useAppStore.getState().setSignals([mockSignal])
      expect(useAppStore.getState().signals).toHaveLength(1)
    })

    it('should toggle signal visibility', () => {
      useAppStore.getState().setShowSignals(true)
      expect(useAppStore.getState().showSignals).toBe(true)
    })

    it('should set signal type filter', () => {
      useAppStore.getState().setSignalTypeFilter('receive')
      expect(useAppStore.getState().signalTypeFilter).toBe('receive')
    })

    it('should set selected signal', () => {
      useAppStore.getState().setSelectedSignal(mockSignal)
      expect(useAppStore.getState().selectedSignal).toEqual(mockSignal)
    })

    it('should set hovered signal', () => {
      useAppStore.getState().setHoveredSignal(mockSignal)
      expect(useAppStore.getState().hoveredSignal).toEqual(mockSignal)
    })
  })

  describe('measuring tool', () => {
    it('should activate measure mode', () => {
      useAppStore.getState().setMeasureActive(true)
      expect(useAppStore.getState().measure.active).toBe(true)
    })

    it('should set measure points', () => {
      const point1 = { position: [1, 2, 3] as [number, number, number] }
      const point2 = { position: [4, 5, 6] as [number, number, number] }

      useAppStore.getState().setMeasureActive(true)
      useAppStore.getState().setMeasurePoint1(point1)
      useAppStore.getState().setMeasurePoint2(point2)

      expect(useAppStore.getState().measure.point1).toEqual(point1)
      expect(useAppStore.getState().measure.point2).toEqual(point2)
    })

    it('should clear measure points when deactivating', () => {
      const point1 = { position: [1, 2, 3] as [number, number, number] }
      useAppStore.getState().setMeasurePoint1(point1)
      useAppStore.getState().setMeasureActive(false)

      expect(useAppStore.getState().measure.point1).toBeNull()
      expect(useAppStore.getState().measure.point2).toBeNull()
    })

    it('should clear measure points', () => {
      const point1 = { position: [1, 2, 3] as [number, number, number] }
      const point2 = { position: [4, 5, 6] as [number, number, number] }

      useAppStore.getState().setMeasurePoint1(point1)
      useAppStore.getState().setMeasurePoint2(point2)
      useAppStore.getState().clearMeasure()

      expect(useAppStore.getState().measure.point1).toBeNull()
      expect(useAppStore.getState().measure.point2).toBeNull()
    })
  })

  describe('loading state', () => {
    it('should set loading state', () => {
      useAppStore.getState().setIsLoading(true)
      expect(useAppStore.getState().isLoading).toBe(true)
    })

    it('should set error', () => {
      useAppStore.getState().setError('Test error')
      expect(useAppStore.getState().error).toBe('Test error')
    })
  })

  describe('settings panel', () => {
    it('should toggle settings panel', () => {
      useAppStore.getState().setSettingsOpen(true)
      expect(useAppStore.getState().settingsOpen).toBe(true)

      useAppStore.getState().setSettingsOpen(false)
      expect(useAppStore.getState().settingsOpen).toBe(false)
    })
  })

  describe('printable view', () => {
    it('should toggle printable view flag', () => {
      expect(useAppStore.getState().printableView).toBe(false)
      useAppStore.getState().setPrintableView(true)
      expect(useAppStore.getState().printableView).toBe(true)
    })
  })
})
