"""
Star API endpoints
"""
from fastapi import APIRouter, Depends, Path, Query, HTTPException, Request
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import text
from app.limiter import limiter
from app.database import get_db
from app.schemas import (
    StarListResponse,
    StarDetailResponse,
    LegacyStarResponse,
    StarBase,
    StarDetail,
    ProperName,
    ProperNamesResponse,
    FictionalName,
    FictionalNamesResponse,
    World,
    WorldsResponse,
)
from app.config import settings

router = APIRouter()

# Maps spelled-out Bayer designation names to the abbreviated form stored in
# athyg.bayer (e.g. "Alp", "Alp-1"), so a search like "alpha cen" can match.
GREEK_LETTER_ABBREV = {
    "alpha": "alp",
    "beta": "bet",
    "gamma": "gam",
    "delta": "del",
    "epsilon": "eps",
    "zeta": "zet",
    "eta": "eta",
    "theta": "the",
    "iota": "iot",
    "kappa": "kap",
    "lambda": "lam",
    "mu": "mu",
    "nu": "nu",
    "xi": "xi",
    "omicron": "omi",
    "pi": "pi",
    "rho": "rho",
    "sigma": "sig",
    "tau": "tau",
    "upsilon": "ups",
    "phi": "phi",
    "chi": "chi",
    "psi": "psi",
    "omega": "ome",
}

# The abbreviations themselves, so a user who types the stored form ("alp cen") gets
# the same treatment as one who types it out ("alpha cen").
GREEK_ABBREV_VALUES = frozenset(GREEK_LETTER_ABBREV.values())

# Shortest search term that produces a usable pg_trgm trigram. Below this, name
# searches are anchored to a prefix so the GIN indexes can still filter — see the
# comment in search_stars().
TRIGRAM_MIN_CHARS = 3

# Maximum allowed spatial range per dimension (parsecs)
# Set to 3000 to accommodate distant stars in the AT-HYG catalog
MAX_SPATIAL_RANGE = 3000.0

# Maximum absolute coordinate value (parsecs)
# AT-HYG catalog typically contains stars within ~10,000 parsecs
MAX_COORDINATE_VALUE = 10000.0

# Largest value a Postgres `integer` column can hold. Every id this API accepts is bound
# to one, so anything above this is not a row that happens not to exist -- it is a value
# the column cannot represent. Without the bound, FastAPI's plain-`int` coercion accepts
# it, asyncpg raises DataError("value out of int32 range") at bind time, and the request
# ends as a bare-text 500 rather than the JSON 4xx every other validation path returns.
# Found by audit-api 2026-07-31.
PG_INT_MAX = 2147483647

# Allowlist for ORDER BY clause to prevent SQL injection.
#
# Every clause ends with `a.id` as a tiebreaker, and that is a correctness fix rather than a
# tidy-up. Sort keys here are massively tied -- 2,784,293 of 2,839,957 stars share an absmag
# value with at least one other star -- so `ORDER BY absmag LIMIT n` cut through the middle
# of a tie group and which stars landed inside the limit was whatever the scan happened to
# reach first. Measured before the fix: three identical runs of one wide-zoom query, same
# plan, returned three different star sets. Reloading a map view could silently change which
# stars it showed. `a.id` is unique and never null, so it makes the result a total order and
# therefore repeatable.
#
# It also has to sit immediately after the sort key for `idx_athyg_absmag_bbox` to satisfy
# the ordering without a sort node -- see the comment on that index in
# db/sql/02_create_indexes.sql before changing anything here.
ORDER_CLAUSES = {
    "absmag": "a.absmag ASC NULLS LAST, a.id",
    "absmag asc": "a.absmag ASC NULLS LAST, a.id",
    "absmag desc": "a.absmag DESC NULLS LAST, a.id",
    "mag": "a.mag ASC NULLS LAST, a.id",
    "mag asc": "a.mag ASC NULLS LAST, a.id",
    "mag desc": "a.mag DESC NULLS LAST, a.id",
    "proper": "a.proper ASC NULLS LAST, a.id",
    "proper asc": "a.proper ASC NULLS LAST, a.id",
    "proper desc": "a.proper DESC NULLS LAST, a.id",
    "dist": "a.dist ASC NULLS LAST, a.id",
    "dist asc": "a.dist ASC NULLS LAST, a.id",
    "dist desc": "a.dist DESC NULLS LAST, a.id",
}
DEFAULT_ORDER = "absmag asc"


