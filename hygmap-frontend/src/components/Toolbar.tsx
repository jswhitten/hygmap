/**
 * Toolbar - Search bar and navigation buttons
 *
 * Search supports star names and catalog IDs (HIP, HD, HR, GJ, Gaia, TYC).
 */

import { useState, useCallback, useRef, useEffect } from 'react'
import {
  useClearMeasure,
  useCoordinateSystem,
  useMeasure,
  useSelectedStar,
  useSetMeasureActive,
  useSetSelectedStar,
  useUnit,
  useViewMode,
} from '../state/store'
import { searchStars } from '../api/stars'
import { parsecsToLightYears } from '../domain/coordinates'
import { generateShareURL, copyToClipboard } from '../utils/urlState'
import { captureScreenshot, generateScreenshotFilename } from '../utils/screenshot'
import type { Star } from '../types/star'
import './Toolbar.css'

interface ToolbarProps {
  onHome: () => void
  onCenter: (star: Star) => void
  getCameraState: () => { position: [number, number, number]; target: [number, number, number] } | null
  getCanvas: () => HTMLCanvasElement | null
}

export default function Toolbar({ onHome, onCenter, getCameraState, getCanvas }: ToolbarProps) {
  const selectedStar = useSelectedStar()
  const setSelectedStar = useSetSelectedStar()
  const measure = useMeasure()
  const setMeasureActive = useSetMeasureActive()
  const clearMeasure = useClearMeasure()
  const unit = useUnit()
  const viewMode = useViewMode()
  const coordinateSystem = useCoordinateSystem()
  const [linkCopied, setLinkCopied] = useState(false)
  const [screenshotTaken, setScreenshotTaken] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [searchResults, setSearchResults] = useState<Star[]>([])
  const [showResults, setShowResults] = useState(false)
  const [isSearching, setIsSearching] = useState(false)
  const searchRef = useRef<HTMLDivElement>(null)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  // Search for stars by name or catalog ID
  const handleSearch = useCallback((query: string) => {
    setSearchQuery(query)

    // Clear previous debounce
    if (debounceRef.current) {
      clearTimeout(debounceRef.current)
    }

    if (query.length < 2) {
      setSearchResults([])
      setShowResults(false)
      return
    }

    // Debounce API calls
    debounceRef.current = setTimeout(async () => {
      setIsSearching(true)
      try {
        const response = await searchStars(query, 10)
        setSearchResults(response.data)
        setShowResults(response.data.length > 0)
      } catch (error) {
        console.error('Search failed:', error)
        setSearchResults([])
      } finally {
        setIsSearching(false)
      }
    }, 300)
  }, [])

  // Select a star from search results
  const handleSelectResult = useCallback(
    (star: Star) => {
      setSelectedStar(star)
      onCenter(star)
      setSearchQuery('')
      setSearchResults([])
      setShowResults(false)
    },
    [setSelectedStar, onCenter]
  )

  // Close results when clicking outside
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (searchRef.current && !searchRef.current.contains(e.target as Node)) {
        setShowResults(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  // Cleanup debounce on unmount
  useEffect(() => {
    return () => {
      if (debounceRef.current) {
        clearTimeout(debounceRef.current)
      }
    }
  }, [])

  // Handle share link
  const handleShare = useCallback(async () => {
    const cameraState = getCameraState()
    if (!cameraState) return

    const url = generateShareURL({
      cx: cameraState.position[0],
      cy: cameraState.position[1],
      cz: cameraState.position[2],
      tx: cameraState.target[0],
      ty: cameraState.target[1],
      tz: cameraState.target[2],
      star: selectedStar?.id,
      unit,
      view: viewMode,
      coords: coordinateSystem,
    })

    const success = await copyToClipboard(url)
    if (success) {
      setLinkCopied(true)
      setTimeout(() => setLinkCopied(false), 2000)
    }
  }, [getCameraState, selectedStar, unit, viewMode, coordinateSystem])

  // Handle screenshot
  const handleScreenshot = useCallback(() => {
    const canvas = getCanvas()
    if (!canvas) return

    captureScreenshot(canvas, generateScreenshotFilename())
    setScreenshotTaken(true)
    setTimeout(() => setScreenshotTaken(false), 2000)
  }, [getCanvas])

  return (
    <div className="toolbar">
      {/* Search */}
      <div className="toolbar-search" ref={searchRef}>
        <input
          type="text"
          placeholder="Search stars (name or HIP/HD/HR)..."
          value={searchQuery}
          onChange={(e) => handleSearch(e.target.value)}
          onFocus={() => searchResults.length > 0 && setShowResults(true)}
          onBlur={(e) => {
            if (!searchRef.current) return
            const nextTarget = e.relatedTarget as Node | null
            if (!nextTarget || !searchRef.current.contains(nextTarget)) {
              setShowResults(false)
            }
          }}
          aria-label="Search stars by name or catalog ID"
          aria-autocomplete="list"
          aria-controls={showResults ? "search-results-list" : undefined}
          aria-expanded={showResults}
        />
        {isSearching ? (
          <span className="search-spinner">...</span>
        ) : (
          <svg
            className="search-icon"
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
          >
            <circle cx="11" cy="11" r="8" />
            <path d="m21 21-4.35-4.35" />
          </svg>
        )}

        {showResults && (
          <div className="search-results" id="search-results-list" role="listbox" aria-label="Search results">
            {searchResults.map((star) => (
              <button
                key={star.id}
                className="search-result"
                onClick={() => handleSelectResult(star)}
                role="option"
                aria-label={`${star.display_name}${star.spect ? `, spectral type ${star.spect}` : ''}`}
              >
                <span className="result-name">{star.display_name}</span>
                {star.spect && (
                  <span className="result-spect">{star.spect}</span>
                )}
              </button>
            ))}
          </div>
        )}
      </div>

      {/* Navigation buttons */}
      <div className="toolbar-buttons">
        <button
          className="toolbar-button"
          onClick={onHome}
          title="Home - Reset view to Sol"
          aria-label="Home - Reset view to Sol"
        >
          <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            aria-hidden="true"
          >
            <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
            <polyline points="9 22 9 12 15 12 15 22" />
          </svg>
        </button>

        <button
          className="toolbar-button"
          onClick={() => selectedStar && onCenter(selectedStar)}
          disabled={!selectedStar}
          title={selectedStar ? `Center on ${selectedStar.display_name || 'selected star'}` : 'Select a star first'}
          aria-label={selectedStar ? `Center on ${selectedStar.display_name || 'selected star'}` : 'Center on selected star (no star selected)'}
        >
          <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            aria-hidden="true"
          >
            <circle cx="12" cy="12" r="10" />
            <circle cx="12" cy="12" r="3" />
            <line x1="12" y1="2" x2="12" y2="6" />
            <line x1="12" y1="18" x2="12" y2="22" />
            <line x1="2" y1="12" x2="6" y2="12" />
            <line x1="18" y1="12" x2="22" y2="12" />
          </svg>
        </button>

        {/* Measure tool button */}
        <button
          className={`toolbar-button ${measure.active ? 'active' : ''}`}
          onClick={() => setMeasureActive(!measure.active)}
          title={measure.active ? 'Exit measure mode' : 'Measure distance between stars'}
          aria-label={measure.active ? 'Exit measure mode' : 'Measure distance between stars'}
          aria-pressed={measure.active}
        >
          <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            aria-hidden="true"
          >
            <path d="M21.3 15.3a2.4 2.4 0 0 1 0 3.4l-2.6 2.6a2.4 2.4 0 0 1-3.4 0L2.7 8.7a2.41 2.41 0 0 1 0-3.4l2.6-2.6a2.41 2.41 0 0 1 3.4 0Z" />
            <path d="m14.5 12.5 2-2" />
            <path d="m11.5 9.5 2-2" />
            <path d="m8.5 6.5 2-2" />
            <path d="m17.5 15.5 2-2" />
          </svg>
        </button>

        {/* Share link button */}
        <button
          className={`toolbar-button ${linkCopied ? 'success' : ''}`}
          onClick={handleShare}
          title={linkCopied ? 'Link copied!' : 'Copy link to current view'}
          aria-label={linkCopied ? 'Link copied to clipboard' : 'Copy link to current view'}
        >
          {linkCopied ? (
            <svg
              width="18"
              height="18"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
            >
              <polyline points="20 6 9 17 4 12" />
            </svg>
          ) : (
            <svg
              width="18"
              height="18"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
            >
              <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
              <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
            </svg>
          )}
        </button>

        {/* Screenshot button */}
        <button
          className={`toolbar-button ${screenshotTaken ? 'success' : ''}`}
          onClick={handleScreenshot}
          title={screenshotTaken ? 'Screenshot saved!' : 'Save screenshot'}
          aria-label={screenshotTaken ? 'Screenshot saved' : 'Save screenshot'}
        >
          {screenshotTaken ? (
            <svg
              width="18"
              height="18"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
            >
              <polyline points="20 6 9 17 4 12" />
            </svg>
          ) : (
            <svg
              width="18"
              height="18"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
            >
              <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
              <circle cx="12" cy="13" r="4" />
            </svg>
          )}
        </button>
      </div>

      {/* Measure mode indicator and controls */}
      {measure.active && (
        <div className="measure-panel">
          <span className="measure-status">
            {!measure.point1 && 'Click first star'}
            {measure.point1 && !measure.point2 && 'Click second star'}
            {measure.point1 && measure.point2 && (
              <>
                <strong>
                  {(() => {
                    const p1 = measure.point1.position
                    const p2 = measure.point2.position
                    const dx = p2[0] - p1[0]
                    const dy = p2[1] - p1[1]
                    const dz = p2[2] - p1[2]
                    const dist = Math.sqrt(dx * dx + dy * dy + dz * dz)
                    const displayDist = unit === 'ly' ? parsecsToLightYears(dist) : dist
                    return displayDist < 1
                      ? displayDist.toFixed(3)
                      : displayDist < 10
                      ? displayDist.toFixed(2)
                      : displayDist.toFixed(1)
                  })()}
                  {unit === 'ly' ? ' ly' : ' pc'}
                </strong>
                {measure.point1.star && measure.point2.star && (
                  <span className="measure-names">
                    {measure.point1.star.display_name} → {measure.point2.star.display_name}
                  </span>
                )}
              </>
            )}
          </span>
          {(measure.point1 || measure.point2) && (
            <button
              className="measure-clear"
              onClick={clearMeasure}
              title="Clear measurement"
            >
              ✕
            </button>
          )}
        </div>
      )}
    </div>
  )
}
