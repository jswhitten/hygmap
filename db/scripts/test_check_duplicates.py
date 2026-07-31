"""
Tests for the Gaia duplicate guard.

Run: python -m pytest test_check_duplicates.py -v

Two things are covered, because the guard exists in two places and either one drifting
would matter:

- the classification rule in check_duplicates.py, which is pure and testable directly
- the SQL guard in the import files, which needs Postgres and 2.8M rows to execute, so
  what is asserted here is that it is still present and still tests the right invariant
"""
import os
import re

import pytest

from check_duplicates import (
    FIRST_IMPORTED_ID,
    KNOWN_INHERITED_BASELINE,
    classify_group,
    summarise,
)

SQL_DIR = os.path.join(os.path.dirname(__file__), "..", "sql")


class TestClassifyGroup:
    """
    The rule that separates "we made this duplicate" from "AT-HYG shipped it".

    Getting this backwards in either direction is costly: blame AT-HYG for our own bug and
    the build stays green while inserting the same star twice; blame us for AT-HYG's real
    binaries and the build fails on 1,166 legitimate rows.
    """

    def test_two_athyg_rows_are_inherited(self):
        # A real close binary: Tycho-2 resolved both, Gaia recorded one source.
        assert classify_group([1035811, 2413107]) == "inherited"

    def test_a_supplement_inserted_row_makes_it_ours(self):
        # gaia 1005873614080407296, the measured CNS5/GCNS collision.
        assert classify_group([5000426, 6069717]) == "created"

    def test_one_supplement_row_beside_an_athyg_row_is_ours(self):
        """The 39 measured cases where CNS5 re-inserted a star AT-HYG already had."""
        assert classify_group([310581, 5000426]) == "created"

    def test_the_boundary_id_counts_as_imported(self):
        assert classify_group([1, FIRST_IMPORTED_ID]) == "created"
        assert classify_group([1, FIRST_IMPORTED_ID - 1]) == "inherited"


class TestSummarise:
    def test_counts_each_class(self):
        groups = {
            "a": [100, 200],  # inherited
            "b": [5000001, 6000001],  # created
            "c": [300, 6000002],  # created
            "d": [400, 500],  # inherited
        }
        assert summarise(groups) == (2, 2)

    def test_ignores_singleton_groups(self):
        """A star with a unique Gaia id is not a duplicate, however it got there."""
        assert summarise({"a": [5000001], "b": [100]}) == (0, 0)

    def test_no_duplicates_is_all_zero(self):
        assert summarise({}) == (0, 0)

    def test_the_baseline_is_not_zero_and_that_is_deliberate(self):
        """
        Guards against someone "tidying" the baseline to 0 and then suppressing the
        resulting failure by merging real binaries. The number being non-zero is the
        recorded decision that AT-HYG's duplicates are legitimate.
        """
        assert KNOWN_INHERITED_BASELINE > 1000


class TestImportGuardsArePresent:
    @staticmethod
    def _sql(name):
        path = os.path.join(SQL_DIR, name)
        assert os.path.exists(path), f"{path} not found"
        with open(path) as fh:
            return fh.read()

    @pytest.mark.parametrize(
        "filename,column",
        [("06_import_cns5.sql", "s.gaia"), ("07_import_gcns.sql", "s.source_id")],
    )
    def test_new_star_insert_skips_an_existing_gaia_id(self, filename, column):
        """
        Both imports must refuse to insert a 'new' star whose Gaia id is already present.
        The guards are symmetric on purpose so neither import depends on running before
        the other -- the original bug came precisely from an assumption about ordering.
        """
        sql = self._sql(filename)
        pattern = re.compile(
            r"NOT\s+EXISTS\s*\(\s*SELECT\s+1\s+FROM\s+athyg\s+a\s+"
            r"WHERE\s+a\.gaia\s*=\s*NULLIF\(" + re.escape(column),
            re.IGNORECASE,
        )
        assert pattern.search(sql), (
            f"{filename} no longer skips inserting a star whose Gaia id already exists. "
            "Without this the CNS5/GCNS collision returns: 2,598 stars inserted twice."
        )

    def test_the_build_fails_on_a_pipeline_created_duplicate(self):
        sql = self._sql("07_import_gcns.sql")
        assert "GAIA-DUPLICATES" in sql, "the build-time duplicate guard was removed"
        assert "RAISE EXCEPTION" in sql
        assert str(FIRST_IMPORTED_ID) in sql, (
            "the guard no longer distinguishes imported rows from AT-HYG rows by id, "
            "which is what keeps it from failing on real binaries"
        )

    def test_the_guard_does_not_simply_require_zero_duplicates(self):
        """
        A guard asserting COUNT(*) = 0 would fail on 1,166 legitimate binaries and would
        get deleted the first time someone hit it. It must test *provenance*.
        """
        sql = self._sql("07_import_gcns.sql")
        assert "FILTER (WHERE id >= 5000000)" in sql


class TestSeparationIsMeasuredInTheRightUnits:
    """
    athyg.ra is stored in HOURS, not degrees.

    The first version of the wide-separation query fed `radians(h.ra - l.ra)` straight into
    the haversine, understating every RA difference by a factor of 15. It still returned
    the right answer for the case it was written against -- the known bad cross-match came
    back as 29.2 degrees instead of 95.3 and was over the 1-degree threshold either way --
    which is exactly why this needs a test rather than a spot check. A subtler case would
    have slipped under the threshold silently.
    """

    @staticmethod
    def _source():
        path = os.path.join(os.path.dirname(__file__), "check_duplicates.py")
        with open(path) as fh:
            return fh.read()

    def test_right_ascension_is_converted_from_hours(self):
        src = self._source()
        assert re.search(r"radians\(\s*\(h\.ra\s*-\s*l\.ra\)\s*\*\s*15\s*\)", src), (
            "the separation query no longer scales ra from hours to degrees; separations "
            "will read 15x too small"
        )

    def test_declination_is_not_scaled(self):
        """Dec is already in degrees -- scaling it too would be the opposite mistake."""
        src = self._source()
        assert re.search(r"radians\(h\.dec\s*-\s*l\.dec\)", src)


class TestOverrideMechanismSupportsRetraction:
    """
    The AT-HYG cross-match error needs a key and an action the override file did not have.
    Both are load-bearing and easy to remove by accident.
    """

    @staticmethod
    def _overrides_sql():
        path = os.path.join(SQL_DIR, "09_import_overrides.sql")
        with open(path) as fh:
            return fh.read()

    def test_tycho_is_accepted_as_a_key(self):
        """
        Needed because the broken star's *Gaia id itself* is wrong and shared with the
        star it was stolen from, so keying on Gaia would correct the innocent row too.
        """
        assert "a.tyc = o.tyc" in self._overrides_sql()

    def test_clear_gaia_also_retracts_everything_derived(self):
        sql = self._overrides_sql()
        assert "r.clear_gaia IS TRUE" in sql
        for derived in ("dist", "absmag", "x_eq"):
            assert re.search(rf"{derived}\s*=\s*NULL", sql), (
                f"clear_gaia no longer clears {derived}; a retracted Gaia id leaves the "
                "star carrying another star's astrometry"
            )

    def test_the_override_csv_has_the_matching_columns(self):
        path = os.path.join(os.path.dirname(__file__), "..", "data", "athyg_overrides.csv")
        with open(path) as fh:
            header = fh.readline().strip().split(",")
        assert header == ["gaia", "hip", "tyc", "expect_proper", "dist", "clear_gaia", "source"]
