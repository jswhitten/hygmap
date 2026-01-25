import {
  useHoveredStar,
  useSelectedStar,
  useIsLoading,
  useError,
  useUnit,
  useCoordinateSystem,
} from '../state/store'
import './HUD.css'

// Conversion: 1 parsec = 3.26156 light-years
const PC_TO_LY = 3.26156

/**
 * Heads-up display showing current position and selected star info
 */
export default function HUD() {
  const hoveredStar = useHoveredStar()
  const selectedStar = useSelectedStar()
  const isLoading = useIsLoading()
  const error = useError()
  const unit = useUnit()
  const coordinateSystem = useCoordinateSystem()

  // Show hovered star if any, otherwise selected star
  const displayStar = hoveredStar || selectedStar

  // Format position based on selected unit and coordinate system
  const formatPosition = (x: number, y: number, z: number) => {
    const unitMultiplier = unit === 'ly' ? PC_TO_LY : 1
    const unitLabel = unit === 'ly' ? 'ly' : 'pc'

    if (coordinateSystem === 'polar') {
      // Convert to spherical: r = sqrt(x² + y² + z²), θ = atan2(y, x), φ = elevation from XY plane
      const r = Math.sqrt(x * x + y * y + z * z) * unitMultiplier
      const theta = Math.atan2(y, x) * (180 / Math.PI) // Azimuth in degrees
      const phi = Math.atan2(z, Math.sqrt(x * x + y * y)) * (180 / Math.PI) // Elevation in degrees
      return `r=${r.toFixed(1)} ${unitLabel}, θ=${theta.toFixed(0)}°, φ=${phi.toFixed(0)}°`
    }

    // Cartesian
    const xScaled = x * unitMultiplier
    const yScaled = y * unitMultiplier
    const zScaled = z * unitMultiplier
    return `(${xScaled.toFixed(1)}, ${yScaled.toFixed(1)}, ${zScaled.toFixed(1)}) ${unitLabel}`
  }

  return (
    <div className="hud">
      <div className="hud-section hud-title">
        <h1>HYGMap</h1>
        <p>Interactive 3D Star Map</p>
      </div>

      <div className="hud-section hud-star-info">
        {isLoading && (
          <div className="hud-loading" role="status" aria-live="polite" aria-busy="true">
            Loading stars...
          </div>
        )}
        {error && (
          <div className="hud-error" role="alert" aria-live="assertive">
            Error: {error}
          </div>
        )}

        {displayStar ? (
          <div className="star-info">
            <div className="star-name">{displayStar.display_name}</div>
            <div className="star-details">
              {displayStar.spect && (
                <div>Spectral Type: {displayStar.spect}</div>
              )}
              {displayStar.absmag !== null && displayStar.absmag !== undefined && (
                <div>Abs Mag: {displayStar.absmag.toFixed(2)}</div>
              )}
              <div>
                Position: {formatPosition(displayStar.x, displayStar.y, displayStar.z)}
              </div>
            </div>
            {hoveredStar && selectedStar && hoveredStar.id !== selectedStar.id && (
              <div className="star-hint">Click to select</div>
            )}
          </div>
        ) : (
          <div className="star-hint">Hover over a star for info</div>
        )}

        {selectedStar && !hoveredStar && (
          <div className="selected-indicator">Selected: {selectedStar.display_name}</div>
        )}
      </div>

      <div className="hud-section hud-controls">
        <p>Left-click + drag: rotate</p>
        <p>Right-click + drag: pan</p>
        <p>Scroll: zoom</p>
        <p>Click star: select</p>
        <p>Keyboard: W/A/S/D or arrow keys move; Q/E move up/down</p>
        <p>Keyboard: +/- zoom; R reset to home view</p>
      </div>
    </div>
  )
}
