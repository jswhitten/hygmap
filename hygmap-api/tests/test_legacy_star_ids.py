"""
Tests for GET /api/stars/legacy/{v3_id} — resolving AT-HYG v3.3 ids.

The AT-HYG 4 migration renumbered every star, so links saved before it point at a
different object. These tests pin the behaviour that makes that recoverable, and — just as
importantly — pin that the endpoint does NOT try to guess whether an incoming id is legacy.
"""

import pytest


@pytest.mark.anyio
class TestLegacyStarLookup:
    async def test_resolves_a_legacy_id_to_the_current_star(self, client):
        resp = await client.get("/api/stars/legacy/7301")
        assert resp.status_code == 200
        body = resp.json()
        assert body["result"] == "success"
        assert body["v3_id"] == 7301
        assert body["data"]["id"] == 3
        assert body["data"]["proper"] == "Sirius"

    async def test_reports_how_the_match_was_made(self, client):
        """Provenance, so a doubtful mapping can be traced back to the identifier used."""
        body = (await client.get("/api/stars/legacy/7301")).json()
        assert body["match_method"] == "gaia"

    async def test_unknown_legacy_id_is_404(self, client):
        """Not every old id resolves: 22 v3.3 stars did not survive to v4, and the matcher
        refuses identifiers shared by two real binary components rather than guessing."""
        resp = await client.get("/api/stars/legacy/424242")
        assert resp.status_code == 404

    async def test_two_legacy_ids_may_name_one_current_star(self, client):
        """AT-HYG 4 merged some v3.3 rows. Both old links must still resolve — this is the
        case a column on athyg could not represent."""
        a = await client.get("/api/stars/legacy/9001")
        b = await client.get("/api/stars/legacy/9002")
        assert a.status_code == b.status_code == 200
        assert a.json()["data"]["id"] == b.json()["data"]["id"] == 4
        assert a.json()["match_method"] == "gaia"
        assert b.json()["match_method"] == "tyc"

    async def test_legacy_lookup_is_not_the_same_as_a_current_lookup(self, client):
        """The whole point, and the reason a bare id cannot be resolved safely.

        Id 2 is valid under BOTH schemes and names a different star under each: as a
        current id it is Proxima Centauri, as a v3 id it meant Sirius. In the real
        catalogue this is not an edge case — 99.99% of v3 ids are also a valid, different
        v4 id. ?select_star=7301 shows an anonymous mag-11.5 star in Cepheus today; it
        used to mean GJ 1, mag 8.755, in Sculptor.
        """
        legacy = (await client.get("/api/stars/legacy/2")).json()
        current_resp = await client.get("/api/stars/2")
        assert current_resp.status_code == 200
        current = current_resp.json()

        assert current["data"]["proper"] == "Proxima Centauri"
        assert legacy["data"]["proper"] == "Sirius"
        assert legacy["data"]["id"] != current["data"]["id"]

    async def test_an_id_that_is_both_resolves_differently_by_route(self, client):
        """v3 id 5 maps to star 5, which is also a current id. The endpoint must answer
        from the mapping table, not fall through to treating it as a current id."""
        legacy = (await client.get("/api/stars/legacy/5")).json()
        assert legacy["data"]["id"] == 5
        assert legacy["match_method"] == "hip"

    async def test_route_does_not_shadow_the_star_detail_route(self, client):
        """`/legacy/{id}` is declared before `/{star_id}`; if that ordering were lost,
        FastAPI would try to parse 'legacy' as an int star_id and return 422."""
        resp = await client.get("/api/stars/3")
        assert resp.status_code == 200
        assert resp.json()["data"]["proper"] == "Sirius"

    async def test_non_integer_legacy_id_is_rejected(self, client):
        resp = await client.get("/api/stars/legacy/abc")
        assert resp.status_code == 422

    async def test_world_id_supplies_the_fictional_name(self, client):
        """A legacy link into a fictional universe should still name the star the way that
        universe does — this is the audience most likely to have old bookmarks."""
        resp = await client.get("/api/stars/legacy/7301?world_id=1")
        assert resp.status_code == 200
        assert "name" in resp.json()["data"]
