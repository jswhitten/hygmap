/**
 * URL State Management
 *
 * Encodes/decodes app state to/from URL parameters for sharing.
 * Supports: camera position, look-at target, selected star ID, view settings
 */

import type { ViewMode } from '../domain/viewMode'
import { DEFAULT_VIEW_MODE, normalizeViewMode } from '../domain/viewMode'

interface ShareableState {
  // Camera position (scene coords)
  cx?: number
  cy?: number
  cz?: number
  // Look-at target (scene coords)
  tx?: number
  ty?: number
  tz?: number
  // Selected star ID
  star?: number
  // View settings
  unit?: 'pc' | 'ly'
  view?: ViewMode
  coords?: 'cartesian' | 'polar'
}

/**
 * Encode state to URL search params
 */
export function encodeStateToURL(state: ShareableState): string {
  const params = new URLSearchParams()

  // Camera position (round to 2 decimal places)
  if (state.cx !== undefined) params.set('cx', state.cx.toFixed(2))
  if (state.cy !== undefined) params.set('cy', state.cy.toFixed(2))
  if (state.cz !== undefined) params.set('cz', state.cz.toFixed(2))

  // Look-at target
  if (state.tx !== undefined) params.set('tx', state.tx.toFixed(2))
  if (state.ty !== undefined) params.set('ty', state.ty.toFixed(2))
  if (state.tz !== undefined) params.set('tz', state.tz.toFixed(2))

  // Selected star
  if (state.star !== undefined) params.set('star', state.star.toString())

  // View settings (only include non-default values)
  if (state.unit && state.unit !== 'pc') params.set('unit', state.unit)
  if (state.view && state.view !== DEFAULT_VIEW_MODE) params.set('view', state.view)
  if (state.coords && state.coords !== 'cartesian') params.set('coords', state.coords)

  return params.toString()
}

/**
 * Decode state from URL search params
 */
export function decodeStateFromURL(search: string): ShareableState {
  const params = new URLSearchParams(search)
  const state: ShareableState = {}

  // Camera position
  const cx = params.get('cx')
  const cy = params.get('cy')
  const cz = params.get('cz')
  if (cx) state.cx = parseFloat(cx)
  if (cy) state.cy = parseFloat(cy)
  if (cz) state.cz = parseFloat(cz)

  // Look-at target
  const tx = params.get('tx')
  const ty = params.get('ty')
  const tz = params.get('tz')
  if (tx) state.tx = parseFloat(tx)
  if (ty) state.ty = parseFloat(ty)
  if (tz) state.tz = parseFloat(tz)

  // Selected star
  const star = params.get('star')
  if (star) state.star = parseInt(star, 10)

  // View settings
  const unit = params.get('unit')
  if (unit === 'ly' || unit === 'pc') state.unit = unit

  const view = normalizeViewMode(params.get('view'))
  if (view) state.view = view

  const coords = params.get('coords')
  if (coords === 'cartesian' || coords === 'polar') state.coords = coords

  return state
}

/**
 * Generate a shareable URL for the current view
 */
export function generateShareURL(state: ShareableState): string {
  const encoded = encodeStateToURL(state)
  const baseURL = window.location.origin + window.location.pathname
  return encoded ? `${baseURL}?${encoded}` : baseURL
}

/**
 * Copy text to clipboard
 */
export async function copyToClipboard(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text)
    return true
  } catch {
    // Fallback for older browsers
    const textarea = document.createElement('textarea')
    textarea.value = text
    textarea.style.position = 'fixed'
    textarea.style.opacity = '0'
    document.body.appendChild(textarea)
    textarea.select()
    try {
      document.execCommand('copy')
      return true
    } catch {
      return false
    } finally {
      document.body.removeChild(textarea)
    }
  }
}
