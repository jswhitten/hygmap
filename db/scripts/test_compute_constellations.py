"""
Tests for constellation computation.

Run: python -m pytest test_compute_constellations.py -v
"""
import os
import pytest
from compute_constellations import (
    load_boundaries,
    precess_to_b1875,
    constellation_independent,
    constellations_astropy,
    VERIFY_CASES,
)

BOUNDARIES = load_boundaries(
    os.path.join(os.path.dirname(__file__), "..", "data", "constellation_boundaries.csv")
)


class TestBoundaryTable:
    def test_has_the_full_roman_table(self):
        assert len(BOUNDARIES) == 357

    def test_covers_all_88_constellations(self):
        assert len({b[3] for b in BOUNDARIES}) == 88

    def test_is_ordered_by_descending_declination(self):
        """
        Load-bearing: the lookup takes the first row whose RA range and declination floor match,
        which only gives the right answer because Roman ordered the table this way. Sorting it
        differently would silently break every result.
        """
        decs = [b[2] for b in BOUNDARIES]
        assert decs == sorted(decs, reverse=True)

    def test_final_row_is_the_south_polar_catch_all(self):
        # Guarantees every position resolves to something.
        ra_low, ra_up, dec_low, con = BOUNDARIES[-1]
        assert (ra_low, ra_up, dec_low, con) == (0.0, 24.0, -90.0, "Oct")


class TestPrecession:
    def test_precesses_backwards_by_the_expected_amount(self):
        """
        J2000 -> B1875 is about 125 years back. At RA near 0h the expected shifts are roughly
        -0.107h in RA and -0.696 deg in Dec; a sign error or a factor slip shows up here.
        """
        ra, dec = precess_to_b1875(0.02551, -24.80391)
        assert (ra - 24.0) - 0.02551 == pytest.approx(-0.107, abs=0.002)
        assert dec - (-24.80391) == pytest.approx(-0.696, abs=0.002)

    def test_wraps_ra_below_zero_into_range(self):
        ra, _dec = precess_to_b1875(0.01, 0.0)
        assert 23.0 < ra < 24.0

    def test_ra_always_in_range(self):
        for ra_in in (0.0, 6.0, 12.0, 18.0, 23.999):
            ra, _ = precess_to_b1875(ra_in, 10.0)
            assert 0.0 <= ra < 24.0


class TestKnownStars:
    @pytest.mark.parametrize("name,ra,dec,expected", VERIFY_CASES, ids=[c[0] for c in VERIFY_CASES])
    def test_independent_implementation(self, name, ra, dec, expected):
        assert constellation_independent(ra, dec, BOUNDARIES) == expected

    def test_astropy_implementation(self):
        got = constellations_astropy([(ra, dec) for _n, ra, dec, _e in VERIFY_CASES])
        assert got == [c[3] for c in VERIFY_CASES]


class TestImplementationsAgree:
    """
    The two implementations are carried on purpose; this is the check that makes that useful.

    They diverge only for positions within about an arcsecond of a boundary, where the FK4
    frame terms astropy models decide the answer. Measured agreement on a 30,000-star sample
    was 99.993%.
    """

    def test_agree_across_a_spread_of_positions(self):
        positions = [
            (ra, dec)
            for ra in (0.5, 3.0, 6.0, 9.0, 12.0, 15.0, 18.0, 21.0, 23.5)
            for dec in (-85.0, -60.0, -30.0, -5.0, 5.0, 30.0, 60.0, 85.0)
        ]
        astro = constellations_astropy(positions)
        mine = [constellation_independent(ra, dec, BOUNDARIES) for ra, dec in positions]

        disagreements = [
            (p, a, m) for p, a, m in zip(positions, astro, mine) if a != m
        ]
        assert not disagreements, f"implementations diverged away from boundaries: {disagreements}"

    def test_every_position_resolves(self):
        """No position may come back unresolved — the table's last row is a catch-all."""
        for ra in (0.0, 5.5, 11.9, 17.3, 23.99):
            for dec in (-90.0, -45.0, 0.0, 45.0, 89.9):
                assert constellation_independent(ra, dec, BOUNDARIES) is not None


class TestSolIsExcluded:
    def test_the_origin_would_otherwise_get_a_constellation(self):
        """
        Sol sits at RA 0 Dec 0 because it *is* the origin, not because it lies there on the sky.
        The lookup happily returns Pisces for it, which is meaningless — hence the explicit
        exclusion in the query. This test documents why that exclusion exists so it is not
        removed as redundant.
        """
        assert constellation_independent(0.0, 0.0, BOUNDARIES) == "Psc"
