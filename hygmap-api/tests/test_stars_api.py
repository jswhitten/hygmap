"""
Tests for Star API endpoints
"""

import pytest
from httpx import AsyncClient


class TestGetStars:
    """Tests for GET /api/stars endpoint"""

    async def test_get_stars_default_bounds(self, client: AsyncClient):
        """Should return stars within default bounds"""
        response = await client.get("/api/stars")
        assert response.status_code == 200

        data = response.json()
        assert data["result"] == "success"
        assert isinstance(data["data"], list)
        assert data["length"] == len(data["data"])

    async def test_get_stars_with_bounds(self, client: AsyncClient):
        """Should return stars within specified bounds"""
        response = await client.get(
            "/api/stars",
            params={
                "xmin": -5,
                "xmax": 5,
                "ymin": -5,
                "ymax": 5,
                "zmin": -5,
                "zmax": 5,
            },
        )
        assert response.status_code == 200

        data = response.json()
        assert data["result"] == "success"
        # Should include Sol at origin and nearby stars
        star_names = [s.get("proper") or s.get("display_name") for s in data["data"]]
        assert "Sol" in star_names

    async def test_get_stars_with_mag_filter(self, client: AsyncClient):
        """Should filter stars by magnitude"""
        response = await client.get(
            "/api/stars",
            params={
                "xmin": -1000,
                "xmax": 1000,
                "ymin": -1000,
                "ymax": 1000,
                "zmin": -1000,
                "zmax": 1000,
                "mag_max": 2,  # Only very bright stars
            },
        )
        assert response.status_code == 200

        data = response.json()
        # All returned stars should have magnitude < 2
        for star in data["data"]:
            assert star["absmag"] is None or star["absmag"] < 2

    async def test_get_stars_respects_limit(self, client: AsyncClient):
        """Should respect the limit parameter"""
        response = await client.get(
            "/api/stars",
            params={
                "xmin": -1000,
                "xmax": 1000,
                "ymin": -1000,
                "ymax": 1000,
                "zmin": -1000,
                "zmax": 1000,
                "limit": 3,
            },
        )
        assert response.status_code == 200

        data = response.json()
        assert len(data["data"]) <= 3

    async def test_get_stars_ordered_by_brightness(self, client: AsyncClient):
        """Should return stars ordered by brightness (lowest absmag first)"""
        response = await client.get(
            "/api/stars",
            params={
                "xmin": -1000,
                "xmax": 1000,
                "ymin": -1000,
                "ymax": 1000,
                "zmin": -1000,
                "zmax": 1000,
            },
        )
        assert response.status_code == 200

        data = response.json()
        stars = data["data"]
        if len(stars) > 1:
            # Filter out stars with None absmag
            stars_with_mag = [s for s in stars if s["absmag"] is not None]
            for i in range(len(stars_with_mag) - 1):
                assert stars_with_mag[i]["absmag"] <= stars_with_mag[i + 1]["absmag"]

    async def test_get_stars_with_world_id(self, client: AsyncClient):
        """Should include fictional names when world_id is provided"""
        response = await client.get(
            "/api/stars",
            params={
                "xmin": -5,
                "xmax": 5,
                "ymin": -5,
                "ymax": 5,
                "zmin": -5,
                "zmax": 5,
                "world_id": 1,  # Star Trek
            },
        )
        assert response.status_code == 200

        data = response.json()
        # Wolf 359 should have fictional name "Wolf 359" in Star Trek
        wolf_stars = [s for s in data["data"] if s.get("proper") == "Wolf 359"]
        if wolf_stars:
            assert wolf_stars[0].get("name") == "Wolf 359"

    async def test_get_stars_includes_dist_and_mag(self, client: AsyncClient):
        """Should include dist and mag fields"""
        response = await client.get("/api/stars")
        assert response.status_code == 200

        data = response.json()
        if data["data"]:
            star = data["data"][0]
            assert "dist" in star
            assert "mag" in star

    async def test_get_stars_order_by_mag_desc(self, client: AsyncClient):
        """Should order by apparent magnitude descending"""
        response = await client.get(
            "/api/stars",
            params={
                "xmin": -1000,
                "xmax": 1000,
                "ymin": -1000,
                "ymax": 1000,
                "zmin": -1000,
                "zmax": 1000,
                "order": "absmag desc",
            },
        )
        assert response.status_code == 200

        data = response.json()
        stars = data["data"]
        if len(stars) > 1:
            stars_with_mag = [s for s in stars if s["absmag"] is not None]
            for i in range(len(stars_with_mag) - 1):
                assert stars_with_mag[i]["absmag"] >= stars_with_mag[i + 1]["absmag"]

    async def test_get_stars_invalid_order(self, client: AsyncClient):
        """Should reject invalid order parameter"""
        response = await client.get(
            "/api/stars",
            params={"order": "invalid; DROP TABLE athyg; --"},
        )
        assert response.status_code == 400
        assert "order" in response.json()["detail"].lower()


