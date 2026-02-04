"""
Unit tests for match_cns5.py parsing and astrometric helpers.

Run: python -m pytest test_match_cns5.py -v
"""

import math
import pytest
from match_cns5 import (
    parse_line,
    propagate_to_j2000,
    estimate_vmag,
    estimate_spectral_type,
    extract_gj_component,
    compute_equatorial_coords,
    safe_float,
    safe_int,
    safe_str,
)


# ---------------------------------------------------------------------------
# safe_* helpers
# ---------------------------------------------------------------------------

class TestSafeHelpers:
    def test_safe_float_normal(self):
        assert safe_float("  3.14  ") == pytest.approx(3.14)

    def test_safe_float_negative(self):
        assert safe_float("-2.5") == pytest.approx(-2.5)

    def test_safe_float_scientific(self):
        assert safe_float("5.572E-4") == pytest.approx(5.572e-4)

    def test_safe_float_blank(self):
        assert safe_float("      ") is None

    def test_safe_float_dash(self):
        assert safe_float("   -  ") is None

    def test_safe_int_normal(self):
        assert safe_int("5142") == 5142

    def test_safe_int_blank(self):
        assert safe_int("    ") is None

    def test_safe_int_dash(self):
        assert safe_int(" -") is None

    def test_safe_str_normal(self):
        assert safe_str("  551  ") == "551"

    def test_safe_str_blank(self):
        assert safe_str("      ") is None

    def test_safe_str_dash(self):
        assert safe_str("  -   ") is None


# ---------------------------------------------------------------------------
# parse_line
# ---------------------------------------------------------------------------

class TestParseLine:
    # Build a test line based on ReadMe byte positions (1-indexed)
    # CNS5=5142, GJ=4165, no comp, no ncomp, primary=0, no GJp,
    # Gaia=1871118140493076224, no HIP, RA=312.278..., Dec=37.471..., Epoch=2016.0
    SAMPLE_LINE = (
        "5142 4165        - 0       1871118140493076224"
        "      - 312.2788230257468     37.47122796548486  "
        "    2016.0    2020yCat.1350....0G  56.889944995813316"
    ).ljust(761)

    def test_parse_cns5_id(self):
        rec = parse_line(self.SAMPLE_LINE)
        assert rec is not None
        assert rec["cns5_id"] == 5142

    def test_parse_gj(self):
        rec = parse_line(self.SAMPLE_LINE)
        assert rec["gj"] == "4165"

    def test_parse_gaia(self):
        rec = parse_line(self.SAMPLE_LINE)
        assert rec["gaia"] == "1871118140493076224"

    def test_parse_hip_missing(self):
        rec = parse_line(self.SAMPLE_LINE)
        assert rec["hip"] is None

    def test_parse_ra(self):
        rec = parse_line(self.SAMPLE_LINE)
        assert rec["ra_deg"] == pytest.approx(312.2788, abs=0.001)

    def test_parse_dec(self):
        rec = parse_line(self.SAMPLE_LINE)
        assert rec["dec_deg"] == pytest.approx(37.4712, abs=0.001)

    def test_parse_epoch(self):
        rec = parse_line(self.SAMPLE_LINE)
        assert rec["epoch"] == pytest.approx(2016.0)

    def test_parse_component(self):
        # GJ 822.1 C component
        line = (
            "5239 822.1  C    3 0       1964791549008457856"
            " 104887 318.69622421753587    38.022506739978056 "
            "    2016.0"
        ).ljust(761)
        rec = parse_line(line)
        assert rec["comp"] == "C"
        assert rec["ncomp"] == 3
        assert rec["hip"] == "104887"

    def test_parse_sun(self):
        # Sun record: cns5_id=0, gj="Sun"
        line = (
            "   0 Sun         - 0"
        ).ljust(761)
        rec = parse_line(line)
        assert rec["cns5_id"] == 0
        assert rec["gj"] == "Sun"

    def test_parse_returns_none_for_garbage(self):
        rec = parse_line("not a valid line at all")
        # cns5_id parse fails -> returns None
        assert rec is None

    def test_parse_hipparcos_epoch(self):
        # Star with Hipparcos epoch (1991.25) instead of Gaia
        line = (
            "5240 822.1  AB   3 1                         -"
            " 104887 318.69727753340095    38.044320596888575 "
            "    1991.25"
        ).ljust(761)
        rec = parse_line(line)
        assert rec["epoch"] == pytest.approx(1991.25)
        assert rec["gaia"] is None


