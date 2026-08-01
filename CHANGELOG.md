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
- **A fictional name whose universe is switched off now says so.** Searching `Vulcan` with
  no universe selected produced a bare "No match", indistinguishable from a misspelling —
  and "Vulcan" is the example in this project's own purpose statement, so it was the exact
  first interaction the project describes itself by.
  `search.php`'s no-match branch now asks whether the term matches a `fic` name in some
  *other* world, and if it does, names that universe and links to `configure.php`. New
  `ApiClient::findFictionalNameInOtherWorlds()`; one `/worlds` call plus one
  `/fictional-names` call per other world, on the no-match path only, against a 191-row
  table. A failure there is swallowed and logged — the search already succeeded, and the
  hint must never turn a plain no-match into an error page.
  Matching mirrors the search endpoint (case-insensitive substring, prefix-anchored below
  3 characters) so the hint cannot promise a result the real search would then miss.

- **The old-link notice no longer makes false claims after a new selection.**
  `App.tsx` bound `LegacyLinkNotice`'s `currentStar` to the live `selectedStar`, and
  nothing in the search or canvas selection paths clears the notice — so picking any
  unrelated star left it asserting "You are seeing *&lt;that star&gt;*" about an id that
  never named it. Reported by `audit-frontend` 2026-07-31-2014, and the reason that
  auditor regressed B+ → B.
  Both stars are now frozen when the notice is built: the claim is about the URL's id, so
  it cannot move. The URL star is resolved once and shared by the selection path and the
  notice, rather than fetched twice.
  Pinned by a regression test in `App.test.tsx` that was **mutation-checked** — restoring
  the old binding makes it fail, which the ORDER BY tiebreaker episode is the standing
  reason to verify rather than assume. `LegacyLinkNotice` also gained
  `aria-label="Old link notice"`, because `SelectionAnnouncer` is also a `role="status"`
  live region and the two were indistinguishable by role.

- **The React "Center" button is disabled, with a reason, for a star with no position.**
  It was left enabled while `handleCenter` returned early, so a mouse user saw the map not
  move and a keyboard or screen-reader user got nothing at all. Now disabled with both a
  `title` and an `aria-label` saying why. Positionless results in the React search dropdown
  also carry a **not on map** badge (10.1:1 contrast, 6.8:1 on hover) and say so in their
  accessible name — `search.php` explained before the click and this dropdown did not, so
  the same star behaved differently in the two UIs. Both covered by new Vitest tests,
  including the Sol-at-the-origin case a falsy coordinate check would get wrong.

- **`search.php` has tests for the first time.** No test executed the file at all; the CI
  smoke test only searched "Sol", which takes the redirect branch, so every other path was
  unexercised. This code had been hand-debugged three times — a contrast fix, a
  `lang`/charset fix, and the shared page-shell refactor — with no regression test landing
  on any of those occasions (`audit-tests`, major, 2026-07-31-2014).
  New `tests/Integration/SearchTest.php` drives it over HTTP (it reads `$_GET`, calls
  `session_start()` and `exit`s on two of four paths, so including it would take the
  PHPUnit process with it): the positionless branch, its link without `select_center`, the
  page shell, the redirect control case, plain no-match, and the disabled-universe hint.
  The positionless star is read from `tests/fixtures/positionless-stars.json` rather than
  hardcoded, so this and `Unit\PositionlessStarTest` cannot drift onto different stars.

- **A v3 id shared by two stars now resolves to the brighter component** instead of being
  dropped. `build_index()` in `db/scripts/match_athyg_v3.py` used to remove any identifier
  held by two current stars (1,166 gaia, 61 hip, 12 hd, 11 hr — GAIA-DUPLICATES' real
  binary components). Maintainer decision, 2026-07-31: both components sit at the same
  point on the map, so landing on either beats refusing the link. Ties break on `id`, and a
  star with no magnitude sorts last rather than brightest.
  **The measured effect is much smaller than the roadmap predicted, which is the part worth
  keeping.** Only **2 ids were newly resolved** — a dropped gaia key did not end the lookup,
  it fell through the cascade to `tyc`, which resolves both components separately. The real
  change is that **536 ids now point at a different star**: the brighter component rather
  than whichever one Tycho-2 reached. So this is *536 old links land on the brighter
  component*, not *1,200 broken links fixed*.
  `athyg_v3_ids.csv` regenerated against the live catalog: 78,733 ranges covering 2,552,145
  ids (was 79,681 / 2,552,143). Verified by re-running `11_import_athyg_v3_ids.sql` against
  the live database and re-checking both the 7301 → 7323 regression case and a changed
  case (v3 6348: id 6366 at mag 10.134 → id 6367 at 9.676, same gaia id).
  These figures are the ones this file, `docs/api.md` and `docs/database.md` carried before
  the ambiguity refusal existed; they are back because the refusal is gone, not because
  anything was reverted.

