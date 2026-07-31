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

import pytest
from httpx import AsyncClient

from app.api.stars import ORDER_CLAUSES

# The tie group in conftest.py: five stars sharing absmag 9.99, alone in a 25..35 box.
TIE_BOX = {
    "xmin": 25, "xmax": 35,
    "ymin": 25, "ymax": 35,
    "zmin": 25, "zmax": 35,
}
TIED_IDS = [14, 15, 16, 17, 18]


class TestTieBreakingIsActuallyExercised:
    """
    The behavioural half of this file: these call the real route.

    Added after an audit (2026-07-31) found the nondeterminism fix was tested only by
    regex and never by calling the endpoint. Writing them turned up a limitation worth
    recording, because it changes what the rest of this file is for.

    **These tests cannot detect the tiebreaker being deleted, on SQLite.** `conftest.py`
    declares `id INTEGER PRIMARY KEY`, which in SQLite makes `id` an alias for `rowid`, so
    a table scan already yields rows in id order and the sorter preserves it for tied keys.
    Verified by mutation: removing `, a.id` from every clause in `ORDER_CLAUSES` leaves all
    of these green. Postgres has no such coincidence — its scan order is physical row order,
    which is why the bug was real in production and invisible here.

    What each layer actually catches, both confirmed by mutation:

    | Mutation                | Shape tests | These tests |
    |-------------------------|-------------|-------------|
    | tiebreaker removed      | fail        | pass        |
    | tiebreaker reversed     | fail        | fail        |

    So the string and regex assertions elsewhere in this module are not redundant scaffolding
    around a "real" test — on this database engine they are the only thing standing between
    a deleted tiebreaker and a silent return of the original bug. Do not delete them in
    favour of these. The honest full-strength check is a repeated request against Postgres
    with real tied data, which is what the manual verification in
    `.claude/features/complete/WIDE-ZOOM-QUERY.md` did once by hand.
    """

    async def test_the_same_request_twice_returns_identical_results(
        self, client: AsyncClient
    ):
        """The literal bug: three identical runs once returned three different star sets."""
        first = await client.get("/api/stars/", params={**TIE_BOX, "limit": 3})
        second = await client.get("/api/stars/", params={**TIE_BOX, "limit": 3})

        assert first.status_code == second.status_code == 200
        assert first.json()["data"] == second.json()["data"]

    async def test_a_limit_cutting_through_a_tie_group_takes_the_lowest_ids(
        self, client: AsyncClient
    ):
        """
        Pins *which* rows the tiebreaker selects, not merely that it is stable.

        All five candidates have the same absmag, so absmag alone cannot choose between
        them and any three would be a valid answer without a tiebreaker. `a.id` ascending
        makes 14, 15, 16 the only correct one. The fixture lists them in descending id
        order so insertion order and id order disagree.
        """
        response = await client.get("/api/stars/", params={**TIE_BOX, "limit": 3})
        assert response.status_code == 200

        ids = [s["id"] for s in response.json()["data"]]
        assert ids == [14, 15, 16]

    async def test_the_whole_tie_group_comes_back_in_id_order(
        self, client: AsyncClient
    ):
        response = await client.get("/api/stars/", params={**TIE_BOX, "limit": 100})
        assert response.status_code == 200

        ids = [s["id"] for s in response.json()["data"]]
        assert ids == TIED_IDS

    @pytest.mark.parametrize("order", ["absmag", "absmag asc", "mag", "dist", "proper"])
    async def test_every_order_is_stable_across_identical_requests(
        self, client: AsyncClient, order
    ):
        """
        Not just the default. `mag`, `dist` and `proper` are all NULL for the tie group, so
        their sort keys are entirely tied — which makes them the strongest available test
        of the tiebreaker, since nothing else can order these rows at all.
        """
        params = {**TIE_BOX, "limit": 3, "order": order}
        first = await client.get("/api/stars/", params=params)
        second = await client.get("/api/stars/", params=params)

        assert first.status_code == second.status_code == 200
        first_ids = [s["id"] for s in first.json()["data"]]
        assert first_ids == [s["id"] for s in second.json()["data"]]
        assert first_ids == [14, 15, 16], f"order={order!r} did not fall back to id order"

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
