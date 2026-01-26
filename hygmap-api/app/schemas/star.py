"""
Pydantic schemas for star data from the athyg table
"""
from pydantic import BaseModel, Field, computed_field
from typing import Optional


class StarBase(BaseModel):
    """Base star data returned in list queries"""
    id: int
    proper: Optional[str] = None
    bayer: Optional[str] = None
    flam: Optional[str] = None
    con: Optional[str] = None
    spect: Optional[str] = None
    absmag: Optional[float] = None
    mag: Optional[float] = None
    dist: Optional[float] = None
    x: float
    y: float
    z: float
    # Catalog IDs for display_name fallback
    hip: Optional[str] = None
    hd: Optional[str] = None
    hr: Optional[str] = None
    gj: Optional[str] = None
    gaia: Optional[str] = None
    tyc: Optional[str] = None
    # Fictional name (populated when world_id is provided)
    name: Optional[str] = None

    @computed_field
    @property
    def display_name(self) -> str:
        """
        Generate display name with priority:
        1. Proper name (e.g., "Vega")
        2. Bayer designation + constellation (e.g., "Alp Lyr")
        3. Flamsteed designation + constellation (e.g., "51 Peg")
        4. HIP catalog number
        5. HD catalog number
        6. Other catalog IDs
        7. Database ID
        """
        if self.proper:
            return self.proper
        if self.bayer and self.con:
            return f"{self.bayer} {self.con}"
        if self.flam and self.con:
            return f"{self.flam} {self.con}"
        if self.hip:
            return f"HIP {self.hip}"
        if self.hd:
            return f"HD {self.hd}"
        if self.hr:
            return f"HR {self.hr}"
        if self.gj:
            return f"GJ {self.gj}"
        if self.gaia:
            return f"Gaia {self.gaia}"
        if self.tyc:
            return f"TYC {self.tyc}"
        return f"ID {self.id}"


class StarDetail(StarBase):
    """Detailed star data with all fields"""
    hyg: Optional[int] = None
    hip: Optional[str] = None
    hd: Optional[str] = None
    hr: Optional[str] = None
    gj: Optional[str] = None
    tyc: Optional[str] = None
    gaia: Optional[str] = None
    ra: Optional[float] = None
    dec: Optional[float] = None
    dist: Optional[float] = None
    mag: Optional[float] = None

    @computed_field
    @property
    def display_name(self) -> str:
        """
        Generate display name with full priority order:
        1. Proper name (e.g., "Vega")
        2. Bayer designation + constellation (e.g., "Alp Lyr")
        3. Flamsteed designation + constellation (e.g., "51 Peg")
        4. HIP catalog number
        5. HD catalog number
        6. Gliese catalog number
        7. Database ID
        """
        if self.proper:
            return self.proper
        if self.bayer and self.con:
            return f"{self.bayer} {self.con}"
        if self.flam and self.con:
            return f"{self.flam} {self.con}"
        if self.hip:
            return f"HIP {self.hip}"
        if self.hd:
            return f"HD {self.hd}"
        if self.gj:
            return f"GJ {self.gj}"
        return f"ID {self.id}"


class StarListResponse(BaseModel):
    """Response for star list queries"""
    result: str = "success"
    data: list[StarBase]
    length: int


class StarDetailResponse(BaseModel):
    """Response for individual star queries"""
    result: str = "success"
    data: Optional[StarDetail] = None


class ProperName(BaseModel):
    """Star with proper name for dropdown"""
    id: int
    proper: str


class ProperNamesResponse(BaseModel):
    """Response for proper names list"""
    result: str = "success"
    data: list[ProperName]
    length: int


class FictionalName(BaseModel):
    """Fictional star name for dropdown"""
    star_id: int
    name: str


class FictionalNamesResponse(BaseModel):
    """Response for fictional names list"""
    result: str = "success"
    data: list[FictionalName]
    length: int


class World(BaseModel):
    """Fictional world/universe"""
    id: int
    name: str


class WorldsResponse(BaseModel):
    """Response for worlds list"""
    result: str = "success"
    data: list[World]
    length: int
