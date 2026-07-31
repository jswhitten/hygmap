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
- **Added: stars can be found by their fictional name.** `/api/stars/search` queried
  `athyg` only and never joined `fic`, so no fictional name was reachable from the search
  box in either frontend — `?q=vulcan` returned `length:0` while the database correctly
  linked `fic.name='Vulcan'` to Keid / HD 26965, and `?q=keid` found that same star.
  "Vulcan" is the example in `.claude/CLAUDE.md`'s own purpose statement, and one of the
  project's two stated audiences could not search by the names it cares about.
  `search_stars` now takes `world_id` like `list_stars` and `get_star` already did.
- Fictional-name matching is **world-scoped**: a name matches only when its universe is the
  selected one, so `?q=vulcan&world_id=1` finds Keid while `world_id=2` and no `world_id`
  return nothing. The rejected alternative — matching across all universes and reporting
  which one hit — would let a star be found under a name the map is not currently showing
  for it, undoing the single-name-per-page-load invariant `DISPLAY-NAME-CANON` established.
- The query uses `EXISTS` for the predicate and a scalar subquery for the name rather than
  the `LEFT JOIN` the other two endpoints use. A join multiplies the row when a star has
  two names in one world, putting one physical star in the result list twice. Nothing does
  that today (191 `fic` rows) but `FICTIONAL-UNIVERSES` will, so the shape that cannot
  duplicate is the one written, with a regression test on `Wolf 359` — which is both an
  `athyg.proper` and a world-1 `fic.name`, so both predicates fire on one row.
  The scalar subquery orders the matched name first, so searching a fictional name shows
  that name rather than a sibling.
- The fictional predicate sits **inside** the existing unmappable-star guard, not beside
  it: a positionless or out-of-domain star stays excluded from search whether it matches on
  a real or a fictional name. Putting it outside would have reopened the
  `DATA-QUALITY-OUTLIERS` 503 through a new door. Two `fic` rows were added to the API test
  fixture specifically so this is proven rather than assumed.
- All seven catalog-ID queries carry the world-scoped fictional name too, so `?q=HD 26965`
  with a universe selected returns `display_name` "Vulcan" and search agrees with the map
  on one page load. Written out seven times rather than generated — the explicitness there
  is a recorded decision that keeps SQL injection structurally impossible.
- `search.php` now loads `Config` (it did not before) and passes the configured
  `fic_names` world through `ApiClient::searchStar`, whose new `$worldId` parameter
  defaults to 0 so every existing caller is unaffected.
- `searchStars` in the React client gained a `worldId` pass-through, defaulting to 0.
  **No user-visible change in the React app**: it has no universe selector yet, so it sends
  `world_id=0` and finds no fictional name. This is the plumbing for the separate
  "fictional-worlds layer in the React app" item, and the split is deliberate.
- 13 API tests, 3 PHP integration tests, 1 PHP unit test and 2 frontend tests added.
  One of them documents a behaviour worth knowing: a `world_id` can only *add* matches,
  never drop or duplicate one — and it adds them by substring exactly as proper-name search
  does, so with Star Trek selected `q=ori` also matches `Alpha Canis Majoris`.
- **Fixed: 17 nearby stars were stored at positions derived from a distance that had
  already been rejected.** `06_import_cns5.sql` and `07_import_gcns.sql` correct a star's
  `dist` when the supplement catalogue contradicts AT-HYG, but gated the coordinate
  recompute on `x_eq IS NULL` — true only for rows they *insert*. For a star that already
  existed and merely had its distance corrected, the gate never fired, leaving a correct
  `dist` and `absmag` beside coordinates computed from the bad Gaia parallax. GJ 125
  (CNS5 788): `dist` 17.19 pc, position 97,011 pc from Sol. 15 CNS5 stars and 2 GCNS
  stars, all of them in the solar neighbourhood this project exists to map.
  The gate now tests the invariant — does the stored position still have the same length
  as `dist`? — rather than whether the position is missing. That is idempotent, repairs
  drift from any source rather than only this one, and cannot be defeated by a future
  edit that introduces another way to change `dist`, which is how this arrived.
  `08_import_gaia_distances.sql` and `09_import_overrides.sql` were checked and never had
  the defect: both set `x_eq` in the same statement as `dist`.
