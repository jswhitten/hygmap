/**
 * Settings panel with gear icon toggle
 */

import { useState, useEffect, useCallback } from 'react'
import {
  useConstellationFilter,
  useCoordinateSystem,
  useGridSettings,
  useLabelMagLimit,
  useMagLimit,
  useMaxDistance,
  usePrintableView,
  useSetConstellationFilter,
  useSetCoordinateSystem,
  useSetLabelMagLimit,
  useSetMagLimit,
  useSetMaxDistance,
  useSetPrintableView,
  useSetSettingsOpen,
  useSetShowLabels,
  useSetShowSignals,
  useSetSignalTypeFilter,
  useSetSpectralFilter,
  useSetUnit,
  useSetViewMode,
  useSettingsOpen,
  useShowLabels,
  useShowSignals,
  useSignalTypeFilter,
  useSpectralFilter,
  useStars,
  useUnit,
  useUpdateGridSettings,
  useViewMode,
} from '../state/store'
import type { ViewMode } from '../domain/viewMode'
import './Settings.css'

// Tab definitions (constant, outside component to avoid recreation)
const TABS = [
  { id: 'filters', label: 'Filters' },
  { id: 'display', label: 'Display' },
  { id: 'view', label: 'View' },
] as const

type TabId = (typeof TABS)[number]['id']