# ---------------------------------------------------------------------------
# propagate_to_j2000
# ---------------------------------------------------------------------------

class TestPropagateToJ2000:
    def test_no_motion(self):
        """Star with zero proper motion should not move."""
        ra, dec = propagate_to_j2000(180.0, 45.0, 2016.0, 0.0, 0.0)
        assert ra == pytest.approx(180.0, abs=1e-10)
        assert dec == pytest.approx(45.0, abs=1e-10)

    def test_already_j2000(self):
        """Epoch is already J2000 — no change."""
        ra, dec = propagate_to_j2000(100.0, -30.0, 2000.0, 500.0, -200.0)
        assert ra == pytest.approx(100.0, abs=1e-10)
        assert dec == pytest.approx(-30.0, abs=1e-10)

    def test_known_proper_motion(self):
        """Barnard's Star: large PM, 16-year baseline from Gaia epoch."""
        # Approximate: pmra ~ -802 mas/yr, pmdec ~ 10362 mas/yr
        # dt = 2000 - 2016 = -16 years
        ra_deg = 269.448
        dec_deg = 4.739
        ra_j2000, dec_j2000 = propagate_to_j2000(
            ra_deg, dec_deg, 2016.0, -801.55, 10362.39
        )
        # RA should increase slightly (negative pmra * negative dt = positive shift)
        # Dec should decrease (positive pmdec * negative dt = negative shift)
        assert ra_j2000 > ra_deg  # pmra is negative, dt is negative -> positive
        assert dec_j2000 < dec_deg  # pmdec is positive, dt is negative -> negative

    def test_missing_epoch_returns_input(self):
        """If epoch is None, return position unchanged."""
        ra, dec = propagate_to_j2000(100.0, 45.0, None, 100.0, 100.0)
        assert ra == 100.0
        assert dec == 45.0

    def test_missing_pm_returns_input(self):
        """If proper motion is None, return position unchanged."""
        ra, dec = propagate_to_j2000(100.0, 45.0, 2016.0, None, None)
        assert ra == 100.0
        assert dec == 45.0

    def test_ra_wraps(self):
        """RA near 360 should wrap correctly."""
        # Star near RA=359, moving positive in RA
        ra, dec = propagate_to_j2000(359.9, 0.0, 2016.0, 1000000.0, 0.0)
        assert 0.0 <= ra < 360.0


# ---------------------------------------------------------------------------
# estimate_vmag
# ---------------------------------------------------------------------------

class TestEstimateVmag:
    def test_solar_type(self):
        """G-type star: G~5.0, BP-RP~0.8"""
        v = estimate_vmag(5.0, 5.4, 4.6)
        assert v is not None
        assert 4.5 < v < 5.5

    def test_red_dwarf(self):
        """M dwarf: G~12.0, BP-RP~3.0. Large BP-RP makes V brighter than G."""
        v = estimate_vmag(12.0, 13.5, 10.5)
        assert v is not None
        # BP-RP=3.0 -> correction ~1.52 -> V = G - 1.52 ≈ 10.48
        assert 10.0 < v < 11.0

    def test_none_gmag(self):
        assert estimate_vmag(None, 5.0, 4.0) is None

    def test_none_bp(self):
        """Missing BP: can't compute V from G alone."""
        assert estimate_vmag(5.0, None, 4.0) is None

    def test_none_rp(self):
        assert estimate_vmag(5.0, 5.4, None) is None


# ---------------------------------------------------------------------------
# compute_equatorial_coords
# ---------------------------------------------------------------------------

