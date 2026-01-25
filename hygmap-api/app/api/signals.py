"""Signal API endpoints"""
from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException, Query, Request
from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncSession
from slowapi import Limiter
from slowapi.util import get_remote_address

from app.config import settings
from app.database import get_db
from app.schemas import Signal, SignalListResponse

router = APIRouter()
limiter = Limiter(key_func=get_remote_address, enabled=settings.RATE_LIMIT_ENABLED)

# Spatial validation constants (parsecs)
MAX_COORDINATE_VALUE = 20000.0
MAX_SPATIAL_RANGE = 6000.0
MAX_LIMIT = 5000
DEFAULT_LIMIT = 1000

# Allowlist for ORDER BY clause to prevent SQL injection
ORDER_CLAUSES = {
    "time": "time ASC",
    "time asc": "time ASC",
    "time desc": "time DESC",
    "name": "name ASC NULLS LAST",
    "name asc": "name ASC NULLS LAST",
    "name desc": "name DESC NULLS LAST",
    "frequency": "frequency ASC NULLS LAST",
    "frequency asc": "frequency ASC NULLS LAST",
    "frequency desc": "frequency DESC NULLS LAST",
}
DEFAULT_ORDER = "time desc"


@router.get("/", response_model=SignalListResponse)
@limiter.limit(settings.RATE_LIMIT)
async def get_signals(
    request: Request,
    xmin: float = Query(-150.0, description="Minimum X coordinate (parsecs)"),
    xmax: float = Query(150.0, description="Maximum X coordinate (parsecs)"),
    ymin: float = Query(-150.0, description="Minimum Y coordinate (parsecs)"),
    ymax: float = Query(150.0, description="Maximum Y coordinate (parsecs)"),
    zmin: float = Query(-150.0, description="Minimum Z coordinate (parsecs)"),
    zmax: float = Query(150.0, description="Maximum Z coordinate (parsecs)"),
    order: str = Query(DEFAULT_ORDER, description="Sort order (time/name/frequency asc|desc)"),
    limit: int = Query(DEFAULT_LIMIT, ge=1, le=MAX_LIMIT, description="Maximum signals to return"),
    signal_type: str | None = Query(
        None,
        pattern="^(transmit|receive)$",
        description="Optional signal type filter",
    ),
    db: AsyncSession = Depends(get_db),
):
    """Fetch signals that fall within a 3D bounding box."""

    # Validate coordinates within absolute bounds
    coordinates = [xmin, xmax, ymin, ymax, zmin, zmax]
    if any(abs(coord) > MAX_COORDINATE_VALUE for coord in coordinates):
        raise HTTPException(
            status_code=400,
            detail=f"Coordinate values must be within ±{MAX_COORDINATE_VALUE} parsecs",
        )

    # Ensure bounds are ordered correctly
    if xmin >= xmax or ymin >= ymax or zmin >= zmax:
        raise HTTPException(status_code=400, detail="Invalid bounds: min must be less than max")

    # Prevent excessively large query regions
    if (
        (xmax - xmin) > MAX_SPATIAL_RANGE
        or (ymax - ymin) > MAX_SPATIAL_RANGE
        or (zmax - zmin) > MAX_SPATIAL_RANGE
    ):
        raise HTTPException(
            status_code=400,
            detail=f"Spatial range too large: maximum {MAX_SPATIAL_RANGE} parsecs per dimension",
        )

    # Validate order against allowlist to avoid SQL injection
    order_clause = ORDER_CLAUSES.get(order.strip().lower())
    if not order_clause:
        raise HTTPException(
            status_code=400,
            detail="Invalid order parameter. Allowed values: time, name, frequency (asc/desc)",
        )

    type_filter = ""
    params: dict[str, float | int | str] = {
        "xmin": xmin,
        "xmax": xmax,
        "ymin": ymin,
        "ymax": ymax,
        "zmin": zmin,
        "zmax": zmax,
        "limit": limit,
    }

    if signal_type:
        type_filter = "AND type = :signal_type"
        params["signal_type"] = signal_type

    query = text(
        f"""
        SELECT
            id,
            name,
            type,
            time,
            ra,
            dec,
            frequency,
            notes,
            x,
            y,
            z,
            last_updated
        FROM signals
        WHERE x > :xmin AND x < :xmax
          AND y > :ymin AND y < :ymax
          AND z > :zmin AND z < :zmax
          {type_filter}
        ORDER BY {order_clause}
        LIMIT :limit
    """
    )

    result = await db.execute(query, params)
    rows = result.mappings().all()
    signals = [Signal(**row) for row in rows]

    return SignalListResponse(result="success", data=signals, length=len(signals))