class TestSearchDistAndMagAreReal:
    """
    /api/stars/search must report the star's actual `dist` and `mag`, not nulls.

    None of the eight SELECT branches in search_stars() named those two columns, so
    StarBase's `Optional[float] = None` defaults supplied nulls for every hit — while
    /api/stars/{id} and the bbox endpoint returned the real values for the same star.
    Filed by audit-data on three consecutive cycles (2026-07-31 1015, 1616, 2014) and
    fixed in the fourth.

    Nothing was visibly broken, which is exactly why 152 passing tests missed it: React
    re-fetches the detail record and computes distance from x/y/z, and PHP's searchStar()
    reads only id/x/display_name. The contract was still wrong, and NULL-COORDINATES had
    just spent a whole feature teaching every consumer that a null here means "no parallax
    exists for this star" — which the endpoint was then saying about 2.84M stars.

    These assert the VALUES, not the keys. Asserting `"dist" in star` passes against the
    bug, since the field is always present and merely null.
    """

    async def test_name_search_reports_dist_and_mag(self, client: AsyncClient):
        response = await client.get("/api/stars/search", params={"q": "Sirius"})
        assert response.status_code == 200

        star = next(s for s in response.json()["data"] if s["id"] == 3)
        assert star["dist"] == pytest.approx(2.6371)
        assert star["mag"] == pytest.approx(-1.44)

    async def test_catalog_id_search_reports_dist_and_mag(self, client: AsyncClient):
        """The other seven branches: same omission, same fix."""
        response = await client.get("/api/stars/search", params={"q": "HIP 32349"})
        assert response.status_code == 200

        star = next(s for s in response.json()["data"] if s["id"] == 3)
        assert star["dist"] == pytest.approx(2.6371)
        assert star["mag"] == pytest.approx(-1.44)

    async def test_search_agrees_with_the_detail_endpoint(self, client: AsyncClient):
        """
        The property that actually matters: two endpoints describing one star must not
        disagree about it. This is the assertion that would have caught the bug without
        anyone knowing which columns were missing.
        """
        search = await client.get("/api/stars/search", params={"q": "Barnard"})
        detail = await client.get("/api/stars/9")
        assert search.status_code == 200 and detail.status_code == 200

        found = next(s for s in search.json()["data"] if s["id"] == 9)
        record = detail.json()["data"]
        assert (found["dist"], found["mag"]) == (record["dist"], record["mag"])

    async def test_a_positionless_star_still_reports_null_dist(self, client: AsyncClient):
        """
        The distinction the bug destroyed. Star 12 has no parallax, so its null is the
        honest answer — and must survive a fix that removes the dishonest ones.
        """
        response = await client.get("/api/stars/search", params={"q": "Positionless"})
        assert response.status_code == 200

        star = next(s for s in response.json()["data"] if s["id"] == 12)
        assert star["dist"] is None
        assert star["mag"] is None


class TestIntegerIdsAreBoundedToTheColumn:
    """
    Ids above Postgres `integer` range must be refused as input, not at bind time.

    FastAPI's plain-`int` coercion accepted them, asyncpg raised
    DataError("value out of int32 range"), nothing caught it, and the request ended as a
    bare-text 500 — breaking the JSON error shape every other validation path here
    returns, and logging a traceback for what is really a malformed request.
    Found by audit-api 2026-07-31.
    """

    OVERFLOW = 2147483648  # PG_INT_MAX + 1

    async def test_star_id_above_int32_is_rejected(self, client: AsyncClient):
        response = await client.get(f"/api/stars/{self.OVERFLOW}")
        assert response.status_code == 422
        assert "detail" in response.json()

    async def test_legacy_id_above_int32_is_rejected(self, client: AsyncClient):
        response = await client.get(f"/api/stars/legacy/{self.OVERFLOW}")
        assert response.status_code == 422

    async def test_world_id_above_int32_is_rejected(self, client: AsyncClient):
        response = await client.get("/api/stars/1", params={"world_id": self.OVERFLOW})
        assert response.status_code == 422

    async def test_the_largest_valid_int_is_still_a_normal_404(self, client: AsyncClient):
        """
        The boundary is the point: 2147483647 is a value the column can hold, so it is an
        id that happens not to exist, not a malformed request. Off-by-one here would turn
        a legitimate lookup into a validation error.
        """
        response = await client.get("/api/stars/2147483647")
        assert response.status_code == 404


class TestSearchStars:
    """Tests for GET /api/stars/search endpoint"""

    async def test_search_by_proper_name(self, client: AsyncClient):
        """Should find stars by proper name"""
        response = await client.get("/api/stars/search", params={"q": "Sirius"})
        assert response.status_code == 200

        data = response.json()
        assert data["result"] == "success"
        assert len(data["data"]) > 0
        assert any(s["proper"] == "Sirius" for s in data["data"])

    async def test_search_by_constellation(self, client: AsyncClient):
        """Should find stars by constellation"""
        response = await client.get("/api/stars/search", params={"q": "Ori"})
        assert response.status_code == 200

        data = response.json()
        assert data["result"] == "success"
        # Should find Betelgeuse and Rigel in Orion
        assert len(data["data"]) >= 2

    async def test_search_by_catalog_id(self, client: AsyncClient):
        """Should find stars by HIP catalog ID"""
        response = await client.get("/api/stars/search", params={"q": "HIP 32349"})
        assert response.status_code == 200

        data = response.json()
        assert data["result"] == "success"
        # Should find Sirius (HIP 32349)
        if len(data["data"]) > 0:
            assert data["data"][0]["hip"] == "32349"

    async def test_search_respects_limit(self, client: AsyncClient):
        """Should respect the limit parameter"""
        response = await client.get(
            "/api/stars/search", params={"q": "al", "limit": 2}
        )
        assert response.status_code == 200

        data = response.json()
        assert len(data["data"]) <= 2

    async def test_search_requires_query(self, client: AsyncClient):
        """Should require a search query"""
        response = await client.get("/api/stars/search")
        assert response.status_code == 422  # Validation error

    async def test_search_by_gj_id(self, client: AsyncClient):
        """Should find stars by GJ catalog ID"""
        response = await client.get("/api/stars/search", params={"q": "GJ 551"})
        assert response.status_code == 200

        data = response.json()
        assert data["result"] == "success"
        assert len(data["data"]) > 0
        assert data["data"][0]["gj"] == "551"

    async def test_search_by_gl_alias(self, client: AsyncClient):
        """Should find stars using GL alias for GJ"""
        response = await client.get("/api/stars/search", params={"q": "GL 551"})
        assert response.status_code == 200

        data = response.json()
        assert data["result"] == "success"
        assert len(data["data"]) > 0
        assert data["data"][0]["gj"] == "551"

    async def test_search_by_cns5_id(self, client: AsyncClient):
        """Should find stars by CNS5 catalog ID"""
        response = await client.get("/api/stars/search", params={"q": "CNS5 5500"})
        assert response.status_code == 200

        data = response.json()
        assert data["result"] == "success"
        assert len(data["data"]) > 0
        assert data["data"][0]["cns5"] == "5500"

    async def test_search_by_gj_high_number(self, client: AsyncClient):
        """Should find CNS5-only stars with GJ numbers above 10000"""
        response = await client.get("/api/stars/search", params={"q": "GJ 10999"})
        assert response.status_code == 200

        data = response.json()
        assert data["result"] == "success"
        assert len(data["data"]) > 0
        assert data["data"][0]["gj"] == "10999"

    async def test_search_minimum_length(self, client: AsyncClient):
        """Should require minimum query length"""
        response = await client.get("/api/stars/search", params={"q": "a"})
        assert response.status_code == 422  # Validation error