- **`docs/user-guide.md` now documents searching.** It had two passing mentions of the
  feature and no explanation of it — including nothing about positionless stars, which
  `docs/api.md` and both changelogs already covered. New section: what the search accepts,
  the 1–2 character prefix rule, why fictional names need their universe on, and what
  happens in each UI when you find a star that cannot be mapped.

- **Search is fast again when a fictional universe is selected**
  [FICTIONAL-SEARCH-PERFORMANCE]. **19,642 ms → 0.502 ms** for `vulcan` with Star Trek on,
  measured by `EXPLAIN (ANALYZE)` against the live 2.84M-row catalog on a quiet host; the
  whole request is now 6.5–9.6 ms end to end, so the query is no longer a measurable part
  of it.
  Every search run by a visitor with Star Trek or Babylon 5 selected — **including searches
  for real star names** — was scanning essentially the whole table. `search.php` passes the
  session's `fic_names` into every query, so this was the normal path for one of the
  project's two stated audiences, not an edge case. PHP's client retries a timeout twice,
  so the worst case was ~90 s of waiting and three repeated full scans of one doomed query.
  **Cause: one `OR` disjunct.** The fictional-name match was a fifth branch of the
  real-name `WHERE`:

      ... OR EXISTS (SELECT 1 FROM fic f WHERE f.star_id = athyg.id AND ...)

  A correlated `EXISTS` there becomes a row-by-row `hashed SubPlan` filter, and the planner
  cannot combine a filter with index scans — so instead of `BitmapOr` over the four
  `pg_trgm` GIN indexes it walked `idx_athyg_absmag_bbox` in absmag order hoping the
  `LIMIT` would fill early. With 186 fictional rows against 2.84M stars, it never did:
  `Rows Removed by Filter: 2,837,261`.
  **The measurement that identified it was a pair, not a number:** `sirius` took 2.4 ms at
  `world_id=0` and exceeded a 15 s timeout at `world_id=1`. Same term, same matches, same
  indexes — so the cost could only be the disjunct's presence. Worth remembering as a
  diagnostic shape; the absolute timings were badly confounded by host load and nearly sent
  the investigation the wrong way.
  Fixed by searching the two things separately and unioning them, so each half is planned
  on its own. `fic` holds 191 rows, so that half is free whatever shape it takes. Each
  branch takes its own top-N before the union, which is exact rather than an approximation —
  the true top-N of a union can only come from the top-N of each side. `UNION` rather than
  `UNION ALL` preserves the no-duplicate-rows property for a star matching both a real and
  a fictional name.
  Also added a 5 s `statement_timeout` on this route as a backstop, so a future bad plan
  fails fast instead of hanging: search is meant to answer in milliseconds, and a bad plan
  does not improve with more time. Verified by inducing a cancellation, not assumed.
  **This was not a regression** — it shipped with fictional-name search on 2026-07-30 and
  had simply never been exercised with `world_id != 0`, by any test or any of five audit
  cycles. Five tests now cover that gap; the two most useful assert that selecting a world
  never drops or reorders a real-name result, and that the union still honours its limit.
