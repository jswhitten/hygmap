import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import LegacyLinkNotice from './LegacyLinkNotice'
import type { Star } from '../types/star'

const star = (id: number, display_name: string): Star => ({
  id,
  x: 1,
  y: 2,
  z: 3,
  absmag: 5,
  display_name,
})

const renderNotice = (props: Partial<React.ComponentProps<typeof LegacyLinkNotice>> = {}) => {
  const defaults: React.ComponentProps<typeof LegacyLinkNotice> = {
    legacyStar: star(7323, 'GJ 1'),
    currentStar: star(7301, 'TYC 1234-5-1'),
    onSelect: vi.fn(),
    onDismiss: vi.fn(),
  }
  const merged = { ...defaults, ...props }
  return { ...render(<LegacyLinkNotice {...merged} />), props: merged }
}

describe('LegacyLinkNotice', () => {
  it('names both readings of the id', () => {
    renderNotice()

    // The star the id means now...
    expect(screen.getByText('TYC 1234-5-1')).toBeInTheDocument()
    // ...and the one it meant before the renumbering, as the actionable control.
    expect(screen.getByRole('button', { name: 'GJ 1' })).toBeInTheDocument()
  })

  it('says so when the id names no current star', () => {
    renderNotice({ currentStar: null })

    expect(screen.getByText(/names no star in the current catalog/i)).toBeInTheDocument()
  })

  it('offers the legacy star to onSelect', () => {
    const { props } = renderNotice()

    fireEvent.click(screen.getByRole('button', { name: 'GJ 1' }))

    expect(props.onSelect).toHaveBeenCalledWith(props.legacyStar)
  })

  it('can be dismissed', () => {
    const { props } = renderNotice()

    fireEvent.click(screen.getByRole('button', { name: /dismiss old-link notice/i }))

    expect(props.onDismiss).toHaveBeenCalled()
  })

  it('announces itself politely rather than interrupting', () => {
    renderNotice()

    const notice = screen.getByRole('status', { name: /old link notice/i })
    expect(notice).toHaveAttribute('aria-live', 'polite')
  })
})
