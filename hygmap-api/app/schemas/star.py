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
    # Optional because a star with no usable parallax has no 3D position. The import
    # clears dist/absmag/x/y/z for those (see the sentinel note in
    # db/sql/03_import_data.sql) rather than placing them at a fabricated 100 kpc.
    # List and search results exclude them, since they cannot be drawn; a direct
    # /stars/{id} request still returns them, with nulls, rather than failing.
    x: Optional[float] = None
    y: Optional[float] = None
    z: Optional[float] = None
    # Catalog IDs for display_name fallback
    hip: Optional[str] = None
    hd: Optional[str] = None
    hr: Optional[str] = None
    gj: Optional[str] = None
    cns5: Optional[str] = None
    gaia: Optional[str] = None
    tyc: Optional[str] = None
    # Fictional name (populated when world_id is provided)
    name: Optional[str] = None

    @computed_field
    @property
    def display_name(self) -> str:
        """
        The star's display name.

        This is the ONE implementation of this rule. It previously existed four times
        (here, on StarDetail, in PHP's StarFormatter, and in React's getStarDisplayName)
        and the copies disagreed: a star with both a GJ and a HIP number was called
        "GJ 1" by PHP and "HIP 439" here. See tests/fixtures/display-names.json, which
        every suite asserts against.

        Canonical order, decided 2026-07-29:

            fictional name (only when a world is selected)
            proper
            bayer + con      (both required, trimmed)
            flam + con       (both required, trimmed)
            GJ               Gliese-Jahreiss leads because this is a map of the solar
            HD               neighbourhood, so the nearby-star catalog is the most
            HIP              useful identifier to show. This used to lead with HIP.
            HR
            CNS5
            TYC
            Gaia
            spect            informative for an anonymous star
            ID <id>          last resort, so the name is never empty
        """
        # The fictional name is only populated when the query passed a world_id, so its
        # presence is the signal. Without this branch the API could not express the name
        # PHP needed, which is why PHP reimplemented the whole chain.
        if self.name:
            return self.name
        if self.proper:
            return self.proper
        # Source data carries padding on bayer/flam; trim so the three stacks agree.
        if self.bayer and self.con:
            return f"{self.bayer.strip()} {self.con.strip()}"
        if self.flam and self.con:
            return f"{self.flam.strip()} {self.con.strip()}"
        if self.gj:
            return f"GJ {self.gj}"
        if self.hd:
            return f"HD {self.hd}"
        if self.hip:
            return f"HIP {self.hip}"
        if self.hr:
            return f"HR {self.hr}"
        if self.cns5:
            return f"CNS5 {self.cns5}"
        if self.tyc:
            return f"TYC {self.tyc}"
        if self.gaia:
            return f"Gaia {self.gaia}"
        if self.spect:
            return self.spect
        return f"ID {self.id}"


class StarDetail(StarBase):
    """Detailed star data with all fields"""
    hyg: Optional[int] = None
    hip: Optional[str] = None
    hd: Optional[str] = None
    hr: Optional[str] = None
    gj: Optional[str] = None
    cns5: Optional[str] = None
    tyc: Optional[str] = None
    gaia: Optional[str] = None
    ra: Optional[float] = None
    dec: Optional[float] = None
    dist: Optional[float] = None
    mag: Optional[float] = None

    # display_name is inherited from StarBase deliberately. It used to be a second
    # hand-written copy of the chain, and the copies had already drifted -- StarBase
    # checked hr and this one did not, so an HR-only star was "HR 2491" in a list row
    # and "ID 7" in a detail row.


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