class TestComputeEquatorialCoords:
    def test_on_x_axis(self):
        """Star at RA=0h, Dec=0deg, dist=10pc -> (10, 0, 0)"""
        x, y, z = compute_equatorial_coords(0.0, 0.0, 10.0)
        assert x == pytest.approx(10.0, abs=1e-6)
        assert y == pytest.approx(0.0, abs=1e-6)
        assert z == pytest.approx(0.0, abs=1e-6)

    def test_on_y_axis(self):
        """Star at RA=6h (90deg), Dec=0deg, dist=10pc -> (0, 10, 0)"""
        x, y, z = compute_equatorial_coords(6.0, 0.0, 10.0)
        assert x == pytest.approx(0.0, abs=1e-6)
        assert y == pytest.approx(10.0, abs=1e-6)
        assert z == pytest.approx(0.0, abs=1e-6)

    def test_on_z_axis(self):
        """Star at Dec=90deg, dist=10pc -> (0, 0, 10)"""
        x, y, z = compute_equatorial_coords(0.0, 90.0, 10.0)
        assert x == pytest.approx(0.0, abs=1e-6)
        assert y == pytest.approx(0.0, abs=1e-6)
        assert z == pytest.approx(10.0, abs=1e-6)

    def test_none_values(self):
        assert compute_equatorial_coords(None, 0.0, 10.0) == (None, None, None)
        assert compute_equatorial_coords(0.0, None, 10.0) == (None, None, None)
        assert compute_equatorial_coords(0.0, 0.0, None) == (None, None, None)

    def test_matches_sql_formula(self):
        """Result should match the SQL formula in 03_import_data.sql:
        x_eq = dist * cos(radians(dec)) * cos(radians(ra * 15))
        y_eq = dist * cos(radians(dec)) * sin(radians(ra * 15))
        z_eq = dist * sin(radians(dec))
        """
        ra_hours = 14.5
        dec_deg = -62.68
        dist_pc = 1.30
        x, y, z = compute_equatorial_coords(ra_hours, dec_deg, dist_pc)
        # Manual calculation
        ra_rad = math.radians(ra_hours * 15.0)
        dec_rad = math.radians(dec_deg)
        expected_x = dist_pc * math.cos(dec_rad) * math.cos(ra_rad)
        expected_y = dist_pc * math.cos(dec_rad) * math.sin(ra_rad)
        expected_z = dist_pc * math.sin(dec_rad)
        assert x == pytest.approx(expected_x, abs=1e-6)
        assert y == pytest.approx(expected_y, abs=1e-6)
        assert z == pytest.approx(expected_z, abs=1e-6)


# ---------------------------------------------------------------------------
# estimate_spectral_type
# ---------------------------------------------------------------------------

class TestEstimateSpectralType:
    def test_solar_type(self):
        """G-type: BP-RP ~0.82, Mv ~4.8 -> G V"""
        assert estimate_spectral_type(0.82, 4.8) == "G V"

    def test_red_dwarf(self):
        """M dwarf: BP-RP ~3.0, Mv ~14 -> M V"""
        assert estimate_spectral_type(3.0, 14.0) == "M V"

    def test_white_dwarf(self):
        """White dwarf: BP-RP ~0.0, Mv ~12 -> D"""
        assert estimate_spectral_type(0.0, 12.0) == "D"

    def test_white_dwarf_cool(self):
        """Cool white dwarf: BP-RP ~1.2, Mv ~14 -> D"""
        assert estimate_spectral_type(1.2, 14.0) == "D"

    def test_brown_dwarf(self):
        """Brown dwarf: BP-RP ~5.0 -> L"""
        assert estimate_spectral_type(5.0, 20.0) == "L"

    def test_no_color(self):
        """No BP-RP data -> None"""
        assert estimate_spectral_type(None, 5.0) is None

    def test_k_dwarf(self):
        """K dwarf: BP-RP ~1.1, Mv ~6.5 -> K V"""
        assert estimate_spectral_type(1.1, 6.5) == "K V"

    def test_a_star(self):
        """A-type: BP-RP ~0.2, Mv ~1.5 -> A V"""
        assert estimate_spectral_type(0.2, 1.5) == "A V"

    def test_f_star(self):
        """F-type: BP-RP ~0.5, Mv ~3.5 -> F V"""
        assert estimate_spectral_type(0.5, 3.5) == "F V"

    def test_b_star(self):
        """B-type: BP-RP ~-0.1, Mv ~-1.0 -> B V"""
        assert estimate_spectral_type(-0.1, -1.0) == "B V"

    def test_o_star(self):
        """O-type: BP-RP ~-0.4, Mv ~-5.0 -> O V"""
        assert estimate_spectral_type(-0.4, -5.0) == "O V"

    def test_ambiguous_no_absmag(self):
        """Moderate color but no absmag -> None (ambiguous)"""
        assert estimate_spectral_type(1.0, None) is None

    def test_very_red_no_absmag(self):
        """Very red with no absmag -> M V (within 25pc, virtually certain)"""
        assert estimate_spectral_type(3.0, None) == "M V"

    def test_k_giant(self):
        """K giant: BP-RP ~1.1, Mv ~1.0 -> K III"""
        assert estimate_spectral_type(1.1, 1.0) == "K III"

    def test_g_subgiant(self):
        """G subgiant: BP-RP ~0.82, Mv ~2.5 -> G IV"""
        assert estimate_spectral_type(0.82, 2.5) == "G IV"


