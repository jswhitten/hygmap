"""
Unit tests for match_gcns.py parsing and astrometric helpers.

Run: python -m pytest test_match_gcns.py -v
"""

import math
import pytest
from match_gcns import (
    parse_fixed_width_line,
    propagate_to_j2000,
    estimate_vmag,
    estimate_spectral_type,
    compute_equatorial_coords,
    safe_float,
    safe_str,
    build_output_row,
    GCNS_EPOCH,
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

    def test_safe_float_empty(self):
        assert safe_float("") is None

    def test_safe_float_nan(self):
        assert safe_float("nan") is None

    def test_safe_float_none(self):
        assert safe_float(None) is None

    def test_safe_str_normal(self):
        assert safe_str("  551  ") == "551"

    def test_safe_str_blank(self):
        assert safe_str("      ") is None

    def test_safe_str_empty(self):
        assert safe_str("") is None

    def test_safe_str_none(self):
        assert safe_str(None) is None


# ---------------------------------------------------------------------------
# parse_fixed_width_line
# ---------------------------------------------------------------------------

class TestParseFixedWidthLine:
    # Sample line from table1c.dat (real data)
    SAMPLE_LINE = (
        "  2334666126716440064      0.0025650 0.03305    -26.3653495 0.02500   "
        " 14.697 0.03698    23.497 0.03680   -62.339 0.02981  15.7779   1586.04"
        "  17.4299    150.54  14.5407    689.37   1.4738  1.01   0            "
        "                            F 1.000 0.000      0.06765      0.06787  "
        "    0.06803      0.06821     11.27021     11.24292     11.30038     "
    )
    
    def test_parse_full_line(self):
        """Parse a complete fixed-width line from table1c.dat."""
        # Build a properly formatted line based on the format spec
        # Bytes:  3-21 source_id, 23-36 RA, 46-59 Dec, 69-77 Plx, 87-95 pmRA,
        #        105-113 pmDE, 123-130 Gmag, 142-149 BPmag, 161-168 RPmag, 240-244 GCNSprob
        line = self.SAMPLE_LINE
        rec = parse_fixed_width_line(line)
        assert rec is not None
        assert rec["source_id"] == "2334666126716440064"
        assert rec["ra_deg"] == pytest.approx(0.0025650, abs=1e-6)
        assert rec["dec_deg"] == pytest.approx(-26.3653495, abs=1e-6)
        assert rec["parallax"] == pytest.approx(14.697, abs=1e-3)
        assert rec["pmra"] == pytest.approx(23.497, abs=1e-3)
        assert rec["pmdec"] == pytest.approx(-62.339, abs=1e-3)
        assert rec["g_mag"] == pytest.approx(15.7779, abs=1e-3)
        # Note: BP and RP mags are at different positions

    def test_parse_missing_source_id(self):
        """Line without source_id should return None."""
        line = "                       180.0000000          45.0000000"
        rec = parse_fixed_width_line(line)
        assert rec is None

    def test_parse_line_with_rv(self):
        """Parse a line that has radial velocity data."""
        # Line 3 from the sample has RV data
        line = (
            "   530861741656374272      0.0056369 0.00951     70.8873639 0.00858   "
            " 10.282 0.01075   -52.864 0.01210    17.787 0.01162  10.8521   3717.03"
            "  11.3022   1136.43  10.2396   1764.44   1.2190  0.82   0  -26.204   "
            "0.4477  2018A&A...616A...1G T 1.000 0.000      0.09702"
        )
        rec = parse_fixed_width_line(line)
        assert rec is not None
        assert rec["source_id"] == "530861741656374272"
        assert rec["ra_deg"] == pytest.approx(0.0056369, abs=1e-6)
        assert rec["dec_deg"] == pytest.approx(70.8873639, abs=1e-6)
        assert rec["parallax"] == pytest.approx(10.282, abs=1e-3)
        assert rec["g_mag"] == pytest.approx(10.8521, abs=1e-3)


# ---------------------------------------------------------------------------
# propagate_to_j2000
# ---------------------------------------------------------------------------

class TestPropagateToJ2000:
    def test_no_motion(self):
        """Star with zero proper motion should not move."""
        ra, dec = propagate_to_j2000(180.0, 45.0, 2016.0, 0.0, 0.0)
        assert ra == pytest.approx(180.0, abs=1e-10)
        assert dec == pytest.approx(45.0, abs=1e-10)

    def test_positive_pm(self):
        """Star moving east and north should go west and south going back to J2000."""
        # 16 years from 2016 back to 2000, so dt = -16
        # pmra = 1000 mas/yr, at dec=0 (cos_dec=1): delta_ra = 1000 * -16 / 3_600_000 deg
        ra, dec = propagate_to_j2000(180.0, 0.0, 2016.0, 1000.0, 500.0)
        # Expected delta_ra = -0.004444... deg, delta_dec = -0.002222... deg
        assert ra == pytest.approx(180.0 - 16 * 1000 / 3_600_000, abs=1e-6)
        assert dec == pytest.approx(0.0 - 16 * 500 / 3_600_000, abs=1e-6)

    def test_high_declination(self):
        """Test cos(dec) scaling at high declination."""
        ra, dec = propagate_to_j2000(180.0, 60.0, 2016.0, 1000.0, 0.0)
        # cos(60) = 0.5, so delta_ra doubles
        cos_dec = 0.5
        expected_delta_ra = -16 * 1000 / (3_600_000 * cos_dec)
        assert ra == pytest.approx(180.0 + expected_delta_ra, abs=1e-6)

    def test_missing_pm(self):
        """Missing proper motion should return position unchanged."""
        ra, dec = propagate_to_j2000(180.0, 45.0, 2016.0, None, None)
        assert ra == pytest.approx(180.0)
        assert dec == pytest.approx(45.0)

    def test_missing_epoch(self):
        """Missing epoch should return position unchanged."""
        ra, dec = propagate_to_j2000(180.0, 45.0, None, 100.0, 100.0)
        assert ra == pytest.approx(180.0)
        assert dec == pytest.approx(45.0)

    def test_ra_wrapping(self):
        """RA should wrap to [0, 360) after propagation."""
        # Start near 0, move west (negative RA delta) to wrap around
        ra, dec = propagate_to_j2000(0.5, 0.0, 2016.0, 5000.0, 0.0)
        # delta_ra = -16 * 5000 / 3_600_000 = -0.0222 deg
        expected = (0.5 - 16 * 5000 / 3_600_000) % 360.0
        assert ra == pytest.approx(expected, abs=1e-6)


# ---------------------------------------------------------------------------
# estimate_vmag
# ---------------------------------------------------------------------------

class TestEstimateVmag:
    def test_with_bp_rp(self):
        """Estimate V from G and BP-RP color."""
        # G=11.109, BP=12.723, RP=9.858 -> BP-RP=2.865
        v = estimate_vmag(11.109, 12.723, 9.858)
        assert v is not None
        # V = G - (-0.01760 - 0.006860*X + 0.1732*X^2)
        bp_rp = 12.723 - 9.858
        correction = -0.01760 - 0.006860 * bp_rp + 0.1732 * bp_rp ** 2
        expected = 11.109 - correction
        assert v == pytest.approx(expected, abs=0.001)

    def test_without_colors(self):
        """Without BP and RP, return None."""
        v = estimate_vmag(11.0, None, None)
        assert v is None

    def test_none_g_mag(self):
        """Without G magnitude, return None."""
        v = estimate_vmag(None, 12.0, 10.0)
        assert v is None


# ---------------------------------------------------------------------------
# estimate_spectral_type
# ---------------------------------------------------------------------------

class TestEstimateSpectralType:
    def test_m_dwarf(self):
        """Very red star should be M dwarf."""
        spect = estimate_spectral_type(2.8, 12.0)
        assert spect == "M V"

    def test_brown_dwarf(self):
        """Extremely red star (BP-RP >= 4) should be L."""
        spect = estimate_spectral_type(4.5, 15.0)
        assert spect == "L"

    def test_white_dwarf(self):
        """Faint blue star should be white dwarf."""
        spect = estimate_spectral_type(0.5, 13.0)
        assert spect == "D"

    def test_g_dwarf(self):
        """Solar-like color should be G dwarf."""
        spect = estimate_spectral_type(0.8, 5.0)
        assert spect == "G V"

    def test_k_giant(self):
        """Red star with bright absolute mag should be giant."""
        spect = estimate_spectral_type(1.2, 1.0)
        assert spect == "K III"

    def test_no_color(self):
        """Without BP-RP color, return None."""
        spect = estimate_spectral_type(None, 5.0)
        assert spect is None

    def test_no_absmag_red(self):
        """Very red star without absmag defaults to M V."""
        spect = estimate_spectral_type(2.8, None)
        assert spect == "M V"


# ---------------------------------------------------------------------------
# compute_equatorial_coords
# ---------------------------------------------------------------------------

class TestComputeEquatorialCoords:
    def test_at_origin(self):
        """Star at RA=0, Dec=0 should be at (dist, 0, 0)."""
        x, y, z = compute_equatorial_coords(0.0, 0.0, 10.0)
        assert x == pytest.approx(10.0)
        assert y == pytest.approx(0.0, abs=1e-10)
        assert z == pytest.approx(0.0, abs=1e-10)

    def test_at_pole(self):
        """Star at Dec=90 should be at (0, 0, dist)."""
        x, y, z = compute_equatorial_coords(0.0, 90.0, 10.0)
        assert x == pytest.approx(0.0, abs=1e-10)
        assert y == pytest.approx(0.0, abs=1e-10)
        assert z == pytest.approx(10.0)

    def test_at_6_hours(self):
        """Star at RA=6h, Dec=0 should be at (0, dist, 0)."""
        x, y, z = compute_equatorial_coords(6.0, 0.0, 10.0)
        assert x == pytest.approx(0.0, abs=1e-10)
        assert y == pytest.approx(10.0)
        assert z == pytest.approx(0.0, abs=1e-10)

    def test_missing_values(self):
        """Missing inputs should return None tuple."""
        assert compute_equatorial_coords(None, 45.0, 10.0) == (None, None, None)
        assert compute_equatorial_coords(12.0, None, 10.0) == (None, None, None)
        assert compute_equatorial_coords(12.0, 45.0, None) == (None, None, None)


# ---------------------------------------------------------------------------
# build_output_row
# ---------------------------------------------------------------------------

class TestBuildOutputRow:
    def test_matched_row(self):
        """Build output for a matched star."""
        rec = {
            "source_id": "4472832130942575872",
            "ra_deg": 266.41683789617,
            "dec_deg": -29.00780604133,
            "parallax": 100.4561,
            "pmra": -3781.306,
            "pmdec": -769.005,
            "g_mag": 11.109,
            "bp_mag": 12.723,
            "rp_mag": 9.858,
            "prob100": 1.0,
            "probastr": 0.9978,
        }
        row = build_output_row(rec, 12345, "gaia_source_id", 0)
        assert row["athyg_id"] == 12345
        assert row["match_method"] == "gaia_source_id"
        assert row["source_id"] == "4472832130942575872"
        assert row["bright_unmatched"] == 0
        # Check that position was propagated
        assert float(row["ra_j2000"]) != 266.41683789617 / 15.0  # Should differ

    def test_new_star_row(self):
        """Build output for a new star."""
        rec = {
            "source_id": "1234567890123456789",
            "ra_deg": 180.0,
            "dec_deg": 45.0,
            "parallax": 20.0,  # 50 pc
            "pmra": 0.0,
            "pmdec": 0.0,
            "g_mag": 12.0,
            "bp_mag": 13.0,
            "rp_mag": 11.0,
            "prob100": 0.95,
            "probastr": 0.99,
        }
        row = build_output_row(rec, 6000001, "new", 0)
        assert row["athyg_id"] == 6000001
        assert row["match_method"] == "new"
        # Distance should be 1000/20 = 50 pc
        assert float(row["dist"]) == pytest.approx(50.0)

    def test_missing_parallax(self):
        """Handle star with missing parallax."""
        rec = {
            "source_id": "9999999999999999999",
            "ra_deg": 90.0,
            "dec_deg": 30.0,
            "parallax": None,
            "pmra": 100.0,
            "pmdec": 50.0,
            "g_mag": 15.0,
            "bp_mag": None,
            "rp_mag": None,
            "prob100": 0.8,
            "probastr": 0.9,
        }
        row = build_output_row(rec, 6000002, "new", 1)
        assert row["dist"] == ""
        assert row["x_eq"] == ""
        assert row["bright_unmatched"] == 1


# ---------------------------------------------------------------------------
# Integration sanity
# ---------------------------------------------------------------------------

class TestGCNSEpoch:
    def test_epoch_is_2016(self):
        """GCNS uses Gaia EDR3 epoch 2016.0."""
        assert GCNS_EPOCH == 2016.0