- **`athyg_v3_ids.csv` is 2 MB instead of 51 MB, holding the same mapping.** Internal; no
  schema, API or behaviour change. GitHub warns above 50 MB per file and refuses above
  100 MB, and this file is committed *deliberately* — AT-HYG deleted the v3.3 source from
  its main branch six days before [ATHYG-V3-URLS] was built, so the derived mapping is the
  durable artifact and needs to stay comfortably storable.
  The file now holds **ranges** rather than one row per id:

      v3_start,v3_end,offset,match_method

  meaning *for every v3 id from `v3_start` to `v3_end`, `athyg_id = v3_id + offset`*.
  `11_import_athyg_v3_ids.sql` expands them with `generate_series`, so the `athyg_v3_ids`
  table is exactly what it always was and nothing downstream knows the difference.
  **This works because of a property of the data worth recording: AT-HYG 4 moved the
  catalog in blocks rather than shuffling it.** The offset stays constant across long runs
  of consecutive ids — 2,552,143 rows collapse to 6,582 distinct offset runs, and to
  79,681 ranges once `match_method` is allowed to break them. The one-row-per-id form was
  the obvious way to write it and it *hid* that structure; 79,681 ranges make the
  block-structured renumbering visible on inspection, which is a second reason to prefer
  it over simply compressing the old file.
  Verified end to end rather than in Python alone: the regenerated CSV was loaded into the
  live database and the expanded table compared against the previous one by row count and
  by `md5(string_agg(...))` over every row — **identical**, 2,552,143 rows, same digest.
  11 new tests pin the encoder, including that gaps survive (the 22 v3 stars that did not
  reach v4 must stay unmapped — expanding across one would invent a mapping for a dead
  link), that zero and negative offsets round-trip, and that the 7301 → 7323 regression
  case survives encoding. The writer refuses to emit a file that does not round-trip, and
  the importer rejects inverted ranges before expansion while the table's `PRIMARY KEY`
  catches overlapping ones — so neither way of corrupting the file passes silently.
- **Audit fixes from `.claude/reports/2026-07-31-2014/`** (the ten-auditor run). Each of
  these was a mechanical fix with an obvious correct answer; the judgment calls from the
  same run went to the ROADMAP instead.
  - `/api/stars/search` **reported `dist: null, mag: null` for every star.** None of the
    eight SELECT branches named those columns, so `StarBase`'s field defaults supplied
    nulls unconditionally, while `/api/stars/{id}` returned the real values for the same
    star. Filed by `audit-data` on **three consecutive cycles** and unfixed through a
    commit named "bug fixes", because nothing was visibly broken: React re-fetches the
    detail record and computes distance from `x/y/z`, and PHP's `searchStar()` reads only
    `id`/`x`/`display_name`. It mattered because [NULL-COORDINATES] had just taught every
    consumer that a null here means *"no parallax exists for this star"* — which the
    endpoint was then saying about 2.84M stars for which it is false. Four tests added,
    asserting the **values** rather than the keys (`"dist" in star` passes against the
    bug), including one that simply requires search and detail to agree about a star, and
    one that keeps a genuinely positionless star's null intact. The fixture gained real
    `dist`/`mag` for three stars: every row was NULL before, which cannot distinguish the
    two meanings and would have let the test pass either way.
  - **Integer ids are now bounded to the column they are bound to.** `star_id`, `v3_id`
    and `world_id` accepted any value FastAPI could coerce to `int`; anything above
    2,147,483,647 reached asyncpg, raised `DataError: value out of int32 range`, and
    surfaced as a **bare-text 500** — breaking the JSON error shape every other validation
    path returns, and logging a traceback for a malformed request. Now `le=PG_INT_MAX`, so
    it is a 422 like `limit`'s. `2147483647` itself still answers a normal 404: it is a
    value the column can hold, so it is an id that happens not to exist. Note this
    reproduces only on Postgres — SQLite has no int32 ceiling, so the API test suite saw a
    404 and could never have caught it before the bound moved validation to the FastAPI
    layer. (`audit-api`.)
  - **Every form that submits `select_star` now stamps `c=4`.** Three did not — the
    coordinate/zoom form and both name dropdowns — and `Request.php` defaults `c` to `0`
    when absent, so ordinary in-app navigation was indistinguishable from a pre-migration
    bookmark and paid for a blocking `/api/stars/legacy/{id}` lookup (measured 21–31 ms, on
    the critical path, on essentially every page render with a star selected). The
    regression test asserts this structurally — *any* form carrying `select_star` must
    carry the marker — so a fourth form added later is covered without anyone remembering.
    (`audit-performance`.)
  - **`search.php` has one page shell instead of three hand-written ones.** The
    `lang`/charset fix from `bc8ca142` landed in one branch and not its sibling six lines
    away, and the no-match branch emitted no `<head>` at all. `audit-frontend` found this
    class of defect **twice in the same function**, so the fix is the shared helper rather
    than a third copy. The API-failure message also stopped claiming the database is
    unreachable when it is merely slow to answer.
  - Doc corrections: `athyg_v3_ids` added to `.claude/CLAUDE.md`'s table list;
    `match_athyg_v3.py`'s docstring corrected to 1,166 Gaia / 61 HIP (it said 1,167/63,
    disagreeing with the live DB and every shipped doc); `ATHYG-V3-URLS.md`'s "Measured"
    section corrected to the numbers that actually shipped (it recorded a pre-fix run).
