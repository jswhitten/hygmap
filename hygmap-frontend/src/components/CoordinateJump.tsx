/**
 * CoordinateJump - move the view to a typed galactic coordinate.
 *
 * The star info panel shows a star's X/Y/Z, so the obvious next question is "how do I go
 * there?". This is that. Coordinates are entered in the same galactic frame the panel and
 * HUD display, in whichever unit is currently selected, so a value read off the panel can be
 * typed straight back in.
 *
 * Parsing and validation live in ../domain/coordinateInput so they can be tested without a
 * DOM; this component is the form around them.
 */

import { useCallback, useRef, useState } from 'react'
import { useAppStore } from '../state/store'
import { parseCoordinateInput } from '../domain/coordinateInput'
import './CoordinateJump.css'

interface CoordinateJumpProps {
  /** Move the view to these galactic coordinates, in parsecs. */
  onJump: (galacticPc: [number, number, number]) => void
}

const EMPTY = { x: '', y: '', z: '' }

export default function CoordinateJump({ onJump }: CoordinateJumpProps) {
  const unit = useAppStore((state) => state.unit)
  const [open, setOpen] = useState(false)
  const [fields, setFields] = useState(EMPTY)
  const [error, setError] = useState<string | null>(null)
  const [invalidField, setInvalidField] = useState<'x' | 'y' | 'z' | null>(null)
  const firstInputRef = useRef<HTMLInputElement>(null)

  const toggle = useCallback(() => {
    setOpen((wasOpen) => {
      if (wasOpen) {
        setError(null)
        setInvalidField(null)
      } else {
        // Focus the first field once it exists, so the control is usable from the keyboard
        // without hunting for it.
        requestAnimationFrame(() => firstInputRef.current?.focus())
      }
      return !wasOpen
    })
  }, [])

  const handleChange = useCallback((axis: 'x' | 'y' | 'z', value: string) => {
    setFields((current) => ({ ...current, [axis]: value }))
    setError(null)
    setInvalidField(null)
  }, [])

  const handleSubmit = useCallback(
    (event: React.FormEvent) => {
      event.preventDefault()

      const result = parseCoordinateInput(fields, unit)
      if (!result.ok) {
        setError(result.error)
        setInvalidField(result.field ?? null)
        return
      }

      onJump(result.value.galacticPc)
      setError(null)
      setInvalidField(null)
      setOpen(false)
      setFields(EMPTY)
    },
    [fields, unit, onJump]
  )

  const handleKeyDown = useCallback(
    (event: React.KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        setOpen(false)
        setError(null)
        setInvalidField(null)
      }
    },
    []
  )

  return (
    <div className="coordinate-jump" onKeyDown={handleKeyDown}>
      <button
        type="button"
        className="toolbar-button"
        onClick={toggle}
        aria-expanded={open}
        aria-controls="coordinate-jump-form"
        title="Go to coordinates"
        aria-label="Go to galactic coordinates"
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
          <circle cx="12" cy="12" r="3" />
          <path d="M12 2v4M12 18v4M2 12h4M18 12h4" />
        </svg>
      </button>

      {open && (
        <form
          id="coordinate-jump-form"
          className="coordinate-jump-form"
          onSubmit={handleSubmit}
          noValidate
        >
          <p className="coordinate-jump-hint">
            Galactic coordinates in {unit === 'ly' ? 'light-years' : 'parsecs'}, Sol at 0,&nbsp;0,&nbsp;0
          </p>

          <div className="coordinate-jump-fields">
            {(['x', 'y', 'z'] as const).map((axis, index) => (
              <span className="coordinate-jump-field" key={axis}>
                <label htmlFor={`coord-${axis}`}>{axis.toUpperCase()}</label>
                <input
                  id={`coord-${axis}`}
                  ref={index === 0 ? firstInputRef : undefined}
                  type="text"
                  inputMode="decimal"
                  autoComplete="off"
                  value={fields[axis]}
                  placeholder="0"
                  aria-invalid={invalidField === axis}
                  aria-describedby={error ? 'coordinate-jump-error' : undefined}
                  onChange={(event) => handleChange(axis, event.target.value)}
                />
              </span>
            ))}
          </div>

          {/* role=alert so the message is announced, not just shown. A keyboard user who
              cannot see the red border still needs to know why nothing happened. */}
          {error && (
            <p className="coordinate-jump-error" id="coordinate-jump-error" role="alert">
              {error}
            </p>
          )}

          <button type="submit" className="coordinate-jump-submit">
            Go
          </button>
        </form>
      )}
    </div>
  )
}