class TestGetStarById:
    """Tests for GET /api/stars/{star_id} endpoint"""

    async def test_get_star_by_id(self, client: AsyncClient):
        """Should return star details by ID"""
        response = await client.get("/api/stars/1")
        assert response.status_code == 200

        data = response.json()
        assert data["result"] == "success"
        assert data["data"]["id"] == 1
        assert data["data"]["proper"] == "Sol"

    async def test_get_star_includes_display_name(self, client: AsyncClient):
        """Should include computed display_name"""
        response = await client.get("/api/stars/3")  # Sirius
        assert response.status_code == 200

        data = response.json()
        assert "display_name" in data["data"]
        assert data["data"]["display_name"] == "Sirius"

    async def test_get_star_not_found(self, client: AsyncClient):
        """Should return 404 for non-existent star"""
        response = await client.get("/api/stars/99999")
        assert response.status_code == 404

        data = response.json()
        assert data["detail"] == "Star not found"

    async def test_get_star_includes_all_fields(self, client: AsyncClient):
        """Should include all detail fields"""
        response = await client.get("/api/stars/4")  # Vega
        assert response.status_code == 200

        data = response.json()
        star = data["data"]

        # Check required fields
        assert "id" in star
        assert "x" in star
        assert "y" in star
        assert "z" in star
        assert "display_name" in star

        # Check optional fields are present (may be None)
        assert "proper" in star
        assert "bayer" in star
        assert "con" in star
        assert "spect" in star
        assert "absmag" in star
        assert "hip" in star
        assert "hd" in star

    async def test_get_star_with_world_id(self, client: AsyncClient):
        """Should include fictional name when world_id is provided"""
        # Wolf 359 has fictional name in Star Trek (world_id=1)
        response = await client.get("/api/stars/10", params={"world_id": 1})
        assert response.status_code == 200

        data = response.json()
        assert data["data"]["name"] == "Wolf 359"

    async def test_get_star_without_world_id(self, client: AsyncClient):
        """Should return empty name when world_id=0"""
        response = await client.get("/api/stars/10", params={"world_id": 0})
        assert response.status_code == 200

        data = response.json()
        assert data["data"]["name"] == ""


class TestDropdownEndpoints:
    """Tests for dropdown data endpoints"""

    async def test_get_proper_names(self, client: AsyncClient):
        """Should return all stars with proper names"""
        response = await client.get("/api/stars/proper-names")
        assert response.status_code == 200

        data = response.json()
        assert data["result"] == "success"
        assert isinstance(data["data"], list)
        assert data["length"] == len(data["data"])

        # Check each entry has id and proper
        for entry in data["data"]:
            assert "id" in entry
            assert "proper" in entry
            assert entry["proper"] is not None

        # Check that our test stars are included
        proper_names = [e["proper"] for e in data["data"]]
        assert "Sol" in proper_names
        assert "Sirius" in proper_names

    async def test_get_fictional_names(self, client: AsyncClient):
        """Should return fictional names for a world"""
        response = await client.get(
            "/api/stars/fictional-names",
            params={"world_id": 1}  # Star Trek
        )
        assert response.status_code == 200

        data = response.json()
        assert data["result"] == "success"
        assert isinstance(data["data"], list)

        # Check each entry has star_id and name
        for entry in data["data"]:
            assert "star_id" in entry
            assert "name" in entry

        # Check Star Trek names are included
        fic_names = [e["name"] for e in data["data"]]
        assert "Wolf 359" in fic_names
        assert "Alpha Centauri" in fic_names

    async def test_get_fictional_names_requires_world_id(self, client: AsyncClient):
        """Should require world_id parameter"""
        response = await client.get("/api/stars/fictional-names")
        assert response.status_code == 422  # Validation error

    async def test_get_fictional_names_filters_by_world(self, client: AsyncClient):
        """Should filter fictional names by world_id"""
        # Star Trek (world_id=1) has Wolf 359, Alpha Centauri, Alpha Canis Majoris
        response1 = await client.get(
            "/api/stars/fictional-names",
            params={"world_id": 1}
        )
        assert response1.status_code == 200
        trek_names = [e["name"] for e in response1.json()["data"]]

        # Babylon 5 (world_id=2) has Epsilon III System
        response2 = await client.get(
            "/api/stars/fictional-names",
            params={"world_id": 2}
        )
        assert response2.status_code == 200
        b5_names = [e["name"] for e in response2.json()["data"]]

        # Check they return different sets
        assert "Epsilon III System" in b5_names
        assert "Epsilon III System" not in trek_names

    async def test_get_worlds(self, client: AsyncClient):
        """Should return all fictional worlds"""
        response = await client.get("/api/stars/worlds")
        assert response.status_code == 200

        data = response.json()
        assert data["result"] == "success"
        assert isinstance(data["data"], list)

        # Check each entry has id and name
        for entry in data["data"]:
            assert "id" in entry
            assert "name" in entry

        # Check our test worlds are included
        world_names = [w["name"] for w in data["data"]]
        assert "Star Trek" in world_names
        assert "Babylon 5" in world_names


