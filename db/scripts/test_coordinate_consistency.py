"""
The invariant behind COORD-RECOMPUTE-FIX: a star's stored position must have the same
length as its stored distance.

x/y/z are dist projected through a rotation, so |position| IS dist. That held for every
row AT-HYG supplied and broke only where a supplement catalogue corrected a distance:
06_import_cns5.sql and 07_import_gcns.sql gated their coordinate recompute on the
coordinates being NULL, which is true only for rows they INSERT. 17 already-existing
stars kept the position derived from the distance that had just been rejected -- GJ 125
at 97,011 pc with dist 17.19 pc -- and dist, absmag and dist_src were all correct on
those rows, so nothing else noticed.

These tests cannot reach a database (`make test-scripts` runs a bare Python image), so
they cover the two things that are checkable without one:

  * the arithmetic, including that the galactic rotation matrix really is norm-preserving
    -- if it were not, the invariant would be false and the new quality check would fire
    on healthy rows;
  * that the two imports still gate on disagreement rather than on NULL, and that their
    tolerance still matches the checker's. That coupling is the part most likely to rot.

The end-to-end proof is a clean rebuild plus check_distance_quality.py, which is recorded
in the feature file.
"""
import math
import re
from pathlib import Path

import pytest

from check_distance_quality import COORD_TOLERANCE_FRACTION

SQL_DIR = Path(__file__).resolve().parent.parent / "sql"
IMPORTS_THAT_CORRECT_DISTANCES = ("06_import_cns5.sql", "07_import_gcns.sql")

# The equatorial -> galactic rotation, as written in every SQL file that uses it.
GALACTIC_MATRIX = (
    (-0.055, -0.8734, -0.4839),
    (0.494, -0.4449, 0.747),
    (-0.8677, -0.1979, 0.4560),
)


def equatorial(ra_hours, dec_degrees, dist):
    """The x_eq/y_eq/z_eq expression from the imports, in Python."""
    ra = math.radians(ra_hours * 15)
    dec = math.radians(dec_degrees)
    return (
        dist * math.cos(dec) * math.cos(ra),
        dist * math.cos(dec) * math.sin(ra),
        dist * math.sin(dec),
    )


def galactic(eq):
    return tuple(sum(row[i] * eq[i] for i in range(3)) for row in GALACTIC_MATRIX)


def norm(v):
    return math.sqrt(sum(c * c for c in v))


class TestTheInvariantHolds:
    """|position| == dist, which is what the new quality check asserts."""

    @pytest.mark.parametrize(
        "ra,dec,dist",
        [
            (0.0, 0.0, 1.0),
            (4.6, -16.7, 17.19),      # GJ 125, the star this bug was found on
            (6.75, -16.72, 2.64),     # Sirius
            (2.53, 89.26, 133.0),     # Polaris, near the pole
            (23.99, 0.0, 50.0),       # just below the RA wrap
            (12.0, -89.9, 1000.0),    # near the south pole
        ],
    )
    def test_equatorial_length_is_the_distance(self, ra, dec, dist):
        assert norm(equatorial(ra, dec, dist)) == pytest.approx(dist, rel=1e-12)

    @pytest.mark.parametrize(
        "ra,dec,dist",
        [
            (0.0, 0.0, 1.0),
            (4.6, -16.7, 17.19),
            (6.75, -16.72, 2.64),
            (2.53, 89.26, 133.0),
            (18.0, 45.0, 8000.0),
        ],
    )
    def test_galactic_length_is_also_the_distance(self, ra, dec, dist):
        # Only true if the rotation matrix is orthonormal. A mistyped constant would
        # break the invariant itself, not just one row.
        assert norm(galactic(equatorial(ra, dec, dist))) == pytest.approx(
            dist, rel=COORD_TOLERANCE_FRACTION / 10
        )

    def test_the_matrix_is_orthonormal_to_within_the_tolerance(self):
        # Stated directly rather than inferred from the cases above, so a failure says
        # what is wrong. The published matrix is rounded to 4 decimals, so it is not
        # exactly orthonormal -- it only has to be well inside the tolerance the
        # checker uses, or healthy rows would fail the check.
        for i, row in enumerate(GALACTIC_MATRIX):
            assert norm(row) == pytest.approx(1.0, abs=COORD_TOLERANCE_FRACTION / 10), (
                f"row {i} is not a unit vector"
            )
        for i in range(3):
            for j in range(i + 1, 3):
                dot = sum(GALACTIC_MATRIX[i][k] * GALACTIC_MATRIX[j][k] for k in range(3))
                assert dot == pytest.approx(0.0, abs=COORD_TOLERANCE_FRACTION / 10), (
                    f"rows {i} and {j} are not orthogonal"
                )

    def test_a_stale_position_is_far_outside_the_tolerance(self):
        # The real GJ 125 numbers. Guards against anyone widening the tolerance to a
        # point where the defect would slip through.
        stale, correct = 97011.0, 17.19
        assert abs(stale - correct) > COORD_TOLERANCE_FRACTION * correct


