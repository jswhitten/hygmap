"""
Star API endpoints
"""
from fastapi import APIRouter, Depends, Query, HTTPException, Request
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import text
from slowapi import Limiter
from slowapi.util import get_remote_address
from app.database import get_db
from app.schemas import StarListResponse, StarDetailResponse, StarBase, StarDetail
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
    limit: int = Query(10000, le=50000, description="Maximum number of stars to return"),
    db: AsyncSession = Depends(get_db),
):
    """
    Get stars within specified 3D spatial bounds.

    Returns stars ordered by absolute magnitude (brightest first).
    Uses the athyg table with galactic coordinates.
    Optional mag_max parameter for LOD - only return stars brighter than this magnitude.
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
    # Build query with optional magnitude filter
    mag_filter = "AND absmag < :mag_max" if mag_max is not None else ""
    query = text(f"""
        SELECT
            id,
            proper,
            bayer,
            flam,
            con,
            spect,
            absmag,
            x,
            y,
            z,
            hip,
            hd,
            hr,
            gj,
            gaia,
            tyc
        FROM athyg
        WHERE x > :xmin AND x < :xmax
          AND y > :ymin AND y < :ymax
          AND z > :zmin AND z < :zmax
          {mag_filter}
        ORDER BY absmag ASC NULLS LAST
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
    q: str = Query(..., min_length=2, max_length=100, description="Search query (name or catalog ID)"),
    limit: int = Query(20, le=100, description="Maximum number of results"),
    db: AsyncSession = Depends(get_db),
):
    """
    Search for stars by name or catalog ID.

    Searches proper names, Bayer/Flamsteed designations, and catalog IDs
    (HIP, HD, HR, GJ, Gaia, TYC).
    """
    search_term = q.strip()

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
                       hip, hd, hr, gj, gaia, tyc
                FROM athyg WHERE hip = :catalog_value LIMIT :limit
            """),
            'hd': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, gaia, tyc
                FROM athyg WHERE hd = :catalog_value LIMIT :limit
            """),
            'hr': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, gaia, tyc
                FROM athyg WHERE hr = :catalog_value LIMIT :limit
            """),
            'gj': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, gaia, tyc
                FROM athyg WHERE gj = :catalog_value LIMIT :limit
            """),
            'gaia': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, gaia, tyc
                FROM athyg WHERE gaia = :catalog_value LIMIT :limit
            """),
            'tyc': text("""
                SELECT id, proper, bayer, flam, con, spect, absmag, x, y, z,
                       hip, hd, hr, gj, gaia, tyc
                FROM athyg WHERE tyc = :catalog_value LIMIT :limit
            """),
        }

        query = CATALOG_QUERIES.get(catalog_field)
        if not query:
            raise HTTPException(
                status_code=400,
                detail=f"Invalid catalog field: {catalog_field}"
            )

        result = await db.execute(query, {"catalog_value": catalog_value, "limit": limit})
    else:
        # Search by name (proper, bayer, constellation)
        # Use LOWER() for case-insensitive search (works with both PostgreSQL and SQLite)
        like_pattern = f"%{search_term.lower()}%"
        query = text("""
            SELECT
                id, proper, bayer, flam, con, spect, absmag, x, y, z,
                hip, hd, hr, gj, gaia, tyc
            FROM athyg
            WHERE LOWER(COALESCE(proper, '')) LIKE :pattern
               OR LOWER(COALESCE(bayer, '') || ' ' || COALESCE(con, '')) LIKE :pattern
               OR LOWER(COALESCE(flam, '') || ' ' || COALESCE(con, '')) LIKE :pattern
               OR LOWER(COALESCE(con, '')) LIKE :pattern
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


@router.get("/{star_id}", response_model=StarDetailResponse)
@limiter.limit(settings.RATE_LIMIT)
async def get_star_by_id(
    request: Request,  # Required for rate limiter
    star_id: int,
    db: AsyncSession = Depends(get_db),
):
    """
    Get detailed information for a specific star by its database ID.
    """
    query = text("""
        SELECT
            id,
            proper,
            bayer,
            flam,
            con,
            spect,
            absmag,
            x,
            y,
            z,
            hyg,
            hip,
            hd,
            hr,
            gj,
            tyc,
            gaia,
            ra,
            dec,
            dist,
            mag
        FROM athyg
        WHERE id = :star_id
    """)

    result = await db.execute(query, {"star_id": star_id})
    row = result.mappings().first()

    if not row:
        raise HTTPException(status_code=404, detail="Star not found")

    star = StarDetail(**row)

    return StarDetailResponse(
        result="success",
        data=star,
    )
