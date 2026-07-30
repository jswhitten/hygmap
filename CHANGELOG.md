# Changelog

Technical changelog, for developers. Covers everything: internals, data pipeline, API
contracts, tooling, test coverage.

**There is a second changelog.** `hygmap-php/src/changelog.html` is served to visitors at
`/changelog.html` and covers only what a *user* would notice — new features, changed
behaviour, visible fixes — in plain language, newest first.

Decided 2026-07-30 to keep both, with that division of labour. They drifted apart once
already: this file went unmaintained for six months while the HTML one stayed current. So:

> **A user-visible change updates BOTH. An internal change updates only this file.**

If you are unsure whether a change is user-visible, ask whether someone using the map would
notice without reading the source. If yes, it belongs in both.

## Unreleased
- Fixed: with a fiction naming layer on, the classic UI's star info panel and star table
  passed a hardcoded `0` for `fic_names` and so led with the catalog name, while the map
  overlay and the API led with the fictional one. A single page load could show four
  different names for one star (id 94902 with Star Trek on: "37 Cet (Alpha)" in the panel,
  "37 Cet" in the table, "Alpha" on the map, `"Alpha"` from the API). All four now agree
  on the canonical rule — a fictional name wins outright. The panel keeps the catalog name
  in parentheses as context, and the Wikipedia link still points at the catalog name
  rather than the fictional one.
- `db/scripts`' 140 tests (catalog matching for CNS5/GCNS, constellation computation) now
  run in `make test`, `make ci` and CI as a `scripts-test` job. They passed and were wired
  into nothing, while ROADMAP credited them with closing `CATALOG-ID-INTEGRITY`.
- GitHub Actions now runs `make typecheck-frontend`. The target was added to close a
  documented type-safety gap but only ever ran via local `make ci`, so the incident it
  exists to prevent could still reach main through the CI that gates merges.
- ESLint now rejects an inlined `3.26156` outside `src/domain/coordinates.ts` and the tests
  that assert its value. The parsec/light-year factor was centralised into `PC_TO_LY` and
  then reintroduced by the same commit that centralised it; two audits caught it, no tool did.
- Documented `/api/stars/proper-names`, `/api/stars/worlds` and `/api/stars/fictional-names`
  in `docs/api.md` — three real, rate-limited routes that were entirely absent.
- Fixed: `docs/api.md` stated the rate limit as 100 requests/minute; the actual default in
  `hygmap-api/app/config.py` is 1000/minute.
- Filled in constellations for 281,300 stars that arrived from the CNS5 and GCNS imports with
  positions but no `con`, making them unfindable by constellation search. Computed offline from
  RA/Dec via astropy, with an independent IAU-1976 implementation kept as a cross-check
  (99.993% agreement; divergence only within ~1 arcsec of a boundary). Sol is excluded, being
  the coordinate origin rather than a position on the sky.
- Added direct coordinate entry to the React app: a toolbar control that moves the view to a
  typed galactic X/Y/Z, in the currently selected unit, validated against the API's
  coordinate domain. Parsing lives in `src/domain/coordinateInput.ts` and is unit-tested
  separately from the form.
- `make ci` now type-checks the frontend. `tsc` was only wired into `npm run build`, which no
  CI step invoked, so TypeScript errors could not fail the build — a duplicate interface
  member had been sitting in `src/types/star.ts` through several green runs.
- **Star names are now consistent between the two interfaces.** The display-name rule
  existed in four places and they disagreed: a star with both a Gliese and a Hipparcos
  number was called "GJ 1" by the classic UI and "HIP 439" by the API and React app.
  Gliese-Jahreiss now leads the catalog order everywhere, since HYGMap maps the solar
  neighbourhood. **API and React consumers will see some names change.**
- Fixed: a star whose only designation was a Yale Bright Star number rendered with an
  empty name in the classic UI (there was no HR branch at all).
- Fixed: a star with a Bayer letter but no constellation rendered as e.g. "Alp " with a
  trailing space instead of falling back to a catalog ID.
- Fixed: a star with only an HR designation was named "HR 2491" in list responses and
  "ID 7" in detail responses — the API had two hand-written copies of the chain.
- Fixed: the classic map's hover tooltip labelled absolute magnitude as a bare "Mag:",
  which reads as apparent magnitude. It now says "Abs Mag:".
- Star names never render empty; a star matching nothing falls back to its spectral type
  and then to its database id.
- The canonical name table now lives in `tests/fixtures/display-names.json` and is
  asserted by the PHP, API, and frontend suites, so the four implementations cannot
  drift apart again unnoticed.
- Documented API rate limiting scope and 429 error shape in docs/api.md.
- Swapped React components to Zustand selector hooks (StarField, Settings, Toolbar) to reduce re-renders.
- Added keyboard navigation guidance to the HUD controls panel.
- Wrapped heavy render layers (InstancedStars, SignalsLayer) in error boundaries.
- Extracted camera offset and star disk segments into named constants to replace magic numbers.

## 2026-01-24
- Escaped tooltip fields to close XSS surface in map-interactive.js.
- Added same-origin validation for configure.php redirects.
- Gated production console logging and fixed StarField response length logging bug.
- Added *.swp to .gitignore.