export default function Settings() {
  const settingsOpen = useSettingsOpen()
  const setSettingsOpen = useSetSettingsOpen()
  const magLimit = useMagLimit()
  const setMagLimit = useSetMagLimit()
  const labelMagLimit = useLabelMagLimit()
  const setLabelMagLimit = useSetLabelMagLimit()
  const showLabels = useShowLabels()
  const setShowLabels = useSetShowLabels()
  const gridSettings = useGridSettings()
  const updateGridSettings = useUpdateGridSettings()
  const viewMode = useViewMode()
  const setViewMode = useSetViewMode()
  const unit = useUnit()
  const setUnit = useSetUnit()
  const coordinateSystem = useCoordinateSystem()
  const setCoordinateSystem = useSetCoordinateSystem()
  const spectralFilter = useSpectralFilter()
  const setSpectralFilter = useSetSpectralFilter()
  const maxDistance = useMaxDistance()
  const setMaxDistance = useSetMaxDistance()
  const constellationFilter = useConstellationFilter()
  const setConstellationFilter = useSetConstellationFilter()
  const stars = useStars()
  const printableView = usePrintableView()
  const setPrintableView = useSetPrintableView()
  const showSignals = useShowSignals()
  const setShowSignals = useSetShowSignals()
  const signalTypeFilter = useSignalTypeFilter()
  const setSignalTypeFilter = useSetSignalTypeFilter()

  // Spectral types (main sequence classes)
  const SPECTRAL_TYPES = ['O', 'B', 'A', 'F', 'G', 'K', 'M']

  // Get unique constellations from loaded stars
  const constellations = [...new Set(stars.map(s => s.con).filter(Boolean))].sort() as string[]

  // Toggle a spectral type in the filter
  const toggleSpectralType = (type: string) => {
    if (spectralFilter.length === 0) {
      // Currently showing all - switch to showing only this type
      setSpectralFilter([type])
    } else if (spectralFilter.includes(type)) {
      // Remove this type
      const newFilter = spectralFilter.filter(t => t !== type)
      setSpectralFilter(newFilter)
    } else {
      // Add this type
      setSpectralFilter([...spectralFilter, type])
    }
  }

  // Check if a spectral type is enabled
  const isSpectralTypeEnabled = (type: string) => {
    return spectralFilter.length === 0 || spectralFilter.includes(type)
  }

  // Conversion: 1 parsec = 3.26156 light-years
  const PC_TO_LY = 3.26156

  // Convert spacing for display based on current unit
  const displaySpacing = unit === 'ly' ? gridSettings.spacing * PC_TO_LY : gridSettings.spacing
  const unitLabel = unit === 'ly' ? 'ly' : 'pc'
  const [activeTab, setActiveTab] = useState<TabId>('filters')

  // Handle ESC key to close settings
  useEffect(() => {
    if (!settingsOpen) return

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setSettingsOpen(false)
      }
    }

    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [settingsOpen, setSettingsOpen])

  // Handle arrow key navigation for TABS
  const handleTabKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLButtonElement>, currentIndex: number) => {
      const tabIds = TABS.map((t) => t.id)
      let newIndex = currentIndex

      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
        e.preventDefault()
        newIndex = (currentIndex + 1) % tabIds.length
      } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
        e.preventDefault()
        newIndex = (currentIndex - 1 + tabIds.length) % tabIds.length
      } else if (e.key === 'Home') {
        e.preventDefault()
        newIndex = 0
      } else if (e.key === 'End') {
        e.preventDefault()
        newIndex = tabIds.length - 1
      } else {
        return
      }

      setActiveTab(tabIds[newIndex])
      document.getElementById(`settings-tab-${tabIds[newIndex]}`)?.focus()
    },
    [TABS]
  )

  const renderFiltersTab = () => (
    <div className="settings-section">
      <h4>Filters</h4>

      <label className="settings-row">
        <span>Star magnitude limit:</span>
        <input
          type="range"
          min="-2"
          max="20"
          step="0.5"
          value={magLimit}
          onChange={(e) => setMagLimit(parseFloat(e.target.value))}
        />
        <span className="settings-value">{magLimit.toFixed(1)}</span>
      </label>
      <p className="settings-hint">
        Lower values show only brighter stars. The Sun has magnitude 4.83.
      </p>

      <label className="settings-row">
        <span>Label magnitude limit:</span>
        <input
          type="range"
          min="-2"
          max="10"
          step="0.5"
          value={labelMagLimit}
          onChange={(e) => setLabelMagLimit(parseFloat(e.target.value))}
        />
        <span className="settings-value">{labelMagLimit.toFixed(1)}</span>
      </label>

      <div className="settings-row-vertical">
        <span>Spectral types:</span>
        <div className="spectral-buttons">
          {SPECTRAL_TYPES.map(type => (
            <button
              key={type}
              className={`spectral-button spectral-${type} ${isSpectralTypeEnabled(type) ? 'active' : ''}`}
              onClick={() => toggleSpectralType(type)}
              title={`${type}-type stars`}
            >
              {type}
            </button>
          ))}
          <button
            className={`spectral-button spectral-all ${spectralFilter.length === 0 ? 'active' : ''}`}
            onClick={() => setSpectralFilter([])}
            title="Show all types"
          >
            All
          </button>
        </div>
      </div>

      <label className="settings-row" htmlFor="max-distance-input">
        <span>Max distance:</span>
        <input
          id="max-distance-input"
          type="number"
          min="1"
          max="1000"
          placeholder="No limit"
          value={maxDistance ?? ''}
          onChange={(e) => {
            const val = e.target.value
            setMaxDistance(val ? parseFloat(val) : null)
          }}
          aria-label={`Maximum distance in ${unitLabel}`}
        />
        <span className="settings-value" aria-hidden="true">{unitLabel}</span>
      </label>
      {maxDistance !== null && (
        <button
          className="settings-clear-filter"
          onClick={() => setMaxDistance(null)}
        >
          Clear distance limit
        </button>
      )}

      <label className="settings-row" htmlFor="constellation-filter">
        <span>Constellation:</span>
        <select
          id="constellation-filter"
          value={constellationFilter ?? ''}
          onChange={(e) => setConstellationFilter(e.target.value || null)}
          aria-label="Filter by constellation"
        >
          <option value="">All constellations</option>
          {constellations.map(con => (
            <option key={con} value={con}>{con}</option>
          ))}
        </select>
      </label>
    </div>
  )

  const renderDisplayTab = () => (
    <div className="settings-section">
      <h4>Display</h4>

      <label className="settings-row" htmlFor="unit-select">
        <span>Unit:</span>
        <select
          id="unit-select"
          value={unit}
          onChange={(e) => setUnit(e.target.value as 'pc' | 'ly')}
          aria-label="Distance unit"
        >
          <option value="pc">Parsecs</option>
          <option value="ly">Light-years</option>
        </select>
      </label>

      <label className="settings-row" htmlFor="coordinate-system-select">
        <span>Coordinates:</span>
        <select
          id="coordinate-system-select"
          value={coordinateSystem}
          onChange={(e) => setCoordinateSystem(e.target.value as 'cartesian' | 'polar')}
          aria-label="Coordinate system"
        >
          <option value="cartesian">Cartesian (X, Y, Z)</option>
          <option value="polar">Spherical (r, θ, φ)</option>
        </select>
      </label>

      <label className="settings-row" htmlFor="grid-spacing-input">
        <span>Grid spacing:</span>
        <input
          id="grid-spacing-input"
          type="number"
          min="1"
          max="100"
          value={Math.round(displaySpacing)}
          onChange={(e) => {
            const value = parseFloat(e.target.value) || 10
            // Convert back to parsecs for storage
            const spacingPc = unit === 'ly' ? value / PC_TO_LY : value
            updateGridSettings({ spacing: Math.max(1, Math.min(100, spacingPc)) })
          }}
          aria-label={`Grid spacing in ${unitLabel}`}
        />
        <span className="settings-value" aria-hidden="true">{unitLabel}</span>
      </label>

      <label className="settings-row" htmlFor="show-grid-checkbox">
        <span>Show grid:</span>
        <input
          id="show-grid-checkbox"
          type="checkbox"
          checked={gridSettings.visible}
          onChange={(e) => updateGridSettings({ visible: e.target.checked })}
        />
      </label>

      <label className={`settings-row ${!gridSettings.visible ? 'disabled' : ''}`}>
        <span>Show 3D grid:</span>
        <input
          type="checkbox"
          checked={gridSettings.show3D}
          disabled={!gridSettings.visible}
          onChange={(e) => updateGridSettings({ show3D: e.target.checked })}
        />
      </label>

      <label className="settings-row">
        <span>Show star labels:</span>
        <input
          type="checkbox"
          checked={showLabels}
          onChange={(e) => setShowLabels(e.target.checked)}
        />
      </label>

      <label className="settings-row">
        <span>Show SETI signals:</span>
        <input
          type="checkbox"
          checked={showSignals}
          onChange={(e) => setShowSignals(e.target.checked)}
        />
      </label>

      <label className={`settings-row ${!showSignals ? 'disabled' : ''}`}>
        <span>Signal type:</span>
        <select
          value={signalTypeFilter}
          disabled={!showSignals}
          onChange={(e) => setSignalTypeFilter(e.target.value as 'all' | 'transmit' | 'receive')}
        >
          <option value="all">All signals</option>
          <option value="transmit">Transmissions</option>
          <option value="receive">Detections</option>
        </select>
      </label>

      <p className="settings-hint">
        Signals render as directional arc triplets pointing away from Sol. Transmitters use warm hues while detections use cool blues.
      </p>

      <label className="settings-row" htmlFor="printable-view-checkbox">
        <span>Printable view:</span>
        <input
          id="printable-view-checkbox"
          type="checkbox"
          checked={printableView}
          onChange={(e) => setPrintableView(e.target.checked)}
        />
      </label>
      <p className="settings-hint">
        Switch to a white background with black stars and minimal UI so screenshots print cleanly.
      </p>
    </div>
  )

  const renderViewTab = () => (
    <div className="settings-section">
      <h4>View</h4>

      <label className="settings-row">
        <span>View mode:</span>
        <select
          value={viewMode}
          onChange={(e) => setViewMode(e.target.value as ViewMode)}
        >
          <option value="3d-free">3D (Free Orbit)</option>
          <option value="3d-locked">3D Locked (Top-down camera)</option>
          <option value="2d-flat">2D Flat Map (XY only)</option>
        </select>
      </label>
      <p className="settings-hint">
        3D Locked keeps the classic top-down camera; 2D Flat projects every star onto the XY plane for a true map view.
      </p>

      <label className="settings-row disabled">
        <span>Camera mode:</span>
        <select disabled>
          <option>Orbit</option>
          <option>First-person</option>
        </select>
      </label>
    </div>
  )

  return (
    <>
      {/* Gear icon button */}
      <button
        className="settings-button"
        onClick={() => setSettingsOpen(!settingsOpen)}
        title="Settings"
        aria-label="Settings"
        aria-expanded={settingsOpen}
      >
        <svg
          width="24"
          height="24"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden="true"
        >
          <circle cx="12" cy="12" r="3" />
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" />
        </svg>
      </button>

      {/* Settings panel */}
      {settingsOpen && (
        <div className="settings-panel" role="dialog" aria-label="Settings panel" aria-modal="true">
          <div className="settings-header">
            <h3>Settings</h3>
            <button
              className="settings-close"
              onClick={() => setSettingsOpen(false)}
              aria-label="Close settings"
            >
              &times;
            </button>
          </div>
          <div className="settings-tabs" role="tablist" aria-label="Settings sections">
            {TABS.map((tab, index) => (
              <button
                key={tab.id}
                id={`settings-tab-${tab.id}`}
                role="tab"
                type="button"
                className={`settings-tab ${activeTab === tab.id ? 'active' : ''}`}
                aria-selected={activeTab === tab.id}
                aria-controls={`settings-panel-${tab.id}`}
                onClick={() => setActiveTab(tab.id)}
                onKeyDown={(e) => handleTabKeyDown(e, index)}
              >
                {tab.label}
              </button>
            ))}
          </div>

          <div
            className="settings-tabpanel"
            role="tabpanel"
            id={`settings-panel-${activeTab}`}
            aria-labelledby={`settings-tab-${activeTab}`}
          >
            {activeTab === 'filters' && renderFiltersTab()}
            {activeTab === 'display' && renderDisplayTab()}
            {activeTab === 'view' && renderViewTab()}
          </div>
        </div>
      )}
    </>
  )
}
