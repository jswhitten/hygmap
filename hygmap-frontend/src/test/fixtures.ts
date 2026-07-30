/**
 * Test fixtures mirroring the REAL API contract.
 *
 * Why this exists
 * ---------------
 * The frontend tests mock `fetch`, so whatever shape the mock returns is the shape the
 * tests believe in. Before this file, `stars.test.ts` mocked a star with five fields and
 * asserted the client returned it unchanged — a test that proves the mock equals itself
 * while the real `StarBase` carries twenty. An audit on 2026-07-29 found the API could
 * change shape with every suite staying green; this is half the fix (the PHP half lives
 * in hygmap-php/tests/Integration/ApiContractTest.php).
 *
 * The payloads below are copied from real responses, not invented. If the API changes,
 * update them here and the tests that depend on the shape will move with it.
 *
 * Captured 2026-07-29 from:
 *   GET /api/stars/?xmin=-5&xmax=5&ymin=-5&ymax=5&zmin=-5&zmax=5&limit=1&order=dist+asc
 *   GET /api/stars/7301
 */

/**
 * Every key the API returns on a list row (StarBase).
 *
 * The TypeScript `Star` interface deliberately declares only the subset the UI uses, so
 * the compiler will not notice a field disappearing. This array is the check that the
 * compiler cannot give us.
 */
export const STAR_BASE_KEYS = [
  'id',
  'proper',
  'bayer',
  'flam',
  'con',
  'spect',
  'absmag',
  'mag',
  'dist',
  'x',
  'y',
  'z',
  'hip',
  'hd',
  'hr',
  'gj',
  'cns5',
  'gaia',
  'tyc',
  'name',
  'display_name',
] as const

/** A real list row: Sol, with the nulls the catalog actually contains. */
export const solListRow = {
  id: 1,
  proper: 'Sol',
  bayer: null,
  flam: null,
  con: null,
  spect: 'G2 V',
  absmag: 4.830999851226807,
  mag: -26.739999771118164,
  dist: 4.850000095757423e-6,
  x: -2.667499927611061e-7,
  y: 2.3959000827744603e-6,
  z: -4.20834521719371e-6,
  hip: null,
  hd: null,
  hr: null,
  gj: null,
  cns5: null,
  gaia: null,
  tyc: null,
  name: '',
  display_name: 'Sol',
}

/**
 * A real list row with catalog IDs populated and no proper name.
 *
 * This is star 7301, the one that exposed the display-name divergence: it carries both
 * `gj` and `hip`, so the two implementations disagree about what to call it. Keep it in
 * the fixtures — a star with exactly one catalog ID cannot reproduce that class of bug.
 */
export const catalogOnlyListRow = {
  id: 7301,
  proper: null,
  bayer: null,
  flam: null,
  con: 'Scl',
  spect: 'M2V',
  absmag: 10.369999885559082,
  mag: 8.5600004196167,
  dist: 4.3460001945495605,
  x: 1.0155913829803467,
  y: -0.30026617646217346,
  z: -4.215703010559082,
  hip: '439',
  hd: '225213',
  hr: null,
  gj: '1',
  cns5: '19',
  gaia: '2306965202564744064',
  tyc: '6995-1264-1',
  name: '',
  display_name: 'HIP 439',
}

/** A realistic list response wrapping the rows above. */
export function starListResponse(data = [solListRow, catalogOnlyListRow]) {
  return { result: 'success', data, length: data.length }
}