- **Star links saved before the AT-HYG 4 update are recognised again** [ATHYG-V3-URLS].
  `athyg.id` is the source catalog's row id, and AT-HYG 4 reassigned it: of the 2,552,143
  v3.3 stars still in the catalog, **only 636 kept their id**. So every bookmarked star link
  from before the migration pointed somewhere else — and did so *silently*, because
  **99.99% of v3 ids are also a valid, different v4 id**. Nothing 404s; the wrong star just
  loads. `?select_star=7301` shows an anonymous mag 11.5 star in Cepheus; it used to mean
  GJ 1, mag 8.755, in Sculptor.
  New `athyg_v3_ids` table maps 2,552,143 v3.3 ids onto current ones, built by
  `db/scripts/match_athyg_v3.py` and exposed as `GET /api/stars/legacy/{v3_id}`. Both UIs
  now stamp their own links with a catalog marker (`c=4`, `?star=…&c=4` in React) and, for
  an id arriving *without* one, show both readings and let the reader choose.
  **The app never guesses which catalog a bare id belongs to, and that is the whole design.**
  Treating unmarked ids as legacy would fix pre-migration links by breaking every link saved
  since, identically and silently — the damage is symmetric, so no guessing rule is safe.
  The marker is also what makes the *next* renumbering survivable.
  Three findings shaped it. **The source data was nearly lost:** AT-HYG deleted the v3.3
  CSVs from `main` on 2026-07-25, six days before this work; they were recovered from the
  parent commit via Git LFS (`media/`, not `raw/`, which returns a 133-byte pointer that
  looks like a corrupt download). **The mapping is many-to-one** — 5 current stars are each
  named by two v3 ids, because v4 merged v3.3 rows — so this is a lookup table, not a column
  on `athyg`; a column would have dropped five links and picked its winner
  *nondeterministically* via `UPDATE…FROM`, the same class of bug WIDE-ZOOM-QUERY fixed in
  `ORDER BY`. And **ambiguous identifiers are refused, not guessed**: 1,166 Gaia ids and 61
  HIP ids name two real binary components each (GAIA-DUPLICATES), so ~1,200 links resolve to
  neither.
  *Corrected 2026-07-31, after the above was written:* that last rule is more conservative
  than it needs to be. Resolving to the brighter component is fine — both components sit at
  the same point on the map — and the entry above overstates the cost of picking by
  equating it with the renumbering's wrong-star-in-another-constellation failure. The
  refusal stands for now because it is the safe direction, not because it is correct.
  Match rate 2,552,143 of 2,552,165 (100.0%), via gaia 2,514,585 / tyc 37,558; the 22
  unmatched are stars that did not survive to v4 and are honestly reported as dead links.
  A unit test caught a real bug before it shipped: `float()` on a 19-digit Gaia source_id
  loses precision (`…744064` → `…744192`), which would have silently corrupted the primary
  match key for 98.6% of the catalog.
- **Fixed: the "no parallax measurement" note was unreadable.** The note box added for
  positionless stars (`hygmap-php/src/index.php:134`) set a dark background with no `color`,
  and the classic UI's stylesheet is a light theme that supplies no inherited colour — so the
  text rendered default black on `#3a2f00`, a contrast ratio of ≈1.64:1. The box is now a
  light amber panel (`#fff8e1` with `#3a2f00` text, ≈14:1), which keeps the intended accent
  and matches the rest of the page. Found by `audit-frontend` 2026-07-31, verified live.
- Small correctness fixes from the same audit run: `search.php`'s "found, but not mappable"
  page now emits `lang="en"` and `<meta charset="utf-8">` like `ErrorHandler.php` does;
  `docs/api.md:96` said 25,341 positionless stars where the DB and the rest of the file say
  25,342; and `ApiClient.php`'s class comment no longer describes itself as mirroring the
  `Database` class, which was deleted by the PHP-API migration six months ago.
- **Fixed: stars with no known distance were drawn at the Sun.** 25,342 catalogue stars have
  no usable parallax, so the API returns null for `dist` and `x`/`y`/`z` rather than
  inventing a position. Neither frontend was updated when that landed. PHP cast the nulls to
  float, which yields `0.0` — a real coordinate, Sol's — so such a star was silently plotted
  at the origin, and `select_center=1` re-centred the entire map there. React still typed
  the coordinates as non-null, so selecting one drove the camera and the distance arithmetic
  to `NaN`.
  They are now **findable but inert**, per the maintainer's decision: they appear in search
  results, are never plotted, and both UIs state that the position is unknown and why.
  Hiding them was rejected — a HIP number that exists should not report "not found".