- `check_distance_quality.py` gained two checks, for the galactic and equatorial triples
  separately, asserting `abs(|position| - dist) <= 0.001 * dist`. Both are needed: `x/y/z`
  are derived *from* `x_eq/y_eq/z_eq`, so verifying only the galactic triple would let a
  stale equatorial one survive for the next import step to rebuild the bad position from.
  Confirmed to fail on the pre-fix database (17 rows on each check) before the fix landed
  — the existing checks all passed on those rows, because only the position was wrong.
- 21 tests added in `db/scripts/test_coordinate_consistency.py`: the arithmetic, the fact
  that the equatorial→galactic matrix is norm-preserving (if it were not, the invariant
  would be false and the new checks would fire on healthy rows), and a regression guard
  that fails if either import goes back to gating on NULL alone or if its tolerance drifts
  from the checker's. Verified to fail against the old gate before being committed.
- **Security: API rate limiting was defeatable by anyone willing to set a header.**
  `docker-compose.prod.yml` set `FORWARDED_ALLOW_IPS=*`, and uvicorn 0.27's
  `ProxyHeadersMiddleware` reads the **first** `X-Forwarded-For` entry when it trusts
  everything. nginx's `$proxy_add_x_forwarded_for` *appends* the real peer, so the first
  entry is whatever the caller sent — rotating it per request bought an unlimited number
  of independent rate-limit buckets. Measured against a live stack under a 5/minute limit:
  12 requests with 12 forged values all returned 200, while an honest caller was cut off
  at 5. `FORWARDED_ALLOW_IPS` now names the bridge gateway specifically, which makes
  uvicorn scan right-to-left and take the entry nginx appended. Re-measured after the
  change: forged rotation now shares one bucket and is cut off at 5, while a genuinely
  different client still gets its own. This replaced, rather than reintroduced, the
  shared-bucket bug fixed on 2026-07-29.
- The Docker network subnet and gateway are now pinned in `docker-compose.yml`
  (`networks.default.ipam`). uvicorn matches `FORWARDED_ALLOW_IPS` by exact string, not
  CIDR, so the address cannot be expressed as a range — and Docker's assignment is not
  stable in practice. If the subnet drifted, the API would silently stop trusting nginx
  and fall back to counting the entire internet as one caller.
- **The API had three `Limiter` instances, and the one it was configured through was not
  the one enforcing.** `main.py` built a limiter and attached it to `app.state`, while
  `api/stars.py` and `api/signals.py` each constructed their own, which the route
  decorators actually used. So `main.py`'s `default_limits` applied to no route, and any
  central policy was silently inert. Found because the new internal-caller exemption was
  correct, imported, bound — and had no effect on a single request. There is now one
  shared limiter in `app/limiter.py`; `stars.py` and `signals.py` import it, and a test
  fails if any module constructs its own. (This was *not* about per-endpoint buckets:
  slowapi keys limits by client *and* endpoint by default, so each route has always had
  its own budget. Worth knowing that a caller's total capacity is `RATE_LIMIT` times the
  number of endpoints it touches.)
- First-party server-side callers on the Docker network no longer count against the
  per-IP limit. The classic PHP UI calls the API from one container address on behalf of
  every one of its users — measured 2026-07-30, 5,010 of 6,871 requests came from it — so
  a per-IP bucket throttled real visitors while protecting nothing; their traffic is
  already limited at the nginx hop. The bridge gateway is deliberately **not** exempt
  even though it is inside the network: it is the address every request collapses to when
  proxy trust is misconfigured, and exempting it would turn that mistake from "one shared
  bucket" into "no rate limiting at all", silently. Configurable via
  `RATE_LIMIT_EXEMPT_INTERNAL`.
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
