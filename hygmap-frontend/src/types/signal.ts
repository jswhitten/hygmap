/**
 * Type definitions for SETI signal overlay
 */

export type SignalType = 'transmit' | 'receive'

export interface Signal {
  id: number
  name?: string | null
  type: SignalType
  time?: string | null
  ra?: number | null
  dec?: number | null
  frequency?: number | null
  notes?: string | null
  x: number
  y: number
  z: number
  last_updated?: string | null
  display_name: string
}

export interface SignalListResponse {
  result: string
  data: Signal[]
  length: number
}

export function getSignalDisplayName(signal: Signal): string {
  return signal.display_name || signal.name || `Signal ${signal.id}`
}

export function getSignalAgeYears(signal: Signal): number | null {
  if (!signal.time) return null
  const timestamp = new Date(signal.time).getTime()
  if (Number.isNaN(timestamp)) return null
  const diffMs = Date.now() - timestamp
  return diffMs / (1000 * 60 * 60 * 24 * 365.25)
}