- Changing the React type produced **33 TypeScript errors**, every one a real unguarded
  consumer, and fixing them took the count to 0. That is precisely the class of bug
  `make typecheck-frontend` was wired into CI to catch. Most of the render pipeline now
  takes a `PositionedStar`, so "only plottable stars reach the renderer" is a compile-time
  guarantee rather than a convention; `calculateDistance` returns `null` instead of `NaN`,
  because NaN propagates silently and formats as "NaN pc".
- **The `/api/stars/search` name and catalog-ID branches now agree.** The name branch
  excluded positionless stars while the seven catalog-ID branches returned them, so
  `?q=hip+60798` and a search for the same star's name disagreed — `audit-api` filed this
  on 2026-07-31. Note its suggested fix was the wrong direction: adding the exclusion to
  the catalog branches would have hidden 25,342 real stars to make two code paths agree.
- **The two unmappable classes are kept apart deliberately.** Stars with *no* position have
  no `absmag`, so `ORDER BY absmag ASC NULLS LAST` sorts all of them last and they can never
  displace a real result inside a limit — verified live, a "Cyg" search at limit 100 returns
  none. Stars *beyond* `MAX_COORDINATE_VALUE` (1,222) have absurd coordinates from a broken
  parallax and an `absmag` bright enough to sort **first**, which is what made a search for
  "Cen" return artifacts ahead of real stars and answer 503 when one was selected. Those stay
  excluded. One blanket rule would have either reopened the 503 or hidden the 25,342.
- Two measurements did the design work and are recorded because they were worth checking
  rather than assuming: `dist` and `x/y/z` **always travel together** — zero counterexamples
  in either direction across 2,837,262 rows, which is what justifies a single
  `hasPosition()` helper per stack instead of three field-by-field checks; and **no
  positionless star carries an `absmag`**, which is what made "findable but inert" almost
  free to implement.
- 19 tests across all three suites, driven by a new shared fixture
  `tests/fixtures/positionless-stars.json` rather than three separately-invented mocks. The
  case that earns its place: **Sol at 0,0,0 has a position.** A helper written with a
  truthiness check would classify the Sun as positionless and recreate the same confusion
  from the opposite direction. Two existing tests asserted the previous decision and were
  rewritten, not deleted — one of them demonstrated that the fictional-name predicate,
  deliberately placed *inside* the unmappable guard rather than beside it, adapted to the
  new behaviour automatically and consistently without being touched.

- **Fixed: all three display-name fixture suites skipped themselves silently.** The shared
  `tests/fixtures/display-names.json` is read by the PHP, API and React suites, which is
  what makes DISPLAY-NAME-CANON a single source of truth rather than three private ones —
  but every one of them skipped when the file was not mounted, which reads as green. One
  Makefile edit away from voiding the guarantee in all three tiers with nothing to say so.
  An audit flagged the React `it.skip`; the same pattern turned out to be in PHP (two
  `markTestSkipped`) and the API (two `@pytest.mark.skipif`). All three now fail with a
  message naming the Makefile target, and each asserts the fixture is non-empty so a
  truncated file cannot yield a green run with no tests. Verified by unmounting: API errors
  at collection, React throws, PHP exits 2. The PHP fix needed a `RuntimeException` in the
  data provider, since providers run before `setUp` and cannot assert.
- **`csv_safe()` has tests for the first time** — 28 of them, in
  `hygmap-php/tests/Unit/CsvSafeTest.php`. It is the guard that stops a star name beginning
  `=`, `+`, `-` or `@` executing as a formula when an exported CSV is opened in Excel,
  LibreOffice or Google Sheets, and star names come from third-party catalogs. Two audits
  reported it as untested; the reason was that it was declared inside `export.php`, which
  performs the export on include, so exercising it meant running the whole endpoint. Moved
  to `src/CsvSafe.php` — the same extraction REPO-HYGIENE did for `MapRenderer`.
  Coverage includes the canonical `=cmd|'/c calc'!A1` payload, whitespace-hidden formulas
  (Excel strips leading space before deciding, so `" =cmd"` still executes) and that the
  original spacing is preserved rather than the trimmed value, plus the false-positive
  side: `Alpha-Centauri` and `Barnard's Star` must pass through untouched, because
  corrupting real names in every export would affect every user rather than a targeted
  reader. One deliberate trade-off is now pinned by a test: a negative number already cast
  to a string is indistinguishable from a formula and gets quoted, which is safe only
  because `export.php` passes floats. Export verified live after the refactor.