@router.get("/", response_model=StarListResponse)
@limiter.limit(settings.RATE_LIMIT)
async def get_stars(
    request: Request,  # Required for rate limiter
    xmin: float = Query(-50, description="Minimum X coordinate (parsecs)"),
    xmax: float = Query(50, description="Maximum X coordinate (parsecs)"),
    ymin: float = Query(-50, description="Minimum Y coordinate (parsecs)"),
    ymax: float = Query(50, description="Maximum Y coordinate (parsecs)"),
    zmin: float = Query(-50, description="Minimum Z coordinate (parsecs)"),
    zmax: float = Query(50, description="Maximum Z coordinate (parsecs)"),
    mag_max: float = Query(None, description="Maximum absolute magnitude (LOD filter, dimmer stars excluded)"),
    limit: int = Query(10000, ge=1, le=50000, description="Maximum number of stars to return"),
    world_id: int = Query(0, ge=0, le=PG_INT_MAX, description="Fictional world ID for fictional names (0 = no fictional names)"),
    order: str = Query(DEFAULT_ORDER, description="Sort order (absmag/mag/proper/dist asc|desc)"),
    db: AsyncSession = Depends(get_db),
):
    """
    Get stars within specified 3D spatial bounds.

    Returns stars ordered by the specified field (default: absolute magnitude, brightest first).
    Uses the athyg table with galactic coordinates.
    Optional mag_max parameter for LOD - only return stars brighter than this magnitude.
    Optional world_id parameter to include fictional names from the fic table.
    """
    # Validate coordinate values are within reasonable range
    coordinates = [xmin, xmax, ymin, ymax, zmin, zmax]
    if any(abs(coord) > MAX_COORDINATE_VALUE for coord in coordinates):
        raise HTTPException(
            status_code=400,
            detail=f"Coordinate values must be within ±{MAX_COORDINATE_VALUE} parsecs"
        )

    # Validate bounds are ordered correctly
    if xmin >= xmax or ymin >= ymax or zmin >= zmax:
        raise HTTPException(
            status_code=400,
            detail="Invalid bounds: min values must be less than max values"
        )

    # Validate spatial range is not too large
    if (xmax - xmin > MAX_SPATIAL_RANGE or
        ymax - ymin > MAX_SPATIAL_RANGE or
        zmax - zmin > MAX_SPATIAL_RANGE):
        raise HTTPException(
            status_code=400,
            detail=f"Spatial range too large: maximum {MAX_SPATIAL_RANGE} parsecs per dimension"
        )

    # Validate order against allowlist to avoid SQL injection
    order_clause = ORDER_CLAUSES.get(order.strip().lower())
    if not order_clause:
        raise HTTPException(
            status_code=400,
            detail="Invalid order parameter. Allowed values: absmag, mag, proper, dist (asc/desc)"
        )

    # Build query with optional magnitude filter and fictional name join
    mag_filter = "AND a.absmag < :mag_max" if mag_max is not None else ""
    query = text(f"""
        SELECT
            a.id,
            a.proper,
            a.bayer,
            a.flam,
            a.con,
            a.spect,
            a.absmag,
            a.mag,
            a.dist,
            a.x,
            a.y,
            a.z,
            a.hip,
            a.hd,
            a.hr,
            a.gj,
            a.cns5,
            a.gaia,
            a.tyc,
            COALESCE(f.name, '') AS name
        FROM athyg a
        LEFT JOIN fic f ON a.id = f.star_id AND f.world_id = :world_id
        WHERE a.x > :xmin AND a.x < :xmax
          AND a.y > :ymin AND a.y < :ymax
          AND a.z > :zmin AND a.z < :zmax
          {mag_filter}
        ORDER BY {order_clause}
        LIMIT :limit
    """)

    params = {
        "xmin": xmin,
        "xmax": xmax,
        "ymin": ymin,
        "ymax": ymax,
        "zmin": zmin,
        "zmax": zmax,
        "limit": limit,
        "world_id": world_id,
    }
    if mag_max is not None:
        params["mag_max"] = mag_max

    result = await db.execute(query, params)

    rows = result.mappings().all()
    stars = [StarBase(**row) for row in rows]

    return StarListResponse(
        result="success",
        data=stars,
        length=len(stars),
    )