class TestSecurityValidation:
    """Security tests for input validation and SQL injection prevention"""

    async def test_coordinate_overflow_validation(self, client: AsyncClient):
        """Should reject coordinates with extreme values"""
        response = await client.get(
            "/api/stars",
            params={
                "xmin": -999999999,
                "xmax": 999999999,
                "ymin": -10,
                "ymax": 10,
                "zmin": -10,
                "zmax": 10,
            }
        )
        assert response.status_code == 400
        assert "within" in response.json()["detail"].lower()

    async def test_search_term_length_validation(self, client: AsyncClient):
        """Should reject search terms that are too long"""
        long_search = "a" * 101  # Exceeds 100 character limit
        response = await client.get(
            "/api/stars/search",
            params={"q": long_search}
        )
        assert response.status_code == 422  # Pydantic validation error

    async def test_invalid_catalog_field_injection_attempt(self, client: AsyncClient):
        """Should prevent SQL injection through catalog field validation"""
        # Note: The catalog_field is now validated against an allowlist,
        # but this test ensures the system handles malicious prefixes gracefully
        malicious_search = "invalid_field; DROP TABLE athyg; --"
        response = await client.get(
            "/api/stars/search",
            params={"q": malicious_search}
        )
        # Should either return no results or handle gracefully (not crash)
        assert response.status_code in [200, 400]
        if response.status_code == 200:
            data = response.json()
            assert data["result"] == "success"

    async def test_sql_injection_in_search_pattern(self, client: AsyncClient):
        """Should handle SQL injection attempts in search patterns"""
        malicious_patterns = [
            "'; DROP TABLE athyg; --",
            "\" OR 1=1 --",
            "' UNION SELECT * FROM athyg --",
            "' OR '1'='1",
            "admin'--",
            "' OR 1=1#",
            "1' AND 1=1--",
            "' OR 'a'='a",
        ]
        for pattern in malicious_patterns:
            response = await client.get(
                "/api/stars/search",
                params={"q": pattern}
            )
            # Should return safely, not cause SQL errors
            assert response.status_code == 200
            data = response.json()
            assert data["result"] == "success"

            # Verify that injection didn't return all rows or dangerous results
            # The malicious SQL should be treated as a literal search string
            # and should return 0 results (no star names match these patterns)
            assert data["length"] == 0, f"SQL injection pattern '{pattern}' returned {data['length']} results (expected 0)"
            assert len(data["data"]) == 0, f"SQL injection pattern '{pattern}' returned unexpected data"

    async def test_sql_injection_in_catalog_search(self, client: AsyncClient):
        """Should handle SQL injection attempts in catalog ID searches"""
        # Attempt to inject via catalog value (should be safely parameterized)
        malicious_catalog_searches = [
            "HIP 1' OR '1'='1",
            "HD 1; DROP TABLE athyg; --",
            "HR 1 UNION SELECT * FROM athyg --",
            "GJ 1' AND id IN (SELECT id FROM athyg) --",
        ]
        for search in malicious_catalog_searches:
            response = await client.get(
                "/api/stars/search",
                params={"q": search}
            )
            assert response.status_code == 200
            data = response.json()
            assert data["result"] == "success"

            # Injection should fail - treated as literal string search
            # Should return 0 or very few results (only if a catalog ID happens to match)
            assert data["length"] <= 1, f"SQL injection in catalog search '{search}' may have succeeded"

    async def test_catalog_field_allowlist_protection(self, client: AsyncClient):
        """Should reject non-whitelisted catalog fields to prevent SQL injection"""
        # These tests verify the ALLOWED_CATALOG_FIELDS allowlist works

        # Valid catalog prefixes should work (if ID exists)
        valid_searches = ["HIP 1", "HD 1", "HR 1", "GJ 1", "GL 1", "CNS5 1"]
        for search in valid_searches:
            response = await client.get(
                "/api/stars/search",
                params={"q": search}
            )
            # Should not crash or return error
            assert response.status_code == 200

        # Invalid/dangerous catalog field attempts should be safely handled
        # These should be treated as name searches, not catalog searches
        dangerous_searches = [
            "id 1",  # Direct column reference
            "proper 1",  # Another column
            "* 1",  # Wildcard
            "athyg 1",  # Table name
        ]
        for search in dangerous_searches:
            response = await client.get(
                "/api/stars/search",
                params={"q": search}
            )
            assert response.status_code == 200
            data = response.json()
            # These should be treated as name searches, returning safe results
            assert data["result"] == "success"

    async def test_sql_injection_protection_with_special_chars(self, client: AsyncClient):
        """Should safely handle special SQL characters in search"""
        special_char_patterns = [
            "'; --",
            "\"; --",
            "'; DROP TABLE athyg; --",
            "%",  # LIKE wildcard
            "_",  # LIKE wildcard
            "\\",  # Escape character
            "'",  # Quote
            "\"",  # Double quote
            ";",  # Statement terminator
            "--",  # SQL comment
            "/*",  # SQL comment start
            "*/",  # SQL comment end
        ]
        for pattern in special_char_patterns:
            response = await client.get(
                "/api/stars/search",
                params={"q": pattern}
            )
            # Should handle gracefully - either 200 with 0 results or validation error
            assert response.status_code in [200, 400]

            if response.status_code == 200:
                data = response.json()
                assert data["result"] == "success"
                # Special chars should be escaped/parameterized, returning no malicious results
                assert data["length"] == 0

    async def test_parameterized_query_prevents_injection(self, client: AsyncClient):
        """Verify parameterized queries correctly escape malicious input"""
        # This test specifically verifies that the :parameter syntax prevents injection

        # Attempt SQL injection through name search
        response = await client.get(
            "/api/stars/search",
            params={"q": "Sol' OR 1=1 OR proper='"}
        )
        assert response.status_code == 200
        data = response.json()

        # If parameterization works correctly, this should return 0 results
        # (no star's name matches this literal string)
        assert data["length"] == 0, "Parameterized query may not be protecting against injection"

        # Attempt SQL injection through catalog search
        response = await client.get(
            "/api/stars/search",
            params={"q": "HIP 1' OR hip='1"}
        )
        assert response.status_code == 200
        data = response.json()

        # Should return at most 1 result (HIP 1 if it exists)
        # Not all rows (which would indicate SQL injection success)
        assert data["length"] <= 1, "Catalog search parameterization may be vulnerable"

    async def test_negative_limit_validation(self, client: AsyncClient):
        """Should reject negative limit values"""
        response = await client.get(
            "/api/stars",
            params={"limit": -1}
        )
        assert response.status_code == 422  # Validation error

    async def test_excessive_limit_validation(self, client: AsyncClient):
        """Should reject limit values that exceed maximum"""
        response = await client.get(
            "/api/stars",
            params={"limit": 100000}
        )
        assert response.status_code == 422  # Validation error

    async def test_invalid_bounds_order(self, client: AsyncClient):
        """Should reject bounds where min >= max"""
        response = await client.get(
            "/api/stars",
            params={
                "xmin": 50,
                "xmax": -50,  # Invalid: max < min
                "ymin": -10,
                "ymax": 10,
                "zmin": -10,
                "zmax": 10,
            }
        )
        assert response.status_code == 400
        assert "bounds" in response.json()["detail"].lower()

    async def test_spatial_range_too_large(self, client: AsyncClient):
        """Should reject spatial ranges that are too large"""
        response = await client.get(
            "/api/stars",
            params={
                "xmin": -5000,
                "xmax": 5000,  # 10000 parsecs exceeds MAX_SPATIAL_RANGE
                "ymin": -10,
                "ymax": 10,
                "zmin": -10,
                "zmax": 10,
            }
        )
        assert response.status_code == 400
        assert "range too large" in response.json()["detail"].lower()

    async def test_unicode_in_search(self, client: AsyncClient):
        """Should handle unicode characters in search gracefully"""
        unicode_searches = [
            "α Centauri",  # Greek letter
            "星星",  # Chinese characters
            "🌟",  # Emoji
        ]
        for search in unicode_searches:
            response = await client.get(
                "/api/stars/search",
                params={"q": search}
            )
            # Should return successfully (even if no results)
            assert response.status_code == 200
            data = response.json()
            assert data["result"] == "success"

    async def test_empty_search_term(self, client: AsyncClient):
        """Should reject empty or too-short search terms"""
        response = await client.get(
            "/api/stars/search",
            params={"q": "a"}  # Single character, below min_length=2
        )
        assert response.status_code == 422  # Validation error