- **Fixed: ~460 stars were drawn a different colour in the 3D view than on the 2D map.**
  Found by `audit-data` 2026-07-31. React read the first character of `spect` blindly, so a
  subdwarf like `sdF8:` classified as `s` and fell through to white instead of the F colour;
  and it had no entry for the carbon and S-type classes, so `R`, `C`, `N` and `S` all fell
  to white where the PHP map drew them orange or red. Live counts: 53 `sd`, 145 `C`, 116
  `N`, 49 `S`, 103 `R`. `getStarColor` in `hygmap-frontend/src/domain/star.ts` now mirrors
  `MapRenderer::getSpecClass`, including its deliberate case-sensitivity — the prefix test
  is for lowercase `s` only, so an S-type star like `S4,2` is not mistaken for a subdwarf.
- `tests/fixtures/spectral-colors.json` is a new shared fixture, read by both a new React
  suite and a new PHP suite, on the `display-names.json` model. It pins the *classification*
  and a colour **name**, not hex values: the two UIs use different palettes on purpose (flat
  GD colours on white vs an emissive material on black), so forcing them to converge would
  be the wrong fix. What must never diverge again is which stars are grouped together.
  The PHP side tests `MapRenderer`'s private methods through reflection with a name-keyed
  colour table injected, which avoids needing GD in the test image.
- **The nondeterminism fix now has behavioural tests — and they proved a limitation worth
  recording.** `audit-tests` filed a major finding that the [WIDE-ZOOM-QUERY] tiebreaker was
  tested only by regex, and proposed adding tied-`absmag` rows to the SQLite fixture so the
  route could be called twice. Done (8 tests), **but the proposed fix does not detect the
  bug it targets**: `conftest.py` declares `id INTEGER PRIMARY KEY`, which in SQLite aliases
  `id` to `rowid`, so a scan already yields id order and the sorter preserves it for tied
  keys. Verified by mutation — deleting `, a.id` from every clause leaves all eight green.
  Postgres has no such coincidence, which is why the bug was real in production and
  invisible in the suite. Mutation testing both ways: removing the tiebreaker fails only the
  shape tests; reversing it to `a.id DESC` fails both. The two layers are therefore
  complementary, and the regex assertions are the only thing catching deletion on SQLite —
  recorded in the test file so they are not "cleaned up" as redundant.
- `docs/api.md` now documents that `x`, `y`, `z` and `dist` can be null, why inventing a
  position was deliberately removed, and that the name-search and catalog-ID branches of
  `/api/stars/search` currently disagree about returning positionless stars — including a
  warning not to "fix" that by adding the exclusion to the catalog branches, since the
  recorded product decision points the other way.
- **An audit finding was wrong and the correction is recorded so it is not re-filed.**
  `audit-security` reported "no Content-Security-Policy at the Apache layer" for a third
  consecutive cycle. There is one, in `hygmap-php/src/.htaccess`, and it is served —
  confirmed by mutating `nosniff` to a sentinel, observing it in the live response, and
  restoring it. `apache2.conf` sets `AllowOverride None` for `/var/www/`, which makes the
  file look inert, but `conf-enabled/docker-php.conf` sets `AllowOverride All` and wins. The
  real weakness is narrower: the policy allows `script-src 'unsafe-inline'`, which removes
  most of CSP's XSS value and needs nonces or hashes for four inline script blocks. Now a
  roadmap item in its own right.

- **Fixed: the import pipeline was inserting thousands of stars twice.** `athyg` carried
  3,862 groups of rows sharing a Gaia DR3 source_id — up from 576 under AT-HYG 3.3, growing
  with every catalog addition. Investigating split them into two unrelated populations, and
  only one was a defect.
  **2,695 groups were created by this pipeline.** `cns5.csv` and `gcns.csv` are both
  produced by cross-matching against the live database, and both were generated from the
  same pre-supplement snapshot, so neither could see what the other would insert: measured,
  **2,598 of the 2,665 stars CNS5 introduces as new are introduced as new by GCNS too**.
  The same physical star was inserted twice in one build — gaia 1005873614080407296 as ids
  5000426 and 6069717, RA agreeing to nine decimal places, identical magnitude and spectral
  type. Both `new`-star inserts now skip a Gaia id that already exists. The guards are
  symmetric so neither import depends on running before the other, which is what the
  original bug assumed; `ON CONFLICT (id)` never covered it, because it is the Gaia identity
  that collides, not the primary key.
  Verified by two clean rebuilds from an empty volume: **2,839,957 → 2,837,262 rows**, the
  difference being exactly the 2,695 duplicates, with all 191 fictional names still
  resolving, Polis's override intact, one NULL constellation (Sol, deliberate), and both
  quality checks green.
