/**
 * Tests for the selection announcer.
 *
 * The bug being fixed: selecting a star moved no focus and said nothing, so a keyboard or
 * screen-reader user pressed Enter on a search result and had no way to know the info panel
 * had opened. These tests pin the announcement, and the structural property that makes it
 * work — the live region exists before the content does.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import SelectionAnnouncer from './SelectionAnnouncer'
import { useAppStore } from '../state/store'
import type { Star } from '../types/star'

const star = (overrides: Partial<Star> = {}): Star => ({
  id: 1,
  proper: 'Vega',
  bayer: null,
  flam: null,
  con: 'Lyr',
  spect: 'A0V',
  absmag: 0.58,
  x: 2.13,
  y: 6.7,
  z: 2.53,
  display_name: 'Vega',
  ...overrides,
})

describe('SelectionAnnouncer', () => {
  beforeEach(() => {
    useAppStore.setState({ selectedStar: null, selectedSignal: null, unit: 'pc' })
  })

  it('renders a polite live region even with nothing selected', () => {
    // This is the whole point of the component. A live region that appears at the same time
    // as its content is routinely not announced, so it has to exist up front and empty.
    render(<SelectionAnnouncer />)

    const region = screen.getByTestId('selection-announcer')
    expect(region).toBeInTheDocument()
    expect(region).toHaveAttribute('aria-live', 'polite')
    expect(region).toHaveAttribute('role', 'status')
    expect(region).toHaveTextContent('')
  })

  it('is hidden visually but present for assistive technology', () => {
    render(<SelectionAnnouncer />)
    const region = screen.getByTestId('selection-announcer')

    // display:none / visibility:hidden would remove it from the accessibility tree, so the
    // clip technique is load-bearing rather than cosmetic.
    expect(region).toHaveClass('sr-only')
  })

  it('announces a star selection with its name and distance', () => {
    const { rerender } = render(<SelectionAnnouncer />)
    useAppStore.setState({ selectedStar: star() })
    rerender(<SelectionAnnouncer />)

    const text = screen.getByTestId('selection-announcer').textContent ?? ''
    expect(text).toContain('Selected Vega')
    expect(text).toContain('A0V')
    expect(text).toContain('parsecs from Sol')
    // It must also say where to find the detail, otherwise "selected" leaves the user
    // wondering what changed.
    expect(text).toContain('star information panel')
  })

  it('announces distance in the selected unit', () => {
    useAppStore.setState({ unit: 'ly' })
    const { rerender } = render(<SelectionAnnouncer />)
    useAppStore.setState({ selectedStar: star() })
    rerender(<SelectionAnnouncer />)

    expect(screen.getByTestId('selection-announcer')).toHaveTextContent('light-years from Sol')
  })

  it('omits spectral type when the star has none', () => {
    const { rerender } = render(<SelectionAnnouncer />)
    useAppStore.setState({ selectedStar: star({ spect: null }) })
    rerender(<SelectionAnnouncer />)

    const text = screen.getByTestId('selection-announcer').textContent ?? ''
    expect(text).toContain('Selected Vega')
    expect(text).not.toContain('spectral type')
  })

  it('announces when the selection is cleared', () => {
    const { rerender } = render(<SelectionAnnouncer />)
    useAppStore.setState({ selectedStar: star() })
    rerender(<SelectionAnnouncer />)
    useAppStore.setState({ selectedStar: null })
    rerender(<SelectionAnnouncer />)

    // Otherwise dismissing the panel is silent and Escape feels like it did nothing.
    expect(screen.getByTestId('selection-announcer')).toHaveTextContent('Selection cleared')
  })

  it('says nothing on first render when nothing has ever been selected', () => {
    render(<SelectionAnnouncer />)
    expect(screen.getByTestId('selection-announcer')).toHaveTextContent('')
  })

  it('announces a signal selection', () => {
    const { rerender } = render(<SelectionAnnouncer />)
    useAppStore.setState({
      selectedSignal: {
        id: 7,
        name: 'Arecibo Message',
        display_name: 'Arecibo Message',
        type: 'transmit',
        x: 1,
        y: 2,
        z: 3,
      } as never,
    })
    rerender(<SelectionAnnouncer />)

    const text = screen.getByTestId('selection-announcer').textContent ?? ''
    expect(text).toContain('Arecibo Message')
    expect(text).toContain('signal information panel')
  })
})