@router.get("/search", response_model=StarListResponse)
@limiter.limit(settings.RATE_LIMIT)
async def search_stars(
    request: Request,  # Required for rate limiter
    q: str = Query(..., min_length=1, max_length=100, description="Search query (name or catalog ID)"),
    limit: int = Query(20, ge=1, le=100, description="Maximum number of results"),
    world_id: int = Query(0, ge=0, le=PG_INT_MAX, description="Fictional world ID for fictional names (0 = no fictional names)"),
    db: AsyncSession = Depends(get_db),
):
    """
    Search for stars by name or catalog ID.

    Searches proper names, Bayer/Flamsteed designations, and catalog IDs
    (HIP, HD, HR, GJ, Gaia, TYC).

    Optional world_id also searches fictional names from the fic table, scoped to that
    world: "vulcan" finds Keid when Star Trek is selected and nothing otherwise. Fictional
    names are deliberately NOT matched across all worlds -- a star must be named the same
    way everywhere on one page load (see DISPLAY-NAME-CANON).
    """
    search_term = q.strip()

    # Reject too-short ASCII queries but allow single-character non-ASCII (e.g., emoji, Greek letters)
    if len(search_term) < 2 and search_term.isascii() and search_term.strip().isalnum():
        raise HTTPException(
            status_code=422,
            detail="Search term must be at least 2 characters",
        )

    # Additional validation for search length
    if len(search_term) > 100:
        raise HTTPException(
            status_code=400,
            detail="Search term too long (maximum 100 characters)"
        )

    search_lower = search_term.lower()

    # Cap this request's query time. See SEARCH_STATEMENT_TIMEOUT_MS for why a backstop
    # rather than a tuning knob. SET LOCAL is scoped to the surrounding transaction, so it
    # cannot leak onto a pooled connection and silently throttle some later request.
    #
    # Guarded on the dialect because the test suite runs on SQLite, which has no such
    # setting. That is a real gap and worth naming: this line is exercised in production
    # and by the PHP integration suite against the live stack, but not by the API tests.
    if db.bind and db.bind.dialect.name == "postgresql":
        await db.execute(
            text(f"SET LOCAL statement_timeout = {int(settings.SEARCH_STATEMENT_TIMEOUT_MS)}")
        )

    # Check if it's a catalog ID search (e.g., "HIP 12345", "HD 123456")
    catalog_prefixes = {
        'hip': 'hip',
        'hd': 'hd',
        'hr': 'hr',
        'gj': 'gj',
        'gl': 'gj',  # Gliese alternate
        'cns5': 'cns5',
        'gaia': 'gaia',
        'tyc': 'tyc',
    }

    catalog_field = None
    catalog_value = None

    for prefix, field in catalog_prefixes.items():
        if search_lower.startswith(prefix + ' ') or search_lower.startswith(prefix + '_'):
            catalog_value = search_term[len(prefix)+1:].strip()
            catalog_field = field
            break
        elif search_lower.startswith(prefix) and search_lower[len(prefix):].strip().isdigit():
            catalog_value = search_lower[len(prefix):].strip()
            catalog_field = field
            break

    if catalog_field and catalog_value:
        # Search by catalog ID using pre-built queries (no f-string interpolation)
        # Each query is explicit to prevent any possibility of SQL injection.
        # Each also carries the world-scoped fictional name, so a catalog lookup with a
        # universe selected agrees with the map about what the star is called -- "HD 26965"
        # returns display_name "Vulcan" under Star Trek. Written out seven times rather
        # than generated, because the explicitness here is a recorded security decision.
        CATALOG_QUERIES = {
            'hip': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, cns5, gaia, tyc, dist, mag,
                       (SELECT f.name FROM fic f
                         WHERE f.star_id = athyg.id AND f.world_id = :world_id
                         ORDER BY f.id LIMIT 1) AS name
                FROM athyg WHERE hip = :catalog_value LIMIT :limit
            """),
            'hd': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, cns5, gaia, tyc, dist, mag,
                       (SELECT f.name FROM fic f
                         WHERE f.star_id = athyg.id AND f.world_id = :world_id
                         ORDER BY f.id LIMIT 1) AS name
                FROM athyg WHERE hd = :catalog_value LIMIT :limit
            """),
            'hr': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, cns5, gaia, tyc, dist, mag,
                       (SELECT f.name FROM fic f
                         WHERE f.star_id = athyg.id AND f.world_id = :world_id
                         ORDER BY f.id LIMIT 1) AS name
                FROM athyg WHERE hr = :catalog_value LIMIT :limit
            """),
            'gj': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, cns5, gaia, tyc, dist, mag,
                       (SELECT f.name FROM fic f
                         WHERE f.star_id = athyg.id AND f.world_id = :world_id
                         ORDER BY f.id LIMIT 1) AS name
                FROM athyg WHERE gj = :catalog_value LIMIT :limit
            """),
            'cns5': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, cns5, gaia, tyc, dist, mag,
                       (SELECT f.name FROM fic f
                         WHERE f.star_id = athyg.id AND f.world_id = :world_id
                         ORDER BY f.id LIMIT 1) AS name
                FROM athyg WHERE cns5 = :catalog_value LIMIT :limit
            """),
            'gaia': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, cns5, gaia, tyc, dist, mag,
                       (SELECT f.name FROM fic f
                         WHERE f.star_id = athyg.id AND f.world_id = :world_id
                         ORDER BY f.id LIMIT 1) AS name
                FROM athyg WHERE gaia = :catalog_value LIMIT :limit
            """),
            'tyc': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, cns5, gaia, tyc, dist, mag,
                       (SELECT f.name FROM fic f
                         WHERE f.star_id = athyg.id AND f.world_id = :world_id
                         ORDER BY f.id LIMIT 1) AS name
                FROM athyg WHERE tyc = :catalog_value LIMIT :limit
            """),
        }

        query = CATALOG_QUERIES.get(catalog_field)
        if query is None:
            raise HTTPException(
                status_code=400,
                detail=f"Invalid catalog field: {catalog_field}"
            )

        result = await db.execute(
            query,
            {"catalog_value": catalog_value, "limit": limit, "world_id": world_id},
        )
    else:
        # Search by name (proper, bayer, constellation)
        # Use LOWER() for case-insensitive search (works with both PostgreSQL and SQLite)
        def escape_like(value: str) -> str:
            return (value
                    .replace("\\", "\\\\")
                    .replace("%", "\\%")
                    .replace("_", "\\_"))

        # Substring search below TRIGRAM_MIN_CHARS is anchored to a prefix instead.
        #
        # The name-search predicates are backed by pg_trgm GIN indexes, which need a
        # full trigram to filter on. A '%xx%' pattern of 1-2 characters yields none,
        # so every bitmap index scan returns all ~2.8M rows and the query is slower
        # than the sequential scan the index replaced (measured: 1.4s -> 3.3s for
        # 'zz'). An anchored 'xx%' pattern does produce an indexable trigram, so it
        # stays fast (measured: 0.05s).
        #
        # The semantics tighten for 1-2 character queries only: they match the start
        # of a name rather than anywhere inside it. That is both the faster and the
        # more useful reading of a two-letter query against 2.8M stars.
        anchored = len(search_lower) < TRIGRAM_MIN_CHARS
        escaped_term = escape_like(search_lower)
        like_pattern = f"{escaped_term}%" if anchored else f"%{escaped_term}%"

        # If the query starts with a spelled-out Greek letter (e.g. "alpha cen"),
        # also try a pattern matched against the abbreviated Bayer form used in
        # the DB (e.g. "alp%cen", matching "alp-1 cen"), since a plain substring
        # match against "alpha cen" never matches "Alp-1 Cen".
        # Accept both the spelled-out name and the abbreviation as the first token.
        # athyg.bayer stores a component suffix on multiple systems ("Alp-1 Cen"), so a
        # plain substring match on "alp cen" finds nothing even though the user typed
        # the exact stored abbreviation. Splitting on '%' bridges the suffix either way.
        bayer_pattern = like_pattern
        tokens = search_lower.split(None, 1)
        first = tokens[0] if tokens else ""
        if first in GREEK_LETTER_ABBREV or first in GREEK_ABBREV_VALUES:
            abbrev = GREEK_LETTER_ABBREV.get(first, first)
            rest = escape_like(tokens[1]) if len(tokens) > 1 else ""
            # Anchored for the same indexability reason as above; every abbreviation
            # is 2-3 characters, so an unanchored '%alp%' would not filter either.
            bayer_pattern = f"{abbrev}%{rest}%" if rest else f"{abbrev}%"

        # Two independent searches unioned, NOT one WHERE with an OR.
        #
        # The fictional-name match used to be a fifth disjunct here:
        #
        #     ... OR EXISTS (SELECT 1 FROM fic f WHERE f.star_id = athyg.id AND ...)
        #
        # which read well and was catastrophically slow. A correlated EXISTS in that
        # position becomes a row-by-row `hashed SubPlan` filter, and the planner cannot
        # combine a filter with index scans -- so instead of `BitmapOr` over the four
        # pg_trgm GIN indexes it walked idx_athyg_absmag_bbox in absmag order, hoping the
        # LIMIT would fill early. For a fictional name it never does: `fic` holds 186 rows
        # for world 1 against 2.84M stars, so the match sorts near the end.
        #
        # Measured on the live catalog (FICTIONAL-SEARCH-PERFORMANCE, 2026-07-31):
        #
        #     q=vulcan&world_id=1   19,642 ms   Rows Removed by Filter: 2,837,261
        #     same query, this shape     1.3 ms   BitmapOr over all four trigram indexes
        #
        # The diagnostic that identifies this class of bug: `sirius` was 2.4 ms at
        # world_id=0 and timed out past 15 s at world_id=1. Same term, same match count --
        # so the cost was never the term or the indexes, only the *presence* of the
        # disjunct. If someone adds a sixth match kind here, check EXPLAIN still shows
        # BitmapOr rather than trusting the clock; a warm cache hides this completely.
        #
        # Each branch takes its own top-:limit, then the union is re-sorted and cut to
        # :limit again. That is exact, not an approximation -- the true top-N of a union
        # can only come from the top-N of each side.
        #
        # UNION rather than UNION ALL: a star matching both a real and a fictional name
        # (Wolf 359 is both, in world 1) produces identical rows on both sides, and the
        # dedup collapses them. That preserves the no-duplicate-rows property the scalar
        # subquery below was written for, which
        # test_star_matching_both_a_real_and_a_fictional_name_returns_one_row pins.
        # Each branch is wrapped as a derived table rather than parenthesised inline:
        # SQLite (which the API tests run on) rejects ORDER BY/LIMIT on an operand of a
        # compound SELECT, while Postgres accepts it. `SELECT * FROM (... LIMIT n) alias`
        # is valid on both, and the tests are only worth having if they run the same SQL
        # the server does.
        query = text("""
            SELECT * FROM (
            SELECT * FROM (SELECT
                id, proper, bayer, flam, con, spect, absmag, x, y, z,
                hip, hd, hr, gj, cns5, gaia, tyc, dist, mag,
                -- Scalar subquery rather than a LEFT JOIN: a join on fic multiplies the
                -- row if a star ever gains two names in one world, which would put the
                -- same star in the result list twice. Nothing does that today (191 rows)
                -- but FICTIONAL-UNIVERSES will, so the shape that cannot duplicate is the
                -- one to write. The DESC ordering prefers the name the user actually
                -- matched on, falling back to a stable f.id order, so searching a
                -- fictional name shows that name rather than a sibling.
                (SELECT f.name FROM fic f
                  WHERE f.star_id = athyg.id AND f.world_id = :world_id
                  ORDER BY (LOWER(f.name) LIKE :pattern ESCAPE '\\') DESC, f.id
                  LIMIT 1) AS name
            FROM athyg
            -- Exclude stars whose coordinates are beyond the domain this API can express.
            -- Returning them produced results that could not be opened -- selecting one
            -- drove the view outside MAX_COORDINATE_VALUE and the PHP page answered 503.
            -- They also sort FIRST, because a broken parallax yields an absurdly bright
            -- absolute magnitude, so they crowded out real answers (DATA-QUALITY-OUTLIERS).
            -- 1,222 rows, measured.
            --
            -- Stars with NO position at all used to be excluded here too. They are not any
            -- more: the maintainer's decision (NULL-COORDINATES, 2026-07-30) is that the
            -- 25,342 stars with no usable parallax stay findable but inert -- a HIP number
            -- that exists should not report "not found". Both frontends are responsible for
            -- rendering them without a click-through to a map they cannot appear on.
            --
            -- This is cheap precisely because they differ from the class above: they have
            -- no absmag at all, so `ORDER BY absmag ASC NULLS LAST` puts every one of them
            -- last and they can never displace a real result inside a limit. The catalog-ID
            -- branches above have always returned them, so this also makes the two halves
            -- of this endpoint agree, which audit-api filed as a defect on 2026-07-31.
            WHERE (
                x IS NULL
                OR (abs(x) <= :max_coord AND abs(y) <= :max_coord AND abs(z) <= :max_coord)
              )
              AND (
                LOWER(COALESCE(proper, '')) LIKE :pattern ESCAPE '\\'
               OR LOWER(COALESCE(bayer, '') || ' ' || COALESCE(con, '')) LIKE :bayer_pattern ESCAPE '\\'
               OR LOWER(COALESCE(flam, '') || ' ' || COALESCE(con, '')) LIKE :pattern ESCAPE '\\'
               OR LOWER(COALESCE(con, '')) LIKE :pattern ESCAPE '\\'
              )
            ORDER BY absmag ASC NULLS LAST
            LIMIT :limit) real_name_matches

            UNION

            -- Fictional names, scoped to the selected world. `fic` is 191 rows, so this
            -- half costs nothing whatever shape it takes -- the point of separating it is
            -- entirely to keep it out of the real-name WHERE, where it destroyed the plan.
            --
            -- The position guard is repeated rather than hoisted: it has to apply to
            -- fictional matches too, or DATA-QUALITY-OUTLIERS' 503 returns through this
            -- branch. test_fictional_name_does_not_resurrect_an_out_of_domain_star pins it.
            --
            -- world_id=0 matches no fic row (ids start at 1), which is what makes "no
            -- universe selected" cost nothing here without a special case.
            SELECT * FROM (SELECT
                id, proper, bayer, flam, con, spect, absmag, x, y, z,
                hip, hd, hr, gj, cns5, gaia, tyc, dist, mag,
                (SELECT f.name FROM fic f
                  WHERE f.star_id = athyg.id AND f.world_id = :world_id
                  ORDER BY (LOWER(f.name) LIKE :pattern ESCAPE '\\') DESC, f.id
                  LIMIT 1) AS name
            FROM athyg
            WHERE (
                x IS NULL
                OR (abs(x) <= :max_coord AND abs(y) <= :max_coord AND abs(z) <= :max_coord)
              )
              AND id IN (SELECT f.star_id FROM fic f
                          WHERE f.world_id = :world_id
                            AND LOWER(f.name) LIKE :pattern ESCAPE '\\')
            ORDER BY absmag ASC NULLS LAST
            LIMIT :limit) fictional_name_matches
            ) u
            ORDER BY absmag ASC NULLS LAST
            LIMIT :limit
        """)
        result = await db.execute(
            query,
            {
                "pattern": like_pattern,
                "bayer_pattern": bayer_pattern,
                "limit": limit,
                "max_coord": MAX_COORDINATE_VALUE,
                "world_id": world_id,
            },
        )

    rows = result.mappings().all()
    stars = [StarBase(**{k: v for k, v in row.items() if k in StarBase.model_fields}) for row in rows]

    return StarListResponse(
        result="success",
        data=stars,
        length=len(stars),
    )


@router.get("/proper-names", response_model=ProperNamesResponse)
@limiter.limit(settings.RATE_LIMIT)
async def get_proper_names(
    request: Request,
    db: AsyncSession = Depends(get_db),
):
    """
    Get all stars with proper names for dropdown selection.
    Returns id and proper name, ordered alphabetically by name.
    """
    query = text("""
        SELECT id, proper
        FROM athyg
        WHERE proper IS NOT NULL
        ORDER BY proper
    """)

    result = await db.execute(query)
    rows = result.mappings().all()
    names = [ProperName(**row) for row in rows]

    return ProperNamesResponse(
        result="success",
        data=names,
        length=len(names),
    )


@router.get("/fictional-names", response_model=FictionalNamesResponse)
@limiter.limit(settings.RATE_LIMIT)
async def get_fictional_names(
    request: Request,
    world_id: int = Query(..., ge=1, le=PG_INT_MAX, description="Fictional world ID to filter by"),
    db: AsyncSession = Depends(get_db),
):
    """
    Get all fictional star names for a specific world/universe.
    Returns star_id and name, ordered alphabetically by name.
    """
    query = text("""
        SELECT star_id, name
        FROM fic
        WHERE world_id = :world_id
        ORDER BY name
    """)

    result = await db.execute(query, {"world_id": world_id})
    rows = result.mappings().all()
    names = [FictionalName(**row) for row in rows]

    return FictionalNamesResponse(
        result="success",
        data=names,
        length=len(names),
    )


@router.get("/worlds", response_model=WorldsResponse)
@limiter.limit(settings.RATE_LIMIT)
async def get_worlds(
    request: Request,
    db: AsyncSession = Depends(get_db),
):
    """
    Get all fictional worlds/universes available.
    Returns id and name, ordered by id.
    """
    query = text("""
        SELECT id, name
        FROM fic_worlds
        ORDER BY id
    """)

    result = await db.execute(query)
    rows = result.mappings().all()
    worlds = [World(**row) for row in rows]

    return WorldsResponse(
        result="success",
        data=worlds,
        length=len(worlds),
    )


@router.get("/legacy/{v3_id}", response_model=LegacyStarResponse)
@limiter.limit(settings.RATE_LIMIT)
async def get_star_by_legacy_id(
    request: Request,  # Required for rate limiter
    v3_id: int = Path(..., ge=1, le=PG_INT_MAX),
    world_id: int = Query(0, ge=0, le=PG_INT_MAX, description="Fictional world ID for fictional name (0 = no fictional name)"),
    db: AsyncSession = Depends(get_db),
):
    """
    Resolve an AT-HYG v3.3 star id to the star it names in the current catalog.

    The AT-HYG 4 migration renumbered every star, so links saved before it point at a
    different object and do so silently -- 99.99% of v3 ids are also a valid, *different*
    v4 id, so the wrong star loads with no error. This endpoint answers only "what did
    this id used to mean"; whether a given incoming id should be read as legacy is not
    knowable here and is left to the caller.

    Returns 404 when no v3 star maps to the current catalog under that id -- either the id
    never existed in v3.3, or its star did not survive to v4 (22 such), or its identifier
    is shared by two real binary components and the matcher refused to guess.
    """
    query = text("""
        SELECT
            a.id, a.proper, a.bayer, a.flam, a.con, a.spect, a.absmag,
            a.x, a.y, a.z, a.hyg, a.hip, a.hd, a.hr, a.gj, a.cns5,
            a.tyc, a.gaia, a.ra, a.dec, a.dist, a.mag,
            COALESCE(f.name, '') AS name,
            v.match_method
        FROM athyg_v3_ids v
        JOIN athyg a ON a.id = v.athyg_id
        LEFT JOIN fic f ON a.id = f.star_id AND f.world_id = :world_id
        WHERE v.v3_id = :v3_id
    """)

    result = await db.execute(query, {"v3_id": v3_id, "world_id": world_id})
    row = result.mappings().first()

    if not row:
        raise HTTPException(status_code=404, detail="No star found for that legacy ID")

    star_fields = {k: v for k, v in row.items() if k != "match_method"}

    return LegacyStarResponse(
        result="success",
        v3_id=v3_id,
        match_method=row["match_method"],
        data=StarDetail(**star_fields),
    )


@router.get("/{star_id}", response_model=StarDetailResponse)
@limiter.limit(settings.RATE_LIMIT)
async def get_star_by_id(
    request: Request,  # Required for rate limiter
    star_id: int = Path(..., ge=1, le=PG_INT_MAX),
    world_id: int = Query(0, ge=0, le=PG_INT_MAX, description="Fictional world ID for fictional name (0 = no fictional name)"),
    db: AsyncSession = Depends(get_db),
):
    """
    Get detailed information for a specific star by its database ID.
    Optional world_id parameter to include fictional name from the fic table.
    """
    query = text("""
        SELECT
            a.id,
            a.proper,
            a.bayer,
            a.flam,
            a.con,
            a.spect,
            a.absmag,
            a.x,
            a.y,
            a.z,
            a.hyg,
            a.hip,
            a.hd,
            a.hr,
            a.gj,
            a.cns5,
            a.tyc,
            a.gaia,
            a.ra,
            a.dec,
            a.dist,
            a.mag,
            COALESCE(f.name, '') AS name
        FROM athyg a
        LEFT JOIN fic f ON a.id = f.star_id AND f.world_id = :world_id
        WHERE a.id = :star_id
    """)

    result = await db.execute(query, {"star_id": star_id, "world_id": world_id})
    row = result.mappings().first()

    if not row:
        raise HTTPException(status_code=404, detail="Star not found")

    star = StarDetail(**row)

    return StarDetailResponse(
        result="success",
        data=star,
    )
