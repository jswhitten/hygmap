/**
 * Accessibility behaviour of the star info panel.
 *
 * The panel had no landmark role, no accessible name, a close button whose only content was a
 * multiplication sign, and no way to dismiss it from the keyboard.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import StarInfoPanel from './StarInfoPanel'
import { useAppStore } from '../state/store'
import type { Star } from '../types/star'

vi.mock('../api/stars', () => ({
  fetchStarById: vi.fn(() => new Promise(() => {})), // never resolves; we only test the shell
}))

const vega: Star = {
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
}

describe('StarInfoPanel accessibility', () => {
  beforeEach(() => {
    useAppStore.setState({ selectedStar: vega, unit: 'pc' })
  })

  it('is an addressable landmark with a name', () => {
    // So a screen-reader user can jump straight to it rather than tabbing the whole toolbar.
    render(<StarInfoPanel />)
    expect(screen.getByRole('complementary', { name: /star information: vega/i })).toBeInTheDocument()
  })

  it('gives the close button a real accessible name', () => {
    // Its visible content is "×", which assistive tech reads as "times" or skips.
    render(<StarInfoPanel />)
    expect(screen.getByRole('button', { name: /close star information for vega/i })).toBeInTheDocument()
  })

  it('closes on Escape', () => {
    render(<StarInfoPanel />)
    fireEvent.keyDown(screen.getByRole('complementary'), { key: 'Escape' })
    expect(useAppStore.getState().selectedStar).toBeNull()
  })

  it('closes when the close button is activated', () => {
    render(<StarInfoPanel />)
    fireEvent.click(screen.getByRole('button', { name: /close star information/i }))
    expect(useAppStore.getState().selectedStar).toBeNull()
  })

  it('renders nothing when no star is selected', () => {
    useAppStore.setState({ selectedStar: null })
    render(<StarInfoPanel />)
    expect(screen.queryByRole('complementary')).not.toBeInTheDocument()
  })
})
