"""Tests for the SETI signals API"""
import pytest
from httpx import AsyncClient


@pytest.mark.anyio
class TestSignalsAPI:
    async def test_get_signals_default_bounds(self, client: AsyncClient) -> None:
        response = await client.get("/api/signals")
        assert response.status_code == 200
        payload = response.json()
        assert payload["result"] == "success"
        assert payload["length"] == len(payload["data"])
        assert payload["length"] >= 2

    async def test_get_signals_filtered_bounds(self, client: AsyncClient) -> None:
        response = await client.get(
            "/api/signals",
            params={
                "xmin": -10,
                "xmax": 0,
                "ymin": 5,
                "ymax": 20,
                "zmin": 0,
                "zmax": 5,
            },
        )
        assert response.status_code == 200
        payload = response.json()
        assert payload["length"] == 1
        assert payload["data"][0]["name"] == "Wow! Signal"

    async def test_get_signals_type_filter(self, client: AsyncClient) -> None:
        response = await client.get("/api/signals", params={"signal_type": "transmit"})
        assert response.status_code == 200
        payload = response.json()
        assert payload["length"] >= 1
        assert all(signal["type"] == "transmit" for signal in payload["data"])

    async def test_get_signals_respects_limit(self, client: AsyncClient) -> None:
        response = await client.get("/api/signals", params={"limit": 1})
        assert response.status_code == 200
        payload = response.json()
        assert payload["length"] == 1

    async def test_get_signals_invalid_order_rejected(self, client: AsyncClient) -> None:
        response = await client.get("/api/signals", params={"order": "id desc"})
        assert response.status_code == 400
        assert "invalid order" in response.json()["detail"].lower()
