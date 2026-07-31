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


class TestCelestialPoles:
    """
    Exactly dec = +/-90, the case most likely to break a boundary scan.

    No star in the live 2.84M-row catalog sits within 0.1 degrees of either pole
    (`dec > 89.9` and `dec < -89.9` both return 0 rows), so this branch has never been
    exercised by real data and would not be until some future catalog import lands one.
    That is precisely why it is worth pinning now rather than after it breaks.

    Two things make the poles a genuine edge case rather than a formality:

    - RA is degenerate there. Every meridian converges, so `precess_to_b1875` returns the
      same B1875 position for any input RA, and the lookup must not depend on which one
      the caller happened to pass.
    - Precession moves the pole. The J2000 pole precesses to dec 89.3038 in B1875, about
      0.7 degrees off the pole, so these positions resolve inside UMi's 88-degree band and
      never touch the table's +/-90 polar rows. A scan that special-cased dec == 90 would
      look correct and be wrong.
    """

    POLE_RAS = (0.0, 6.0, 12.0, 18.0, 23.99)

    @pytest.mark.parametrize("ra", POLE_RAS)
    def test_north_pole_is_ursa_minor(self, ra):
        assert constellation_independent(ra, 90.0, BOUNDARIES) == "UMi"

    @pytest.mark.parametrize("ra", POLE_RAS)
    def test_south_pole_is_octans(self, ra):
        assert constellation_independent(ra, -90.0, BOUNDARIES) == "Oct"

    def test_both_implementations_agree_at_the_poles(self):
        positions = [(ra, dec) for dec in (90.0, -90.0) for ra in self.POLE_RAS]
        astro = constellations_astropy(positions)
        mine = [constellation_independent(ra, dec, BOUNDARIES) for ra, dec in positions]

        disagreements = [(p, a, m) for p, a, m in zip(positions, astro, mine) if a != m]
        assert not disagreements, f"implementations diverged at a pole: {disagreements}"

    @pytest.mark.parametrize("dec", (90.0, -90.0))
    def test_result_does_not_depend_on_ra_at_the_pole(self, dec):
        """Every meridian meets at the pole, so the RA passed in must not change anything."""
        results = {constellation_independent(ra, dec, BOUNDARIES) for ra in self.POLE_RAS}
        assert len(results) == 1, f"pole result varied with RA: {results}"

    @pytest.mark.parametrize("dec", (90.0, -90.0))
    def test_precession_collapses_ra_at_the_pole(self, dec):
        """
        The degeneracy above, asserted at its source rather than only in its effect.

        Not bit-identical: the returned RAs differ in the last couple of ULPs (order 1e-14
        hours, or about 1e-10 arcseconds) because they arrive through different atan2
        arguments. That is float noise many orders of magnitude below any angle the
        boundary table can express, so the tolerance is what makes this test meaningful
        rather than a test of IEEE-754. Declination does come back exactly equal.
        """
        precessed = [precess_to_b1875(ra, dec) for ra in self.POLE_RAS]
        ras = [p[0] for p in precessed]
        decs = [p[1] for p in precessed]

        assert max(ras) - min(ras) < 1e-9, f"RA did not collapse: {ras}"
        assert len(set(decs)) == 1, f"declination varied with RA: {set(decs)}"

    def test_the_poles_do_not_reach_the_polar_boundary_rows(self):
        """
        Guards the reasoning in the docstring, not just the answer.

        If a future change made the poles resolve via the +/-90 catch-all rows, these
        assertions would fail even though the returned constellation stayed correct --
        which is the point, because it would mean precession had stopped being applied.
        """
        _ra_n, dec_n = precess_to_b1875(0.0, 90.0)
        _ra_s, dec_s = precess_to_b1875(0.0, -90.0)

        assert dec_n == pytest.approx(89.3038, abs=1e-3)
        assert dec_s == pytest.approx(-89.3038, abs=1e-3)
        # Comfortably inside the table rather than at its extremes.
        assert dec_n < 90.0 and dec_s > -90.0

    @pytest.mark.parametrize(
        "dec,expected", ((89.9999, "UMi"), (-89.9999, "Oct"))
    )
    def test_just_short_of_the_pole_matches_the_pole(self, dec, expected):
        """No discontinuity in the last fraction of a degree."""
        for ra in self.POLE_RAS:
            assert constellation_independent(ra, dec, BOUNDARIES) == expected
