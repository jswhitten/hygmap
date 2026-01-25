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
        valid_searches = ["HIP 1", "HD 1", "HR 1", "GJ 1", "GL 1"]
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
