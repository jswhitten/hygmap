"""Tests for match_athyg_v3.py — the AT-HYG v3.3 -> v4.0 id mapping."""

import csv
import gzip
import io

import pytest

from match_athyg_v3 import (
    V3_COLUMNS,
    assert_no_duplicate_v3_ids,
    build_index,
    from_ranges,
    match_rows,
    norm,
    read_v3_rows,
    to_ranges,
)


def v3_line(**fields):
    """Build a v3.3 CSV line from named columns."""
    row = {c: "" for c in V3_COLUMNS}
    row.update({k: str(v) for k, v in fields.items()})
    buf = io.StringIO()
    csv.writer(buf).writerow([row[c] for c in V3_COLUMNS])
    return buf.getvalue()


class TestNorm:
    def test_blank_is_none(self):
        assert norm("") is None
        assert norm("   ") is None
        assert norm(None) is None

    def test_strips_whitespace(self):
        assert norm(" 439 ") == "439"

    def test_float_form_matches_int_form(self):
        # AT-HYG writes some numeric ids as floats; '439.0' and '439' are one star.
        assert norm("439.0") == norm("439") == "439"

    def test_tycho_id_passes_through(self):
        assert norm("6995-1264-1") == "6995-1264-1"

    def test_non_integral_float_kept_as_written(self):
        assert norm("1.5") == "1.5"

    def test_large_gaia_id_survives_exactly(self):
        """A 19-digit Gaia source_id must not be routed through float().

        float('2306965202564744064') == 2.306965202564744e+18, which converts back to
        2306965202564744192 — a different star. Gaia is the primary match key for 98.6%
        of the catalog, so this would corrupt nearly every match.
        """
        big = "2306965202564744064"
        assert norm(big) == big
        assert norm(" " + big + " ") == big


class TestBuildIndex:
    def test_indexes_by_identifier(self):
        rows = [{"id": 10, "gaia": "111"}, {"id": 11, "gaia": "222"}]
        idx, ambiguous = build_index(rows, "gaia")
        assert idx == {"111": 10, "222": 11}
        assert ambiguous == 0

    def test_ambiguous_identifier_is_dropped_not_guessed(self):
        # The GAIA-DUPLICATES case: one Gaia source, two real binary components.
        # Picking either would send old links to a coin-flip star.
        rows = [{"id": 10, "gaia": "999"}, {"id": 11, "gaia": "999"}]
        idx, ambiguous = build_index(rows, "gaia")
        assert "999" not in idx
        assert ambiguous == 1

    def test_same_star_listed_twice_is_not_ambiguous(self):
        rows = [{"id": 10, "gaia": "999"}, {"id": 10, "gaia": "999"}]
        idx, ambiguous = build_index(rows, "gaia")
        assert idx == {"999": 10}
        assert ambiguous == 0

    def test_blank_identifiers_are_skipped(self):
        rows = [{"id": 10, "hip": ""}, {"id": 11, "hip": None}, {"id": 12, "hip": "5"}]
        idx, _ = build_index(rows, "hip")
        assert idx == {"5": 12}


class TestReadV3Rows:
    def test_reads_header_file_and_headerless_file(self, tmp_path):
        """Part 2 of the v3.3 release has NO header — its first line is data.

        Skipping line 1 of every file silently drops one star. This is the trap.
        """
        p1 = tmp_path / "athyg_v33-1.csv"
        p1.write_text(",".join(V3_COLUMNS) + "\n" + v3_line(id=1, gaia="111"))
        p2 = tmp_path / "athyg_v33-2.csv"
        p2.write_text(v3_line(id=2, gaia="222") + v3_line(id=3, gaia="333"))

        rows = list(read_v3_rows([str(p1), str(p2)]))
        assert [r["id"] for r in rows] == ["1", "2", "3"]

    def test_reads_gzip(self, tmp_path):
        p = tmp_path / "athyg_v33-1.csv.gz"
        with gzip.open(p, "wt") as fh:
            fh.write(",".join(V3_COLUMNS) + "\n" + v3_line(id=7301, hip=439))
        rows = list(read_v3_rows([str(p)]))
        assert rows[0]["hip"] == "439"

    def test_short_lines_are_ignored(self, tmp_path):
        p = tmp_path / "x.csv"
        p.write_text("1,2,3\n" + v3_line(id=5, gaia="5"))
        assert [r["id"] for r in read_v3_rows([str(p)])] == ["5"]