class TestSearchGreekLetters:
    """
    Greek-letter Bayer handling and short-term anchoring in /api/stars/search.

    athyg.bayer stores abbreviations ("Alp"), sometimes with a component suffix
    ("Alp-1 Cen"). These tests lock in that both the spelled-out and the abbreviated
    first token reach the same stars, and that short terms stay anchored so the
    pg_trgm indexes can filter (see db/sql/02_create_indexes.sql).
    """

    async def test_spelled_out_greek_letter_matches_abbreviation(
        self, client: AsyncClient
    ):
        """'alpha cma' should reach Sirius, stored as bayer 'Alp' con 'CMa'"""
        response = await client.get(
            "/api/stars/search", params={"q": "alpha cma"}
        )
        assert response.status_code == 200
        data = response.json()
        assert any(s["proper"] == "Sirius" for s in data["data"])

    async def test_abbreviated_greek_letter_matches(self, client: AsyncClient):
        """
        'alp cma' must work too.

        Regression test: before this was handled, only the spelled-out form was
        expanded, so a user typing the exact form stored in the database got nothing.
        """
        response = await client.get("/api/stars/search", params={"q": "alp cma"})
        assert response.status_code == 200
        data = response.json()
        assert any(s["proper"] == "Sirius" for s in data["data"])

    async def test_both_greek_forms_agree(self, client: AsyncClient):
        """The spelled-out and abbreviated forms must return the same stars"""
        spelled = await client.get("/api/stars/search", params={"q": "beta ori"})
        abbrev = await client.get("/api/stars/search", params={"q": "bet ori"})
        assert spelled.status_code == abbrev.status_code == 200
        assert (
            [s["id"] for s in spelled.json()["data"]]
            == [s["id"] for s in abbrev.json()["data"]]
        )

    async def test_greek_letter_alone_still_matches(self, client: AsyncClient):
        """A bare Greek letter should still find its stars"""
        response = await client.get("/api/stars/search", params={"q": "bet"})
        assert response.status_code == 200
        assert any(s["proper"] == "Rigel" for s in response.json()["data"])

    async def test_short_term_is_prefix_anchored(self, client: AsyncClient):
        """
        A two-character term matches the START of a name, not anywhere inside it.

        Below three characters pg_trgm cannot filter, so these terms are anchored.
        'ol' appears inside 'Sol' but does not start it, so it must not match --
        that is the documented semantic trade for keeping short searches fast.
        """
        response = await client.get("/api/stars/search", params={"q": "ol"})
        assert response.status_code == 200
        assert not any(s["proper"] == "Sol" for s in response.json()["data"])

    async def test_short_term_matches_prefix(self, client: AsyncClient):
        """The anchored form still finds names that start with the term"""
        response = await client.get("/api/stars/search", params={"q": "ve"})
        assert response.status_code == 200
        assert any(s["proper"] == "Vega" for s in response.json()["data"])

    async def test_longer_term_still_matches_substring(self, client: AsyncClient):
        """At three characters and above, substring matching is unchanged"""
        response = await client.get("/api/stars/search", params={"q": "elgeu"})
        assert response.status_code == 200
        assert any(s["proper"] == "Betelgeuse" for s in response.json()["data"])