- **1,167 groups were left alone, deliberately** (1,166 in the finished database — the
  retraction below removes one). They are inherited from AT-HYG and are
  real close binaries: Tycho-2 resolved both components, Gaia DR3 recorded one source.
  **88% carry hard evidence** — distinct Tycho-2 identifiers and/or explicit component
  designations (Graffias / Graffias B; GJ 314A / GJ 314B with Tycho ids 6571-3298-1 and -2).
  Even the pairs at *identical* positions have distinct Tycho ids and differing magnitudes;
  their positions match because both rows inherited the same Gaia source's astrometry, not
  because they are one star. Merging them would have deleted real stars to make a database
  key convenient.
  **Consequence, worth recording:** Gaia alone can therefore never be the durable key for
  `STABLE-STAR-URLS`. That feature needs Gaia plus a component discriminator, or a
  different key.
- The build now **fails** if a duplicate Gaia group contains a row the pipeline inserted.
  The invariant is deliberately "no duplicate we created" rather than "no duplicates" — a
  guard asserting zero would fail on 1,166 legitimate binaries and would be deleted the
  first time someone hit it. `db/scripts/check_duplicates.py` reports the same split
  after the fact, with the inherited count tracked against a baseline so it cannot grow
  silently.
- **Fixed one star carrying another star's astrometry.** `gaia 647372483426742912` was held
  by two rows **95.3 degrees apart** — tyc 1966-277-1 in Leo and tyc 3986-3499-1 in Cepheus.
  Gaia source_ids encode a level-12 HEALPix pixel, so the id itself settles the question: it
  decodes to RA 9.84814h Dec +28.3445, **0.0052° from the Leo row and 95.2869° from the
  Cepheus one**. The error is upstream in `athyg_40.csv` — both rows arrive with it, and the
  Cepheus row also inherited the Leo star's distance, proper motion and radial velocity, so
  its `G_R3` distance was another star's parallax. Retracted through
  `athyg_overrides.csv`, along with everything derived from it; the star keeps only what was
  actually measured for it, a Tycho-2 position and a magnitude.
- The override mechanism gained two capabilities that case needed: a **Tycho-2 key** (keying
  on Gaia would have matched both rows and corrected the innocent one, and the star has no
  HIP number) and **`clear_gaia`**, which drops a wrong identification together with the
  distance, absolute magnitude and coordinates derived from it. Leaving the distance would
  keep a number that is precisely wrong, which is the failure mode `DATA-QUALITY-OUTLIERS`
  and `COORD-RECOMPUTE-FIX` both existed to remove.
- **Removing rows invalidated `constellations.csv`**, which is keyed on `athyg_id`, and the
  first clean rebuild failed at step 10 with "stale: 2695 row(s) reference an athyg_id that
  does not exist" — the exact count of removed duplicates. That is `CATALOG-ID-INTEGRITY`'s
  staleness guard doing precisely its job, and it is worth recording as a standing coupling:
  **any change to which rows exist requires regenerating `constellations.csv`.** Regenerated
  (281,300 → 278,605 rows, again exactly 2,695 fewer) and the rebuild repeated clean.
- **Fixed a units bug in the new check itself, found while writing the upstream report.**
  `check_duplicates.py`'s wide-separation query fed `radians(h.ra - l.ra)` into the
  haversine, but `athyg.ra` is in **hours**, not degrees — understating every RA difference
  by a factor of 15. It happened to return the right verdict on the case it was written
  against (95.3° read as 29.2°, over the 1° threshold either way), which is precisely why
  it needed a test rather than a spot check: a subtler case would have slipped under
  silently. Corrected and pinned by two tests.
- 15 tests added in `db/scripts/test_check_duplicates.py`, covering the classification rule
  directly and asserting that both import guards, the build-time exception, and the two new
  override capabilities are still present — the SQL itself needs Postgres and 2.8M rows, so
  what is testable in CI is that the guards have not been removed.
