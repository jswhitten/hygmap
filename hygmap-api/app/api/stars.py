"""
Star API endpoints
"""
from fastapi import APIRouter, Depends, Query, HTTPException, Request
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import text
from slowapi import Limiter
from slowapi.util import get_remote_address
from app.database import get_db
from app.schemas import (
    StarListResponse,
    StarDetailResponse,
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

# Rate limiter instance (uses settings from app.config)
limiter = Limiter(key_func=get_remote_address, enabled=settings.RATE_LIMIT_ENABLED)

# Maximum allowed spatial range per dimension (parsecs)
# Set to 3000 to accommodate distant stars in the AT-HYG catalog
MAX_SPATIAL_RANGE = 3000.0

# Maximum absolute coordinate value (parsecs)
# AT-HYG catalog typically contains stars within ~10,000 parsecs
MAX_COORDINATE_VALUE = 10000.0

# Allowlist for ORDER BY clause to prevent SQL injection
ORDER_CLAUSES = {
    "absmag": "a.absmag ASC NULLS LAST",
    "absmag asc": "a.absmag ASC NULLS LAST",
    "absmag desc": "a.absmag DESC NULLS LAST",
    "mag": "a.mag ASC NULLS LAST",
    "mag asc": "a.mag ASC NULLS LAST",
    "mag desc": "a.mag DESC NULLS LAST",
    "proper": "a.proper ASC NULLS LAST",
    "proper asc": "a.proper ASC NULLS LAST",
    "proper desc": "a.proper DESC NULLS LAST",
    "dist": "a.dist ASC NULLS LAST",
    "dist asc": "a.dist ASC NULLS LAST",
    "dist desc": "a.dist DESC NULLS LAST",
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
    world_id: int = Query(0, ge=0, description="Fictional world ID for fictional names (0 = no fictional names)"),
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
    db: AsyncSession = Depends(get_db),
):
    """
    Search for stars by name or catalog ID.

    Searches proper names, Bayer/Flamsteed designations, and catalog IDs
    (HIP, HD, HR, GJ, Gaia, TYC).
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
        # Each query is explicit to prevent any possibility of SQL injection
        CATALOG_QUERIES = {
            'hip': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, cns5, gaia, tyc
                FROM athyg WHERE hip = :catalog_value LIMIT :limit
            """),
            'hd': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, cns5, gaia, tyc
                FROM athyg WHERE hd = :catalog_value LIMIT :limit
            """),
            'hr': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, cns5, gaia, tyc
                FROM athyg WHERE hr = :catalog_value LIMIT :limit
            """),
            'gj': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, cns5, gaia, tyc
                FROM athyg WHERE gj = :catalog_value LIMIT :limit
            """),
            'cns5': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, cns5, gaia, tyc
                FROM athyg WHERE cns5 = :catalog_value LIMIT :limit
            """),
            'gaia': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, cns5, gaia, tyc
                FROM athyg WHERE gaia = :catalog_value LIMIT :limit
            """),
            'tyc': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, cns5, gaia, tyc
                FROM athyg WHERE tyc = :catalog_value LIMIT :limit
            """),
        }

        query = CATALOG_QUERIES.get(catalog_field)
        if query is None:
            raise HTTPException(
                status_code=400,
                detail=f"Invalid catalog field: {catalog_field}"
            )

        result = await db.execute(query, {"catalog_value": catalog_value, "limit": limit})
    else:
        # Search by name (proper, bayer, constellation)
        # Use LOWER() for case-insensitive search (works with both PostgreSQL and SQLite)
        escaped = (search_term.lower()
                   .replace("\\", "\\\\")
                   .replace("%", "\\%")
                   .replace("_", "\\_"))
        like_pattern = f"%{escaped}%"
        query = text("""
            SELECT
                id, proper, bayer, flam, con, spect, absmag, x, y, z,
                hip, hd, hr, gj, cns5, gaia, tyc
            FROM athyg
            WHERE LOWER(COALESCE(proper, '')) LIKE :pattern ESCAPE '\\'
               OR LOWER(COALESCE(bayer, '') || ' ' || COALESCE(con, '')) LIKE :pattern ESCAPE '\\'
               OR LOWER(COALESCE(flam, '') || ' ' || COALESCE(con, '')) LIKE :pattern ESCAPE '\\'
               OR LOWER(COALESCE(con, '')) LIKE :pattern ESCAPE '\\'
            ORDER BY absmag ASC NULLS LAST
            LIMIT :limit
        """)
        result = await db.execute(query, {"pattern": like_pattern, "limit": limit})

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
    world_id: int = Query(..., ge=1, description="Fictional world ID to filter by"),
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


@router.get("/{star_id}", response_model=StarDetailResponse)
@limiter.limit(settings.RATE_LIMIT)
async def get_star_by_id(
    request: Request,  # Required for rate limiter
    star_id: int,
    world_id: int = Query(0, ge=0, description="Fictional world ID for fictional name (0 = no fictional name)"),
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
