import './PrintableOverlay.css'

interface PrintableOverlayProps {
  onExit: () => void
}

export default function PrintableOverlay({ onExit }: PrintableOverlayProps) {
  return (
    <div className="printable-overlay" role="status" aria-live="polite">
      <div className="printable-overlay__content">
        <div>
          <p className="printable-overlay__title">Printable view enabled</p>
          <p className="printable-overlay__body">
            Background and stars are optimized for printing. Use your browser's print dialog or take a
            screenshot to capture this layout.
          </p>
        </div>
        <button
          type="button"
          className="printable-overlay__button"
          onClick={onExit}
        >
          Exit printable view
        </button>
      </div>
    </div>
  )
}