class TestImportsRecomputeOnDisagreement:
    """
    The regression guard.

    The bug was a gate that read "recompute where the coordinates are missing". The fix
    is "recompute where they are missing OR disagree with dist". If someone simplifies
    it back, these fail.
    """

    @staticmethod
    def _recompute_block(filename):
        text = (SQL_DIR / filename).read_text()
        start = text.index("Recompute coordinates for matched stars")
        end = text.index("INSERT INTO athyg", start)
        return text[start:end]

    @pytest.mark.parametrize("filename", IMPORTS_THAT_CORRECT_DISTANCES)
    def test_recompute_is_gated_on_disagreement_not_only_on_null(self, filename):
        block = self._recompute_block(filename)
        assert "abs(sqrt(x_eq*x_eq + y_eq*y_eq + z_eq*z_eq) - dist)" in block, (
            f"{filename} no longer recomputes the equatorial position when it disagrees "
            "with dist; that is the COORD-RECOMPUTE-FIX defect returning"
        )
        assert "abs(sqrt(x*x + y*y + z*z) - dist)" in block, (
            f"{filename} no longer recomputes the galactic position when it disagrees "
            "with dist"
        )

    @pytest.mark.parametrize("filename", IMPORTS_THAT_CORRECT_DISTANCES)
    def test_import_tolerance_matches_the_checker(self, filename):
        # A drift here would be silent and nasty in one direction: an import looser than
        # the checker leaves rows the checker then fails the build on.
        found = set(re.findall(r"> ([0-9.]+) \* dist", self._recompute_block(filename)))
        assert found, f"{filename} has no tolerance comparison against dist"
        assert found == {str(COORD_TOLERANCE_FRACTION)}, (
            f"{filename} uses tolerance(s) {sorted(found)} but "
            f"check_distance_quality.py uses {COORD_TOLERANCE_FRACTION}"
        )

    @pytest.mark.parametrize("filename", IMPORTS_THAT_CORRECT_DISTANCES)
    def test_equatorial_is_recomputed_before_galactic(self, filename):
        # x/y/z are derived from x_eq/y_eq/z_eq, so the order is load-bearing: rebuilding
        # the galactic triple from a stale equatorial one just re-derives the bad value.
        block = self._recompute_block(filename)
        assert block.index("SET\n  x_eq") < block.index("SET\n  x =")


class TestCheckerCoversBothTriples:
    """
    Both coordinate triples are checked, not just the galactic one.

    x/y/z are computed from x_eq/y_eq/z_eq. If only the galactic triple were verified, a
    stale equatorial one could survive and the next import step to rebuild x/y/z from it
    would silently restore the bad position.
    """

    def test_both_galactic_and_equatorial_are_checked(self):
        from check_distance_quality import CHECKS

        labels = " ".join(label for label, _, _ in CHECKS)
        assert "galactic positions whose length disagrees" in labels
        assert "equatorial positions whose length disagrees" in labels

    def test_checks_use_the_shared_tolerance(self):
        from check_distance_quality import CHECKS

        coordinate_checks = [
            sql for label, sql, _ in CHECKS if "length disagrees with dist" in label
        ]
        assert len(coordinate_checks) == 2
        for sql in coordinate_checks:
            assert f"{COORD_TOLERANCE_FRACTION} * dist" in sql
