/**
 * SelectionAnnouncer - tells assistive technology what just got selected.
 *
 * Why this is a separate, always-mounted component
 * ------------------------------------------------
 * A live region only gets announced if it is already in the accessibility tree when its
 * contents change. Both info panels return null when nothing is selected, so putting
 * `aria-live` on a panel root — which SignalInfoPanel did — means the region and its text
 * appear in the same tick, and screen readers routinely say nothing at all.
 *
 * This renders an empty region unconditionally and writes into it afterwards, which is the
 * arrangement that actually speaks.
 *
 * Why announce rather than move focus
 * -----------------------------------
 * Selecting a star from the search results used to move no focus and say nothing, so a
 * keyboard user pressed Enter and had no way to know the info panel had opened somewhere else
 * on screen. Moving focus into the panel would be the loudest signal, but it takes focus away
 * from the search box, and searching again is the most likely next action — so the trade is
 * hostile. Instead: announce what was selected, and keep the panel reachable by Tab (its close
 * button and links are natively focusable) and dismissible with Escape.
 */

import { useEffect, useRef, useState } from 'react'
import { useAppStore } from '../state/store'
import { parsecsToLightYears } from '../domain/coordinates'
import { hasPosition } from '../domain/star'

export default function SelectionAnnouncer() {
  const selectedStar = useAppStore((state) => state.selectedStar)
  const selectedSignal = useAppStore((state) => state.selectedSignal)
  const unit = useAppStore((state) => state.unit)
  const [message, setMessage] = useState('')

  // Track what was last announced so re-renders (a unit change, a hover) do not repeat it.
  const lastAnnounced = useRef<string | null>(null)

  useEffect(() => {
    let next: string | null = null

    if (selectedStar) {
      const spectral = selectedStar.spect ? `, spectral type ${selectedStar.spect}` : ''

      // A star with no parallax has no distance to announce. Saying "0.0 light-years from
      // Sol" — which is what the old arithmetic produced once x/y/z could be null — would
      // be a confident false statement to the one user who cannot see the screen to
      // check it. State the absence instead.
      let distancePhrase: string
      if (hasPosition(selectedStar)) {
        const distPc = Math.sqrt(
          selectedStar.x * selectedStar.x +
            selectedStar.y * selectedStar.y +
            selectedStar.z * selectedStar.z
        )
        const distance = unit === 'ly' ? parsecsToLightYears(distPc) : distPc
        const unitLabel = unit === 'ly' ? 'light-years' : 'parsecs'
        distancePhrase = `${distance.toFixed(1)} ${unitLabel} from Sol`
      } else {
        distancePhrase = 'distance unknown, so it cannot be shown on the map'
      }

      next = `key:star:${selectedStar.id}`
      if (lastAnnounced.current !== next) {
        setMessage(
          `Selected ${selectedStar.display_name}${spectral}, ` +
            `${distancePhrase}. ` +
            `Details are in the star information panel.`
        )
      }
    } else if (selectedSignal) {
      const name =
        selectedSignal.display_name || selectedSignal.name || `Signal ${selectedSignal.id}`
      next = `key:signal:${selectedSignal.id}`
      if (lastAnnounced.current !== next) {
        setMessage(`Selected signal ${name}. Details are in the signal information panel.`)
      }
    } else if (lastAnnounced.current !== null) {
      // Deselection is also worth saying: otherwise closing the panel is silent and the user
      // cannot tell whether Escape did anything.
      setMessage('Selection cleared.')
    }

    lastAnnounced.current = next
  }, [selectedStar, selectedSignal, unit])

  return (
    <div
      className="sr-only"
      role="status"
      aria-live="polite"
      aria-atomic="true"
      data-testid="selection-announcer"
    >
      {message}
    </div>
  )
}
