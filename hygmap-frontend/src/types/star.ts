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
  /**
   * Fictional name, populated by the API only when a world_id was requested.
   * The React UI has no world selector yet, so this is normally absent — but the
   * display-name rule accounts for it so all three stacks share one chain.
   */
  name?: string | null
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
 * Get the display name for a star.
 *
 * Prefers the server-computed display_name; falls back to the canonical chain below.
 * See tests/fixtures/display-names.json for the authoritative table.
 */
export function getStarDisplayName(star: Star | StarDetail): string {
  // The server computes this too, and its value wins — see StarBase.display_name in
  // hygmap-api/app/schemas/star.py. This local chain is the fallback for rows that
  // arrive without one, and it must stay in step with the server and with PHP's
  // StarFormatter. All three are asserted against tests/fixtures/display-names.json;
  // change that table first.
  //
  // Canonical order (decided 2026-07-29): fictional > proper > bayer+con > flam+con >
  // GJ > HD > HIP > HR > CNS5 > TYC > Gaia > spect > ID. GJ leads because this is a map
  // of the solar neighbourhood; this chain used to lead with HIP.
  if (star.display_name) {
    return star.display_name
  }

  if (star.name) {
    return star.name
  }
  if (star.proper) {
    return star.proper
  }
  // Source data carries padding on bayer/flam, and the constellation is required —
  // a bare Greek letter is not a designation.
  if (star.bayer && star.con) {
    return `${star.bayer.trim()} ${star.con.trim()}`
  }
  if (star.flam && star.con) {
    return `${star.flam.trim()} ${star.con.trim()}`
  }

  const detail = star as StarDetail
  if (detail.gj) {
    return `GJ ${detail.gj}`
  }
  if (detail.hd) {
    return `HD ${detail.hd}`
  }
  if (detail.hip) {
    return `HIP ${detail.hip}`
  }
  if (detail.hr) {
    return `HR ${detail.hr}`
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
  if (star.spect) {
    return star.spect
  }

  return `ID ${star.id}`
}