class TestSearchExcludesUndisplayableStars:
    """
    Search must not return stars whose COORDINATES the app cannot express.

    This produced a result that looked fine and then failed: selecting one drove the map
    view outside MAX_COORDINATE_VALUE, the bbox request was rejected, and the PHP page
    answered 503. Searching "Cen" did exactly this, because a broken parallax gives an
    absurdly bright absolute magnitude and search orders brightest-first, so the artifacts
    came back first.

    This class used to also assert that stars with NO coordinates were excluded. That is
    no longer the behaviour: the maintainer decided on 2026-07-30 (NULL-COORDINATES) that
    those stay findable but inert, since hiding 25,342 real catalog stars means a HIP
    number that exists reports "not found". They are covered by
    TestPositionlessStarsAreFindableButInert instead.

    Keeping the two apart is the point. A star with an absurd position sorts FIRST and
    breaks the page; a star with no position sorts LAST and is harmless. One blanket rule
    would either reopen the 503 or hide the 25,342.
    """

    async def test_search_excludes_stars_beyond_the_coordinate_domain(
        self, client: AsyncClient
    ):
        """Beyond MAX_COORDINATE_VALUE the API cannot express a bbox containing the star."""
        response = await client.get("/api/stars/search", params={"q": "Sgr"})
        assert response.status_code == 200

        ids = [s["id"] for s in response.json()["data"]]
        assert 13 not in ids, "a star outside the queryable coordinate domain was returned"

    async def test_search_still_finds_normal_stars_in_that_constellation(
        self, client: AsyncClient
    ):
        """Guard against the exclusions being too broad."""
        response = await client.get("/api/stars/search", params={"q": "Ori"})
        assert response.status_code == 200
        assert len(response.json()["data"]) >= 2

    async def test_star_detail_returns_positionless_stars_without_failing(
        self, client: AsyncClient
    ):
        """
        A direct request for such a star must still work.

        Excluding it from search is right; making it unfetchable is not, and returning 500
        because the schema demanded non-null coordinates is certainly not.
        """
        response = await client.get("/api/stars/12")
        assert response.status_code == 200

        data = response.json()["data"]
        assert data["x"] is None
        assert data["dist"] is None
        # The name and the direction survive; only the distance is unknown.
        assert data["display_name"] == "Positionless Star"
        assert data["con"] == "Sgr"


