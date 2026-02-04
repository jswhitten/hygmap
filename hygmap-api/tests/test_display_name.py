"""
Tests for star display name logic
"""
from app.schemas.star import StarBase, StarDetail

class TestStarDisplayName:
    def test_star_base_priority(self):
        # Base star with just ID
        s = StarBase(id=1, x=0, y=0, z=0)
        assert s.display_name == "ID 1"

        # With Gaia
        s = StarBase(id=1, x=0, y=0, z=0, gaia="12345")
        assert s.display_name == "Gaia 12345"

        # With TYC (should override Gaia)
        s = StarBase(id=1, x=0, y=0, z=0, gaia="12345", tyc="6789")
        assert s.display_name == "TYC 6789"

        # With CNS5 (should override TYC)
        s = StarBase(id=1, x=0, y=0, z=0, gaia="12345", tyc="6789", cns5="5500")
        assert s.display_name == "CNS5 5500"

    def test_star_detail_priority(self):
        # Detail star with just ID
        s = StarDetail(id=1, x=0, y=0, z=0)
        assert s.display_name == "ID 1"

        # With Gaia
        s = StarDetail(id=1, x=0, y=0, z=0, gaia="12345")
        assert s.display_name == "Gaia 12345"

        # With TYC (should override Gaia)
        s = StarDetail(id=1, x=0, y=0, z=0, gaia="12345", tyc="6789")
        assert s.display_name == "TYC 6789"

        # With CNS5 (should override TYC)
        s = StarDetail(id=1, x=0, y=0, z=0, gaia="12345", tyc="6789", cns5="5500")
        assert s.display_name == "CNS5 5500"
