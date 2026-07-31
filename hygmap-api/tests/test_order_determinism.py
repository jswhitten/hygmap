"""
Guards for WIDE-ZOOM-QUERY.

Two things need pinning, and neither is expressible as an ordinary request assertion.

1. Every ORDER BY must end with a unique tiebreaker. Without one the endpoint is
   *nondeterministic*: sort keys are heavily tied (2,784,293 of 2,839,957 stars share an
   absmag with another star), so a LIMIT cuts through a tie group and returns whichever
   rows the scan reached first. Three identical runs of one wide-zoom query, same plan,
   were measured returning three different star sets before this was fixed.

2. The index that makes wide zoom fast depends on its exact column order, and nothing else
   in the codebase says so. `(absmag, id, x, y, z)` is not arbitrary: absmag leads so the
   index supplies the sort, id sits immediately after it so (absmag, id) satisfies the
   ORDER BY with no sort node, and x/y/z trail so they can be applied as Index Cond without
   a heap fetch. Reordering or trimming it silently returns the query to a sequential scan
   plus a disk-based sort -- which is the regression this feature existed to fix, and which
   had already survived two audits.

The plan shape itself needs Postgres and 2.8M rows, so it cannot be asserted here; the
measured plans and timings are recorded in db/sql/02_create_indexes.sql and the feature
file. These tests guard the two inputs that determine it.
"""
import os
import re

from app.api.stars import ORDER_CLAUSES

INDEX_SQL = os.path.join(
    os.path.dirname(__file__), "..", "..", "db", "sql", "02_create_indexes.sql"
)


class TestOrderClausesAreTotalOrders:
    def test_every_clause_ends_with_the_id_tiebreaker(self):
        missing = [k for k, v in ORDER_CLAUSES.items() if not v.strip().endswith("a.id")]
        assert not missing, (
            f"these order clauses have no unique tiebreaker and are therefore "
            f"nondeterministic under LIMIT: {missing}"
        )

    def test_the_tiebreaker_directly_follows_the_sort_key(self):
        """
        Anything between the sort key and the tiebreaker breaks the index's ability to
        satisfy the ordering, reintroducing the sort node.
        """
        for key, clause in ORDER_CLAUSES.items():
            terms = [t.strip() for t in clause.split(",")]
            assert len(terms) == 2, f"{key!r} has an unexpected shape: {clause!r}"
            assert terms[1] == "a.id", f"{key!r} does not end with the tiebreaker"

    def test_every_documented_order_is_present(self):
        """The four sort fields the API advertises, ascending and descending."""
        for field in ("absmag", "mag", "proper", "dist"):
            assert field in ORDER_CLAUSES
            assert f"{field} asc" in ORDER_CLAUSES
            assert f"{field} desc" in ORDER_CLAUSES


class TestWideZoomIndexShape:
    """
    Reads the DDL rather than the live database so it runs in the ordinary suite. A missing
    file fails loudly instead of skipping -- see TestProdComposeTrustSetting for why that
    matters here.
    """

    @staticmethod
    def _ddl():
        assert os.path.exists(INDEX_SQL), (
            f"{INDEX_SQL} not found. This test must not be skipped: mount db/ into the "
            "container (see the test-api target in the Makefile)."
        )
        with open(INDEX_SQL) as fh:
            return fh.read()

    def test_the_wide_zoom_index_exists_with_exactly_this_column_order(self):
        ddl = self._ddl()
        match = re.search(
            r"CREATE INDEX idx_athyg_absmag_bbox ON athyg\s*\(([^)]*)\)", ddl
        )
        assert match, "idx_athyg_absmag_bbox is missing from 02_create_indexes.sql"

        columns = [c.strip() for c in match.group(1).split(",")]
        assert columns == ["absmag", "id", "x", "y", "z"], (
            f"column order changed to {columns}; see the comment above the index -- the "
            "order is what keeps the sort node out of the plan"
        )

    def test_the_reasoning_is_recorded_next_to_the_index(self):
        """
        The measured selectivity figures are the argument for why no spatial index can help
        here. Losing them means the next person re-derives it or, worse, 'fixes' this by
        adding another index on (x, y, z).
        """
        ddl = self._ddl()
        assert "92.11%" in ddl, "the measured wide-zoom selectivity figures were removed"
        assert "Index Cond" in ddl, "the explanation of why x/y/z trail was removed"