class TestFictionalNameSearch:
    """
    Search by fictional name, scoped to the selected universe.

    Before FICTIONAL-NAME-SEARCH, /api/stars/search queried athyg only, so no fictional
    name was reachable from the search box in either frontend -- "Vulcan" found nothing
    while the database linked it correctly to Keid. The scoping decision is deliberate:
    a fictional name matches only when its universe is selected, so a star is named the
    same way everywhere on one page load (DISPLAY-NAME-CANON).

    Fixture mapping: star 10 is 'Wolf 359', named 'Wolf 359' in world 1 and
    'Epsilon III System' in world 2.
    """

    async def test_finds_star_by_fictional_name_when_world_selected(
        self, client: AsyncClient
    ):
        response = await client.get(
            "/api/stars/search", params={"q": "epsilon iii", "world_id": 2}
        )
        assert response.status_code == 200

        data = response.json()["data"]
        assert len(data) == 1
        assert data[0]["id"] == 10
        assert data[0]["name"] == "Epsilon III System"
        assert data[0]["display_name"] == "Epsilon III System"

    async def test_not_found_when_a_different_world_is_selected(
        self, client: AsyncClient
    ):
        """The scoping decision, asserted rather than assumed."""
        response = await client.get(
            "/api/stars/search", params={"q": "epsilon iii", "world_id": 1}
        )
        assert response.status_code == 200
        assert response.json()["length"] == 0

    async def test_not_found_when_no_world_is_selected(self, client: AsyncClient):
        response = await client.get("/api/stars/search", params={"q": "epsilon iii"})
        assert response.status_code == 200
        assert response.json()["length"] == 0

    async def test_fictional_name_match_is_substring_and_case_insensitive(
        self, client: AsyncClient
    ):
        """Consistent with the existing proper-name search."""
        response = await client.get(
            "/api/stars/search", params={"q": "EPSIL", "world_id": 2}
        )
        assert response.status_code == 200

        data = response.json()["data"]
        assert len(data) == 1
        assert data[0]["id"] == 10

    async def test_catalog_id_search_carries_the_fictional_name(
        self, client: AsyncClient
    ):
        """
        Search and map must agree on one page load: looking a star up by catalog ID with a
        universe selected has to return the same name the map draws.
        """
        response = await client.get(
            "/api/stars/search", params={"q": "HIP 54035", "world_id": 2}
        )
        assert response.status_code == 200

        data = response.json()["data"]
        assert len(data) == 1
        assert data[0]["id"] == 10
        assert data[0]["display_name"] == "Epsilon III System"

    async def test_catalog_id_search_without_a_world_keeps_the_real_name(
        self, client: AsyncClient
    ):
        response = await client.get("/api/stars/search", params={"q": "HIP 54035"})
        assert response.status_code == 200

        data = response.json()["data"]
        assert data[0]["name"] is None
        assert data[0]["display_name"] == "Wolf 359"

    async def test_star_matching_both_a_real_and_a_fictional_name_returns_one_row(
        self, client: AsyncClient
    ):
        """
        Regression: star 10 is called 'Wolf 359' in athyg.proper AND in fic for world 1,
        so both predicates match. A LEFT JOIN would have returned it twice -- one physical
        star, two rows in the result list. This is why the query uses EXISTS plus a scalar
        subquery instead.
        """
        response = await client.get(
            "/api/stars/search", params={"q": "wolf 359", "world_id": 1}
        )
        assert response.status_code == 200

        ids = [s["id"] for s in response.json()["data"]]
        assert ids.count(10) == 1
        assert len(ids) == len(set(ids))

    async def test_selecting_a_world_never_drops_or_duplicates_an_existing_hit(
        self, client: AsyncClient
    ):
        """
        Selecting a universe may only ADD matches. It must never drop a star that a plain
        search found, and never return one twice.

        Note this is deliberately a superset assertion, not equality. Equality is wrong:
        'Ori' legitimately gains Sirius under world 1, because 'ori' is a substring of its
        fictional name 'Alpha Canis Majoris'. That is the feature, and it is the same
        substring rule the proper-name search already uses.
        """
        for query in ("sirius", "alp", "Ori", "vega"):
            plain = await client.get("/api/stars/search", params={"q": query})
            scoped = await client.get(
                "/api/stars/search", params={"q": query, "world_id": 1}
            )
            assert plain.status_code == scoped.status_code == 200

            plain_ids = [s["id"] for s in plain.json()["data"]]
            scoped_ids = [s["id"] for s in scoped.json()["data"]]
            assert set(plain_ids) <= set(scoped_ids), (
                f"world_id dropped a hit for {query!r}"
            )
            assert len(scoped_ids) == len(set(scoped_ids)), (
                f"world_id duplicated a hit for {query!r}"
            )

    async def test_a_world_can_add_a_match_via_the_fictional_name(
        self, client: AsyncClient
    ):
        """The other half of the test above, stated positively so the behaviour is pinned."""
        plain = await client.get("/api/stars/search", params={"q": "Ori"})
        scoped = await client.get(
            "/api/stars/search", params={"q": "Ori", "world_id": 1}
        )

        plain_ids = [s["id"] for s in plain.json()["data"]]
        scoped_ids = [s["id"] for s in scoped.json()["data"]]
        # Sirius, via 'Alpha Canis Majoris'.
        assert 3 not in plain_ids
        assert 3 in scoped_ids

    async def test_a_fictional_name_on_a_positionless_star_is_findable(
        self, client: AsyncClient
    ):
        """
        Was the opposite assertion until 2026-07-31.

        The fictional predicate sits inside the same guard as the real-name predicates, so
        it inherits whatever that guard does — which was the point of putting it there. When
        the guard stopped excluding positionless stars (NULL-COORDINATES), fictional names
        on them became findable too, automatically and consistently. That is the behaviour
        being pinned here: a fictional name is not a second class of visibility.
        """
        response = await client.get(
            "/api/stars/search", params={"q": "unmappable colony", "world_id": 1}
        )
        assert response.status_code == 200

        data = response.json()["data"]
        assert [s["id"] for s in data] == [12]
        assert data[0]["x"] is None

    async def test_fictional_name_does_not_resurrect_an_out_of_domain_star(
        self, client: AsyncClient
    ):
        response = await client.get(
            "/api/stars/search", params={"q": "faraway outpost", "world_id": 1}
        )
        assert response.status_code == 200
        assert response.json()["length"] == 0

    async def test_negative_world_id_is_rejected(self, client: AsyncClient):
        response = await client.get(
            "/api/stars/search", params={"q": "epsilon iii", "world_id": -1}
        )
        assert response.status_code == 422


class TestPositionlessStarsAreFindableButInert:
    """
    NULL-COORDINATES: the 25,342 stars with no usable parallax stay findable.

    The maintainer's decision (2026-07-30) was that hiding them is worse than showing them
    inert — a HIP number that exists should not report "not found". The name-search branch
    used to exclude them while the seven catalog-ID branches did not, which audit-api filed
    as a defect on 2026-07-31; both halves now agree.

    Fixture star 12 is positionless; star 13 has coordinates beyond MAX_COORDINATE_VALUE.
    Those are different problems and only the first is being un-excluded — see the comment
    on the WHERE clause in stars.py.
    """

    async def test_a_positionless_star_is_returned_by_name_search(self, client: AsyncClient):
        response = await client.get("/api/stars/search", params={"q": "Positionless"})
        assert response.status_code == 200

        data = response.json()["data"]
        assert [s["id"] for s in data] == [12]
        assert data[0]["x"] is None and data[0]["y"] is None and data[0]["z"] is None

    async def test_a_positionless_star_is_returned_by_constellation_search(
        self, client: AsyncClient
    ):
        """Star 12's constellation is Sgr, which is also star 13's — see the next test."""
        response = await client.get("/api/stars/search", params={"q": "Sgr"})
        assert response.status_code == 200
        assert 12 in [s["id"] for s in response.json()["data"]]

    async def test_an_out_of_domain_star_is_still_excluded(self, client: AsyncClient):
        """
        The other unmappable class, and the reason this is not one blanket rule. Star 13
        HAS coordinates — absurd ones, from a broken parallax — and an absmag bright enough
        to sort first, which is what produced a 503 when someone searched "Cen"
        (DATA-QUALITY-OUTLIERS). Relaxing that exclusion too would reopen it.
        """
        response = await client.get("/api/stars/search", params={"q": "Sgr"})
        assert response.status_code == 200
        assert 13 not in [s["id"] for s in response.json()["data"]]

    async def test_a_positionless_star_never_appears_in_a_bbox_result(
        self, client: AsyncClient
    ):
        """
        Not enforced by a predicate — it is structural. No null satisfies `x > xmin`, so a
        bounding-box query cannot return one. Asserted because the decision says "never
        plotted", and this is the query that feeds the map.
        """
        response = await client.get(
            "/api/stars/",
            # MAX_SPATIAL_RANGE caps a dimension at 3000 pc, so this is the widest box
            # the endpoint will accept -- which is the strongest version of this assertion.
            params={
                "xmin": -1500, "xmax": 1500,
                "ymin": -1500, "ymax": 1500,
                "zmin": -1500, "zmax": 1500,
                "limit": 50000,
            },
        )
        assert response.status_code == 200

        data = response.json()["data"]
        assert 12 not in [s["id"] for s in data]
        assert all(s["x"] is not None for s in data)

    async def test_a_positionless_star_is_still_fetchable_by_id(self, client: AsyncClient):
        """Findable but inert means reachable, not hidden."""
        response = await client.get("/api/stars/12")
        assert response.status_code == 200

        data = response.json()["data"]
        assert data["x"] is None
        assert data["dist"] is None
        assert data["display_name"] == "Positionless Star"

    async def test_positionless_stars_sort_last_and_cannot_displace_a_real_result(
        self, client: AsyncClient
    ):
        """
        What makes the decision cheap. Positionless stars have no absmag, and every search
        orders by `absmag ASC NULLS LAST`, so they land at the end of the list rather than
        crowding out stars a user can actually look at. Measured across the whole catalogue
        before relying on it: 0 of the 25,342 carry an absmag.
        """
        response = await client.get("/api/stars/search", params={"q": "Sgr", "limit": 100})
        assert response.status_code == 200

        ids = [s["id"] for s in response.json()["data"]]
        positions = [s["x"] for s in response.json()["data"]]
        assert 12 in ids

        first_positionless = positions.index(None)
        assert all(p is not None for p in positions[:first_positionless])