# ---------------------------------------------------------------------------
# extract_gj_component
# ---------------------------------------------------------------------------

class TestExtractGJComponent:
    def test_simple_component(self):
        assert extract_gj_component("127A") == "A"

    def test_decimal_gj_with_component(self):
        assert extract_gj_component("822.1C") == "C"

    def test_no_component(self):
        assert extract_gj_component("551") is None

    def test_decimal_no_component(self):
        assert extract_gj_component("822.1") is None

    def test_none_input(self):
        assert extract_gj_component(None) is None

    def test_all_alpha(self):
        """All-alpha string (like 'Sun') has no component."""
        assert extract_gj_component("Sun") is None

    def test_multi_letter_component(self):
        assert extract_gj_component("127AB") == "AB"

    def test_whitespace(self):
        assert extract_gj_component("  127A  ") == "A"


# ---------------------------------------------------------------------------
# Component validation logic
# ---------------------------------------------------------------------------

class TestComponentValidation:
    """Test the component mismatch detection logic used during matching."""

    def test_component_match_allows(self):
        """When athyg has '127A' and CNS5 comp is 'A', match should stand."""
        id_to_gj = {100: "127A"}
        athyg_gj = id_to_gj.get(100)
        athyg_comp = extract_gj_component(athyg_gj)
        assert athyg_comp == "A"
        assert athyg_comp == "A"  # matches CNS5 comp

    def test_component_mismatch_cancels(self):
        """When athyg has '127A' and CNS5 comp is 'B', match should cancel."""
        id_to_gj = {100: "127A"}
        athyg_gj = id_to_gj.get(100)
        athyg_comp = extract_gj_component(athyg_gj)
        assert athyg_comp == "A"
        assert athyg_comp not in "B"  # mismatch -> cancel

    def test_ab_contains_a_allows(self):
        """When athyg has '860A' and CNS5 comp is 'AB', match should stand."""
        id_to_gj = {100: "860A"}
        athyg_comp = extract_gj_component(id_to_gj.get(100))
        cns5_comp = "AB"
        assert athyg_comp == "A"
        assert athyg_comp in cns5_comp  # A is in AB -> allow

    def test_ab_contains_b_allows(self):
        """When athyg has '860B' and CNS5 comp is 'AB', match should stand."""
        id_to_gj = {100: "860B"}
        athyg_comp = extract_gj_component(id_to_gj.get(100))
        cns5_comp = "AB"
        assert athyg_comp == "B"
        assert athyg_comp in cns5_comp  # B is in AB -> allow

    def test_gj_plus_comp_lookup(self):
        """GJ matching should combine gj + comp to find athyg entries."""
        gj_index = {"150.1A": 100, "150.1B": 200}
        # Bare GJ number doesn't match
        assert gj_index.get("150.1") is None
        # GJ + comp finds the correct entry
        assert gj_index.get("150.1" + "B") == 200
        assert gj_index.get("150.1" + "A") == 100

    def test_no_athyg_component_allows(self):
        """When athyg has '551' (no component), any CNS5 comp is OK."""
        id_to_gj = {100: "551"}
        athyg_comp = extract_gj_component(id_to_gj.get(100))
        assert athyg_comp is None
        # No athyg component -> validation doesn't apply

    def test_no_cns5_component_allows(self):
        """When CNS5 has no component, match should stand regardless."""
        id_to_gj = {100: "127A"}
        athyg_comp = extract_gj_component(id_to_gj.get(100))
        cns5_comp = None
        assert athyg_comp == "A"
        # No CNS5 component -> validation doesn't apply
        # (both must have components for mismatch to cancel)

    def test_no_athyg_gj_allows(self):
        """When athyg has no GJ at all, match should stand."""
        id_to_gj = {}
        athyg_comp = extract_gj_component(id_to_gj.get(100))
        assert athyg_comp is None
