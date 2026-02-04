/**
 * Type definitions for star data from the athyg table
 */

export interface Star {
  id: number
  proper?: string | null
  bayer?: string | null
  flam?: string | null
  con?: string | null
  spect?: string | null
  absmag?: number | null
  x: number
  y: number
  z: number
  display_name: string
}

export interface StarDetail extends Star {
  hyg?: number | null
  hip?: string | null
  hd?: string | null
  hr?: string | null
  gj?: string | null
  tyc?: string | null
  cns5?: string | null
  gaia?: string | null
  ra?: number | null
  dec?: number | null
  dist?: number | null
  mag?: number | null
}

export interface StarListResponse {
  result: string
  data: Star[]
  length: number
}

export interface StarDetailResponse {
  result: string
  data: StarDetail | null
}

export interface BoundingBox {
  xmin: number
  xmax: number
  ymin: number
  ymax: number
  zmin: number
  zmax: number
}

/**
 * Get display name for a star with priority:
 * 1. Proper name (e.g., "Vega")
 * 2. Bayer designation + constellation (e.g., "Alp Lyr")
 * 3. Flamsteed designation + constellation (e.g., "51 Peg")
 * 4. Catalog IDs (HIP, HD, GJ)
 * 5. Database ID
 */
export function getStarDisplayName(star: Star | StarDetail): string {
  // Use server-computed display_name if available
  if (star.display_name) {
    return star.display_name
  }

  // Fallback computation (shouldn't be needed with new API)
  if (star.proper) {
    return star.proper
  }
  if (star.bayer && star.con) {
    return `${star.bayer} ${star.con}`
  }
  if (star.flam && star.con) {
    return `${star.flam} ${star.con}`
  }

  // For StarDetail, check catalog IDs
  const detail = star as StarDetail
  if (detail.hip) {
    return `HIP ${detail.hip}`
  }
  if (detail.hd) {
    return `HD ${detail.hd}`
  }
  if (detail.hr) {
    return `HR ${detail.hr}`
  }
  if (detail.gj) {
    return `GJ ${detail.gj}`
  }
  if (detail.cns5) {
    return `CNS5 ${detail.cns5}`
  }
  if (detail.tyc) {
    return `TYC ${detail.tyc}`
  }
  if (detail.gaia) {
    return `Gaia ${detail.gaia}`
  }

  return `ID ${star.id}`
}
