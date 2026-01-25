"""
Pydantic schemas for SETI signal data
"""
from __future__ import annotations

from datetime import datetime
from typing import Literal, Optional
from pydantic import BaseModel, Field, computed_field

SignalType = Literal["transmit", "receive"]


class Signal(BaseModel):
    """Signal row returned from the signals table"""

    id: int
    name: Optional[str] = None
    type: SignalType
    time: Optional[datetime] = None
    ra: Optional[float] = None
    dec: Optional[float] = None
    frequency: Optional[float] = None
    notes: Optional[str] = None
    x: float = Field(..., description="Galactic X coordinate in parsecs")
    y: float = Field(..., description="Galactic Y coordinate in parsecs")
    z: float = Field(..., description="Galactic Z coordinate in parsecs")
    last_updated: Optional[datetime] = None

    @computed_field
    @property
    def display_name(self) -> str:
        """Fallback display name if the signal lacks a label"""
        return self.name or f"Signal {self.id}"


class SignalListResponse(BaseModel):
    """Response payload for signal list queries"""

    result: str = "success"
    data: list[Signal]
    length: int
