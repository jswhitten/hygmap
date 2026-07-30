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