class TestMatchRows:
    def _indexes(self, **kw):
        return {k: v for k, v in kw.items()}

    def test_matches_on_gaia_first(self):
        v3 = [{"id": "7301", "gaia": "222", "tyc": "T", "gl": ""}]
        idx = self._indexes(gaia={"222": 7323}, tyc={"T": 999})
        rows, stats = match_rows(v3, idx)
        assert rows == [(7301, 7323, "gaia")]
        assert stats["via_gaia"] == 1

    def test_falls_back_through_the_cascade(self):
        v3 = [{"id": "1", "gaia": "", "tyc": "6995-1264-1"}]
        idx = self._indexes(gaia={}, tyc={"6995-1264-1": 42})
        rows, _ = match_rows(v3, idx)
        assert rows == [(1, 42, "tyc")]

    def test_gliese_column_is_gl_in_v3_but_gj_here(self):
        v3 = [{"id": "1", "gl": "Gl 1"}]
        idx = self._indexes(gj={"Gl 1": 7323})
        rows, _ = match_rows(v3, idx)
        assert rows == [(1, 7323, "gj")]

    def test_unmatched_is_counted_not_guessed(self):
        v3 = [{"id": "1", "gaia": "nope"}]
        rows, stats = match_rows(v3, self._indexes(gaia={}))
        assert rows == []
        assert stats["unmatched"] == 1

    def test_ambiguous_identifier_yields_no_match(self):
        """An identifier dropped by build_index must not fall through to a worse key
        and quietly match something else."""
        current = [{"id": 10, "gaia": "999", "hip": "5"},
                   {"id": 11, "gaia": "999", "hip": ""}]
        gaia_idx, _ = build_index(current, "gaia")
        rows, stats = match_rows([{"id": "1", "gaia": "999"}], {"gaia": gaia_idx})
        assert rows == []
        assert stats["unmatched"] == 1

    def test_regression_7301_maps_to_7323(self):
        """The recorded case: GJ 1 / HIP 439 was v3 id 7301 and is v4 id 7323.

        Today ?select_star=7301 shows an unrelated mag-11.5 star in Cepheus.
        """
        v3 = [{"id": "7301", "gaia": "2306965202564744064", "hip": "439"}]
        idx = self._indexes(gaia={"2306965202564744064": 7323})
        rows, _ = match_rows(v3, idx)
        assert rows == [(7301, 7323, "gaia")]


class TestAssertNoDuplicateV3Ids:
    def test_accepts_distinct(self):
        assert_no_duplicate_v3_ids([(1, 10, "gaia"), (2, 11, "gaia")])

    def test_rejects_duplicates(self):
        with pytest.raises(ValueError, match="mapped more than once"):
            assert_no_duplicate_v3_ids([(1, 10, "gaia"), (1, 11, "tyc")])

    def test_two_v3_ids_may_share_a_current_star(self):
        """Legitimate: v4 merged two v3 rows. Only the v3 side must be unique."""
        assert_no_duplicate_v3_ids([(1, 10, "gaia"), (2, 10, "tyc")])


