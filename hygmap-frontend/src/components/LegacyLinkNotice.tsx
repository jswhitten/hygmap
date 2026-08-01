import { getStarDisplayName } from '../types/star'
import type { Star } from '../types/star'
import { CURRENT_CATALOG_VERSION } from '../utils/urlState'

interface LegacyLinkNoticeProps {
  /** The star this link's id named before the AT-HYG 4 renumbering. */
  legacyStar: Star
  /** The star the id names now, if any. */
  currentStar: Star | null
  onSelect: (star: Star) => void
  onDismiss: () => void
}

/**
 * Tells the reader that the link they followed is ambiguous, and offers both readings.
 *
 * AT-HYG 4 renumbered every star, so a link saved before that migration points at a
 * different object — and it fails *silently*, because 99.99% of v3 ids are also a valid,
 * different v4 id. Nothing 404s; the wrong star simply loads.
 *
 * This never picks for the reader. Guessing is not safer in either direction: treating
 * every unmarked id as legacy would break every link saved since the migration in exactly
 * the same way. So both candidates are named and the choice is theirs.
 */
export default function LegacyLinkNotice({
  legacyStar,
  currentStar,
  onSelect,
  onDismiss,
}: LegacyLinkNoticeProps) {
  return (
    /* aria-label because SelectionAnnouncer is also a role="status" live region, and the
       two are otherwise indistinguishable to anything navigating by role — a screen-reader
       user included. */
    <div
      role="status"
      aria-live="polite"
      aria-label="Old link notice"
      style={{
        position: 'absolute',
        top: '1rem',
        left: '50%',
        transform: 'translateX(-50%)',
        zIndex: 20,
        maxWidth: 'min(46rem, calc(100vw - 2rem))',
        display: 'flex',
        alignItems: 'baseline',
        gap: '0.75rem',
        padding: '0.6rem 0.9rem',
        borderRadius: '4px',
        borderLeft: '3px solid #4a7fc1',
        background: '#0e1b2c',
        color: '#dce8f7',
        fontSize: '0.875rem',
        lineHeight: 1.45,
      }}
    >
      <span>
        <strong>This may be an old link.</strong>{' '}
        Star numbering changed when the catalog was updated to AT-HYG&nbsp;{CURRENT_CATALOG_VERSION}.
        {currentStar
          ? <> You are seeing <strong>{getStarDisplayName(currentStar)}</strong>.</>
          : <> That id names no star in the current catalog.</>}
        {' '}Before the update, this link meant{' '}
        <button
          type="button"
          onClick={() => onSelect(legacyStar)}
          style={{
            background: 'none',
            border: 'none',
            padding: 0,
            font: 'inherit',
            color: '#8fc0ff',
            textDecoration: 'underline',
            cursor: 'pointer',
          }}
        >
          {getStarDisplayName(legacyStar)}
        </button>
        .
      </span>
      <button
        type="button"
        onClick={onDismiss}
        aria-label="Dismiss old-link notice"
        style={{
          marginLeft: 'auto',
          background: 'none',
          border: 'none',
          color: '#8fa8c7',
          font: 'inherit',
          cursor: 'pointer',
          padding: '0 0.25rem',
        }}
      >
        ✕
      </button>
    </div>
  )
}
