/**
 * Tests for the coordinate-jump control.
 *
 * The parsing rules are covered in src/domain/coordinateInput.test.ts; these cover the form:
 * that it opens, converts, reports errors accessibly, and hands the camera parsecs.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import CoordinateJump from './CoordinateJump'
import { useAppStore } from '../state/store'

const openForm = () => fireEvent.click(screen.getByRole('button', { name: /galactic coordinates/i }))

const typeCoords = (x: string, y: string, z: string) => {
  fireEvent.change(screen.getByLabelText('X'), { target: { value: x } })
  fireEvent.change(screen.getByLabelText('Y'), { target: { value: y } })
  fireEvent.change(screen.getByLabelText('Z'), { target: { value: z } })
}

describe('CoordinateJump', () => {
  beforeEach(() => {
    useAppStore.setState({ unit: 'pc' })
  })

  it('is collapsed until asked for', () => {
    render(<CoordinateJump onJump={vi.fn()} />)
    expect(screen.queryByLabelText('X')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /galactic coordinates/i })).toHaveAttribute(
      'aria-expanded',
      'false'
    )
  })

  it('opens and exposes labelled fields', () => {
    render(<CoordinateJump onJump={vi.fn()} />)
    openForm()

    // Real labels, not placeholders — getByLabelText would fail otherwise.
    expect(screen.getByLabelText('X')).toBeInTheDocument()
    expect(screen.getByLabelText('Y')).toBeInTheDocument()
    expect(screen.getByLabelText('Z')).toBeInTheDocument()
  })

  it('passes parsecs straight through when the unit is parsecs', () => {
    const onJump = vi.fn()
    render(<CoordinateJump onJump={onJump} />)
    openForm()
    typeCoords('1.5', '-2', '3')
    fireEvent.click(screen.getByRole('button', { name: 'Go' }))

    expect(onJump).toHaveBeenCalledWith([1.5, -2, 3])
  })

  it('converts light-years to parsecs before jumping', () => {
    // The camera works in parsecs; the form shows whatever the app is displaying.
    useAppStore.setState({ unit: 'ly' })
    const onJump = vi.fn()
    render(<CoordinateJump onJump={onJump} />)
    openForm()
    typeCoords('3.26156', '0', '0')
    fireEvent.click(screen.getByRole('button', { name: 'Go' }))

    expect(onJump).toHaveBeenCalledTimes(1)
    expect(onJump.mock.calls[0][0][0]).toBeCloseTo(1, 5)
  })

  it('says which unit it expects', () => {
    useAppStore.setState({ unit: 'ly' })
    render(<CoordinateJump onJump={vi.fn()} />)
    openForm()
    expect(screen.getByText(/light-years/i)).toBeInTheDocument()
  })

  it('reports a bad value in an alert and does not jump', () => {
    const onJump = vi.fn()
    render(<CoordinateJump onJump={onJump} />)
    openForm()
    typeCoords('abc', '0', '0')
    fireEvent.click(screen.getByRole('button', { name: 'Go' }))

    expect(onJump).not.toHaveBeenCalled()
    // role=alert so a screen-reader user learns why nothing happened, rather than only
    // seeing a red border they cannot perceive.
    expect(screen.getByRole('alert')).toHaveTextContent(/not one/i)
    expect(screen.getByLabelText('X')).toHaveAttribute('aria-invalid', 'true')
  })

  it('refuses a destination outside the mapped region', () => {
    const onJump = vi.fn()
    render(<CoordinateJump onJump={onJump} />)
    openForm()
    typeCoords('99999', '0', '0')
    fireEvent.click(screen.getByRole('button', { name: 'Go' }))

    expect(onJump).not.toHaveBeenCalled()
    expect(screen.getByRole('alert')).toHaveTextContent(/outside the mapped region/i)
  })

  it('clears the error once the input changes', () => {
    render(<CoordinateJump onJump={vi.fn()} />)
    openForm()
    typeCoords('abc', '0', '0')
    fireEvent.click(screen.getByRole('button', { name: 'Go' }))
    expect(screen.getByRole('alert')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('X'), { target: { value: '1' } })
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('closes and resets after a successful jump', () => {
    render(<CoordinateJump onJump={vi.fn()} />)
    openForm()
    typeCoords('1', '2', '3')
    fireEvent.click(screen.getByRole('button', { name: 'Go' }))

    expect(screen.queryByLabelText('X')).not.toBeInTheDocument()
    openForm()
    expect(screen.getByLabelText('X')).toHaveValue('')
  })

  it('closes on Escape without jumping', () => {
    const onJump = vi.fn()
    render(<CoordinateJump onJump={onJump} />)
    openForm()
    typeCoords('1', '2', '3')
    fireEvent.keyDown(screen.getByLabelText('X'), { key: 'Escape' })

    expect(screen.queryByLabelText('X')).not.toBeInTheDocument()
    expect(onJump).not.toHaveBeenCalled()
  })
})
