"""Pydantic schemas package"""
from app.schemas.star import (
    StarBase,
    StarDetail,
    StarListResponse,
    StarDetailResponse,
    ProperName,
    ProperNamesResponse,
    FictionalName,
    FictionalNamesResponse,
    World,
    WorldsResponse,
)
from app.schemas.signal import Signal, SignalListResponse

__all__ = [
    "StarBase",
    "StarDetail",
    "StarListResponse",
    "StarDetailResponse",
    "ProperName",
    "ProperNamesResponse",
    "FictionalName",
    "FictionalNamesResponse",
    "World",
    "WorldsResponse",
    "Signal",
    "SignalListResponse",
]
