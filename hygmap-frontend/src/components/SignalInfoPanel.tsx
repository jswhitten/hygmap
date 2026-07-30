import { useCallback } from 'react'
import { useAppStore } from '../state/store'
import { parsecsToLightYears } from '../domain/coordinates'
import './SignalInfoPanel.css'

const formatter = new Intl.DateTimeFormat(undefined, {
  dateStyle: 'medium',
  timeStyle: 'short',
})

export default function SignalInfoPanel() {
  const { selectedSignal, hoveredSignal, setSelectedSignal, setHoveredSignal, showSignals } = useAppStore()

  const activeSignal = hoveredSignal || selectedSignal

  // Declared before the early return: hooks must run in the same order on every render, and
  // ESLint's rules-of-hooks correctly rejected this sitting further down.
  const handleKeyDown = useCallback(
    (event: React.KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        setSelectedSignal(null)
        setHoveredSignal(null)
      }
    },
    [setSelectedSignal, setHoveredSignal]
  )

  if (!activeSignal || !showSignals) {
    return null
  }

  const isPinned = !!selectedSignal && activeSignal.id === selectedSignal.id

  const distancePc = Math.sqrt(
    activeSignal.x * activeSignal.x +
      activeSignal.y * activeSignal.y +
      activeSignal.z * activeSignal.z
  )
  const distanceLy = parsecsToLightYears(distancePc)

  const timestamp = activeSignal.time ? new Date(activeSignal.time) : null
  const formattedTime = timestamp ? formatter.format(timestamp) : 'Unknown'

  let ageString: string | null = null
  if (timestamp) {
    const diffYears = (Date.now() - timestamp.getTime()) / (1000 * 60 * 60 * 24 * 365.25)
    if (Number.isFinite(diffYears)) {
      ageString = `${diffYears.toFixed(1)} years ago`
    }
  }

  const frequencyMHz = activeSignal.frequency ?? null
  const frequencyGhZ = frequencyMHz ? frequencyMHz / 1000 : null

  const typeLabel = activeSignal.type === 'transmit' ? 'Transmission' : 'Detection'

  const signalName =
    activeSignal.display_name || activeSignal.name || `Signal ${activeSignal.id}`

  const handleClose = () => {
    setSelectedSignal(null)
    setHoveredSignal(null)
  }

  return (
    // aria-live removed: this root is conditionally rendered, so the region and its content
    // appeared in the same tick and screen readers said nothing. SelectionAnnouncer holds a
    // persistent region instead, which is the arrangement that actually speaks.
    <div
      className="signal-panel"
      role="complementary"
      aria-label={`Signal information: ${signalName}`}
      onKeyDown={handleKeyDown}
    >
      <div className="signal-panel__header">
        <div>
          <h3>{signalName}</h3>
          <span className={`signal-panel__type signal-panel__type--${activeSignal.type}`}>
            {typeLabel}
          </span>
          {!isPinned && (
            <div className="signal-panel__hint" aria-live="polite">
              Hovering — click a wave to pin
            </div>
          )}
        </div>
        <button
          className="signal-panel__close"
          onClick={handleClose}
          aria-label="Close signal details"
        >
          &times;
        </button>
      </div>

      <div className="signal-panel__section">
        <h4>Observation</h4>
        <div className="signal-panel__row">
          <span>Timestamp</span>
          <strong>{formattedTime}</strong>
        </div>
        {ageString && (
          <div className="signal-panel__row signal-panel__row-subtext">
            <span>Age</span>
            <em>{ageString}</em>
          </div>
        )}
        {activeSignal.notes && (
          <p className="signal-panel__notes">{activeSignal.notes}</p>
        )}
      </div>

      <div className="signal-panel__section">
        <h4>Frequency</h4>
        <div className="signal-panel__row">
          <span>MHz</span>
          <strong>{frequencyMHz ? frequencyMHz.toFixed(3) : '—'}</strong>
        </div>
        <div className="signal-panel__row">
          <span>GHz</span>
          <strong>{frequencyGhZ ? frequencyGhZ.toFixed(6) : '—'}</strong>
        </div>
      </div>

      <div className="signal-panel__section">
        <h4>Coordinates</h4>
        <div className="signal-panel__row">
          <span>Galactic (pc)</span>
          <strong>
            {activeSignal.x.toFixed(1)}, {activeSignal.y.toFixed(1)}, {activeSignal.z.toFixed(1)}
          </strong>
        </div>
        <div className="signal-panel__row">
          <span>Distance</span>
          <strong>{distancePc.toFixed(2)} pc ({distanceLy.toFixed(2)} ly)</strong>
        </div>
      </div>
    </div>
  )
}