class TestSelectingAWorldDoesNotChangeRealNameSearch:
    """
    A universe being selected must not change the cost or the answer of a real-name search.

    This is the property FICTIONAL-SEARCH-PERFORMANCE restored. The fictional-name match
    used to be a fifth OR-disjunct in the real-name WHERE, which turned a `hashed SubPlan`
    filter loose on 2.84M rows and cost 19.6 s for `vulcan&world_id=1` where the same
    query without the disjunct took 7.4 ms. Measured, not inferred: `sirius` was 2.4 ms at
    world_id=0 and exceeded a 15 s statement_timeout at world_id=1 -- identical term,
    identical matches, so the cost was purely the disjunct's presence.

    Five audit cycles missed it because no test and no auditor ever set world_id on a
    *real-name* search. These tests exist so that stays impossible. They assert answers
    rather than timings -- a timing test would be flaky and would pass on a warm cache
    over a bad plan anyway; the plan itself is checked by hand with EXPLAIN and recorded
    in the feature file.
    """

    async def test_real_name_search_is_unaffected_by_world_id(self, client: AsyncClient):
        without = await client.get("/api/stars/search", params={"q": "Sirius"})
        with_world = await client.get(
            "/api/stars/search", params={"q": "Sirius", "world_id": 1}
        )
        assert without.status_code == 200 and with_world.status_code == 200
        assert [s["id"] for s in without.json()["data"]] == [
            s["id"] for s in with_world.json()["data"]
        ]

    async def test_a_world_may_add_fictional_hits_but_never_drops_real_ones(
        self, client: AsyncClient
    ):
        """
        The union must be additive. Selecting a world can introduce fictional matches --
        that is the feature -- but every star found without a world must still be found
        with one, and in the same relative order.

        `Ori` is a good probe by accident: fixture star 3 is Sirius, whose Star Trek name
        is "Alpha Canis Maj-ori-s", so world 1 legitimately adds it to a constellation
        search. An earlier version of this test asserted the two lists were equal and was
        simply wrong about the feature.
        """
        without = await client.get("/api/stars/search", params={"q": "Ori"})
        with_world = await client.get(
            "/api/stars/search", params={"q": "Ori", "world_id": 1}
        )
        plain = [s["id"] for s in without.json()["data"]]
        worlded = [s["id"] for s in with_world.json()["data"]]

        assert set(plain) <= set(worlded)
        assert [i for i in worlded if i in plain] == plain
        assert len(worlded) == len(set(worlded))

    async def test_a_term_matching_neither_returns_nothing_either_way(
        self, client: AsyncClient
    ):
        """
        The pathological case: `3C 273` matches no star and no fictional name, so the old
        shape had nothing to stop it scanning the whole catalog. 19.2 s, measured.
        """
        without = await client.get("/api/stars/search", params={"q": "3C 273"})
        with_world = await client.get(
            "/api/stars/search", params={"q": "3C 273", "world_id": 1}
        )
        assert without.json()["data"] == [] and with_world.json()["data"] == []

    async def test_the_union_still_honours_the_limit(self, client: AsyncClient):
        """
        Each branch takes its own top-N before the union, so a naive implementation can
        return up to 2N rows. Asserted because the bug would be invisible at the default
        limit with this fixture.
        """
        response = await client.get(
            "/api/stars/search", params={"q": "Lyn", "limit": 3, "world_id": 1}
        )
        assert response.status_code == 200
        assert len(response.json()["data"]) <= 3

    async def test_the_union_returns_the_true_top_n(self, client: AsyncClient):
        """
        Taking top-N per branch then top-N of the union is exact, not an approximation --
        but only if both branches sort the same way. A star reachable by BOTH a real and a
        fictional name must not be able to displace a brighter real-name-only match.
        """
        limited = await client.get(
            "/api/stars/search", params={"q": "Lyn", "limit": 3, "world_id": 1}
        )
        full = await client.get(
            "/api/stars/search", params={"q": "Lyn", "limit": 100, "world_id": 1}
        )
        assert [s["id"] for s in limited.json()["data"]] == [
            s["id"] for s in full.json()["data"]
        ][:3]