- **Fixed: the star list was nondeterministic.** Found while working on wide-zoom
  performance, and the more serious of the two findings. `absmag` is heavily tied —
  2,784,293 of 2,839,957 stars share a value with at least one other star — so
  `ORDER BY absmag LIMIT n` cut through the middle of a tie group and returned whichever
  rows the scan happened to reach first. Measured: **three identical runs of one wide-zoom
  query, same plan, returned three different star sets.** Reloading a map view could
  silently change which stars appeared. Every clause in `ORDER_CLAUSES` now ends with
  `a.id`, which is unique and never null, making each ordering total and the endpoint
  repeatable. Verified by running the same request three times through the API and
  diffing the payloads: byte-identical.
- **Wide-zoom bounding-box queries are 3.6–45× faster**, via
  `idx_athyg_absmag_bbox (absmag, id, x, y, z)`. Measured server-side medians, before →
  after: ±100 pc 574→161ms, ±250 743→39ms, ±500 924→54ms, ±1000 1038→23ms, and ±1500 pc at
  limit 50000 1590→217ms with the **disk-based external merge sort (~211MB across three
  workers) gone entirely**. Narrow zoom is untouched and still uses `idx_athyg_galactic`
  (±20 pc: 33ms both before and after). 109MB against an 806MB table.
- The audit's framing of that finding — "wide zoom does not use the spatial indexes" — was
  correct as an observation and misleading as a diagnosis, so it is worth recording why.
  The planner was right to ignore them: measured, the box holds **55% of the catalog at
  ±500 pc and 92% at ±1500 pc**, and a sequential scan is the correct plan for a filter
  that keeps 92% of the rows. The cost was the sort, not the scan. A composite spatial
  index — the feature file's first suggestion — could not have helped.
  An index on `absmag` alone was tried and rejected: it fixed wide zoom but was **5.2×
  slower than the sequential scan at ±100 pc**, because it did a random heap fetch per
  candidate row. Putting `x, y, z` in the index lets the planner apply them as `Index Cond`
  and reject rows without touching the heap, which is what makes every zoom level fast.
- Note for whoever looks at `/api/stars` performance next: at limit 50000 the query is
  217ms and the request is ~2.4s, so **serialising the ~18MB JSON response is now the
  bottleneck**, not the database. That is a different problem from this one.
- 5 tests added in `hygmap-api/tests/test_order_determinism.py`. They pin the two inputs
  that determine the plan — that every order clause ends with the tiebreaker and that it
  directly follows the sort key, and that the index keeps its exact column order — because
  the plan shape itself needs Postgres and 2.8M rows and cannot be asserted in a suite that
  runs on SQLite. `make test-api` now mounts `db/sql` so the second of those can read the
  DDL; as with the compose guard, a missing file fails rather than skips.
- **Fixed: a security guard that had never once executed.**
  `TestProdComposeTrustSetting` asserts that production does not ship
  `FORWARDED_ALLOW_IPS=*` — the exact misconfiguration `PROXY-TRUST` fixed, where any
  caller could forge `X-Forwarded-For` and get their own rate-limit bucket. It resolved
  `docker-compose.prod.yml` relative to the repo root, which `make test-api` does not mount,
  so it skipped. CI runs `make test-api` as well, so the guard was inert everywhere. The
  `test-api` target now mounts the file (at container `/`, which is what `tests/../..`
  resolves to there); the API suite went from 132 passed / 2 skipped to 134 / 0.
  The skip path was removed too — a missing file now fails with a message explaining how to
  fix it. A guard that quietly passes when its subject is absent is how this survived two
  audit cycles. Verified by running the suite against a compose file doctored to say
  `FORWARDED_ALLOW_IPS=*` (fails) and with the file absent (fails rather than skips).
- 18 tests added in `db/scripts/test_compute_constellations.py::TestCelestialPoles`
  covering exactly dec = ±90, which had only incidental coverage: no star in the 2.84M-row
  catalog sits within 0.1° of either pole, so the branch would not be exercised by real
  data until a future import landed one. +90 → UMi and −90 → Oct, both implementations
  agreeing, for every RA. Two properties are asserted beyond the answer, because the answer
  alone does not pin the behaviour: **precession moves the pole 0.7° off ±90** (to dec
  89.3038 in B1875), so the poles resolve inside UMi's 88° band and never reach the table's
  polar rows — meaning a scan that special-cased `dec == 90` would look correct and be
  wrong; and RA is degenerate at the pole, so the result must not depend on which meridian
  was passed. Confirmed by mutation: disabling precession fails three of the new tests
  while the UMi/Oct assertions still pass. `db/scripts` is now 179 tests.
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