class TestRangeEncoding:
    """The mapping ships as ranges, not one row per id.

    AT-HYG 4 moved the catalog in blocks rather than shuffling it, so `athyg_id - v3_id`
    is constant across long runs. Encoding those runs took the shipped file from 51 MB to
    2 MB — under GitHub's 50 MB warning threshold, which matters because the v3.3 source
    was deleted upstream and this derived file is the durable artifact.

    The property that has to hold is round-tripping: 11_import_athyg_v3_ids.sql expands
    these ranges with generate_series, so an encoding bug becomes 2.5M wrong legacy links
    rather than a visible failure.
    """

    def test_a_contiguous_run_becomes_one_range(self):
        rows = [(10, 110, "gaia"), (11, 111, "gaia"), (12, 112, "gaia")]
        assert to_ranges(rows) == [(10, 12, 100, "gaia")]

    def test_a_changed_offset_breaks_the_range(self):
        """Consecutive ids, but the second block moved by a different amount."""
        rows = [(10, 110, "gaia"), (11, 111, "gaia"), (12, 500, "gaia")]
        assert to_ranges(rows) == [(10, 11, 100, "gaia"), (12, 12, 488, "gaia")]

    def test_a_changed_method_breaks_the_range(self):
        """Same offset either side, but match_method is data, not a detail to smooth over."""
        rows = [(10, 110, "gaia"), (11, 111, "tyc"), (12, 112, "gaia")]
        assert to_ranges(rows) == [
            (10, 10, 100, "gaia"),
            (11, 11, 100, "tyc"),
            (12, 12, 100, "gaia"),
        ]

    def test_a_gap_breaks_the_range(self):
        """
        The 22 v3 stars that did not survive to v4 leave real gaps. Expanding across one
        would invent a mapping for a dead link — the exact silent-wrong-star failure this
        feature exists to end — so the gap must survive the encoding.
        """
        rows = [(10, 110, "gaia"), (11, 111, "gaia"), (13, 113, "gaia")]
        assert to_ranges(rows) == [(10, 11, 100, "gaia"), (13, 13, 100, "gaia")]

    def test_the_gap_is_absent_after_expansion(self):
        """Stated as the consequence, not just the representation."""
        expanded = from_ranges(to_ranges([(10, 110, "gaia"), (13, 113, "gaia")]))
        assert [v3 for v3, _, _ in expanded] == [10, 13]

    def test_a_negative_offset_survives(self):
        """v4 ids are not always larger; 636 stars kept their id and others moved down."""
        rows = [(500, 100, "tyc"), (501, 101, "tyc")]
        assert to_ranges(rows) == [(500, 501, -400, "tyc")]
        assert from_ranges(to_ranges(rows)) == rows

    def test_a_zero_offset_survives(self):
        """The 636 stars whose id did not change. Offset 0 is a value, not a missing one."""
        rows = [(7, 7, "gaia"), (8, 8, "gaia")]
        assert to_ranges(rows) == [(7, 8, 0, "gaia")]
        assert from_ranges(to_ranges(rows)) == rows

    def test_unsorted_input_round_trips(self):
        """run() sorts before encoding; this pins that the encoder does not depend on it."""
        rows = [(12, 112, "gaia"), (10, 110, "gaia"), (11, 111, "gaia")]
        assert to_ranges(rows) == [(10, 12, 100, "gaia")]
        assert from_ranges(to_ranges(rows)) == sorted(rows)

    def test_empty_input(self):
        assert to_ranges([]) == []
        assert from_ranges([]) == []

    def test_round_trip_over_a_mixed_mapping(self):
        """
        The property, over one input carrying every case at once: contiguous blocks, a
        gap, an offset change, a method change, and two v3 ids sharing one current star.
        """
        rows = sorted([
            (2, 2, "gaia"),
            (3, 3, "gaia"),
            (4, 900, "gaia"),
            (5, 901, "gaia"),
            (6, 902, "tyc"),
            (9, 905, "gaia"),
            (10, 905, "tyc"),
        ])
        ranges = to_ranges(rows)
        assert from_ranges(ranges) == rows
        assert len(ranges) < len(rows)

    def test_the_regression_case_survives_encoding(self):
        """7301 -> 7323 is the case the whole feature is named for."""
        expanded = from_ranges(to_ranges([(7301, 7323, "gaia")]))
        assert expanded == [(7301, 7323, "gaia")]
