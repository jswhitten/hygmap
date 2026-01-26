"""
Pytest fixtures for backend tests

Uses an in-memory SQLite database for testing instead of PostgreSQL.
"""

import pytest
from typing import AsyncGenerator
from httpx import AsyncClient, ASGITransport
from sqlalchemy import text
from sqlalchemy.ext.asyncio import create_async_engine, AsyncSession, async_sessionmaker

from app.main import app
from app.database import get_db


# Create in-memory SQLite engine for testing
TEST_DATABASE_URL = "sqlite+aiosqlite:///:memory:"

test_engine = create_async_engine(
    TEST_DATABASE_URL,
    echo=False,
)

TestSessionLocal = async_sessionmaker(
    test_engine,
    class_=AsyncSession,
    expire_on_commit=False,
)


async def override_get_db() -> AsyncGenerator[AsyncSession, None]:
    """Override database dependency with test database"""
    async with TestSessionLocal() as session:
        try:
            yield session
        finally:
            await session.close()


@pytest.fixture(scope="session")
def anyio_backend():
    return "asyncio"


@pytest.fixture(scope="function")
async def db_session() -> AsyncGenerator[AsyncSession, None]:
    """Create a fresh database session for each test"""
    # Recreate tables from scratch for each test to avoid UNIQUE conflicts
    async with test_engine.begin() as conn:
        await conn.execute(text("DROP TABLE IF EXISTS signals"))
        await conn.execute(text("DROP TABLE IF EXISTS fic"))
        await conn.execute(text("DROP TABLE IF EXISTS fic_worlds"))
        await conn.execute(text("DROP TABLE IF EXISTS athyg"))

        await conn.execute(text("""
            CREATE TABLE athyg (
                id INTEGER PRIMARY KEY,
                proper TEXT,
                bayer TEXT,
                flam TEXT,
                con TEXT,
                spect TEXT,
                absmag REAL,
                x REAL NOT NULL,
                y REAL NOT NULL,
                z REAL NOT NULL,
                hyg INTEGER,
                hip TEXT,
                hd TEXT,
                hr TEXT,
                gj TEXT,
                tyc TEXT,
                gaia TEXT,
                ra REAL,
                dec REAL,
                dist REAL,
                mag REAL
            )
        """))

        await conn.execute(text("""
            CREATE TABLE fic_worlds (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL
            )
        """))

        await conn.execute(text("""
            CREATE TABLE fic (
                id INTEGER PRIMARY KEY,
                star_id INTEGER NOT NULL,
                world_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                FOREIGN KEY (star_id) REFERENCES athyg(id),
                FOREIGN KEY (world_id) REFERENCES fic_worlds(id)
            )
        """))

        await conn.execute(text("""
            CREATE TABLE signals (
                id INTEGER PRIMARY KEY,
                name TEXT,
                type TEXT,
                time TEXT,
                ra REAL,
                dec REAL,
                frequency REAL,
                notes TEXT,
                x REAL NOT NULL,
                y REAL NOT NULL,
                z REAL NOT NULL,
                last_updated TEXT
            )
        """))

        # Insert test data
        await conn.execute(text("""
            INSERT INTO athyg (id, proper, bayer, con, spect, absmag, x, y, z, hip, hd)
            VALUES
                (1, 'Sol', NULL, NULL, 'G2V', 4.83, 0, 0, 0, NULL, NULL),
                (2, 'Proxima Centauri', 'Alp', 'Cen', 'M5.5Ve', 15.53, -1.55, -1.18, -3.77, '70890', '217987'),
                (3, 'Sirius', 'Alp', 'CMa', 'A1V', 1.42, -1.87, 0.08, -2.31, '32349', '48915'),
                (4, 'Vega', 'Alp', 'Lyr', 'A0V', 0.58, 2.13, 14.25, 11.92, '91262', '172167'),
                (5, 'Betelgeuse', 'Alp', 'Ori', 'M1Iab', -5.85, -109.32, -222.29, -126.64, '27989', '39801'),
                (6, 'Rigel', 'Bet', 'Ori', 'B8Ia', -7.84, -69.32, -170.08, -204.68, '24436', '34085'),
                (7, 'Altair', 'Alp', 'Aql', 'A7V', 2.21, 2.37, 4.44, -1.52, '97649', '187642'),
                (8, 'Deneb', 'Alp', 'Cyg', 'A2Ia', -8.73, 556.38, 1312.99, 432.82, '102098', '197345'),
                (9, 'Barnard Star', NULL, 'Oph', 'M4Ve', 13.22, -0.01, -1.82, 0.03, '87937', NULL),
                (10, 'Wolf 359', 'CN', 'Leo', 'M6.5Ve', 16.55, -2.20, -0.61, 1.13, '54035', NULL)
        """))

        await conn.execute(text("""
            INSERT INTO fic_worlds (id, name)
            VALUES
                (1, 'Star Trek'),
                (2, 'Babylon 5')
        """))

        await conn.execute(text("""
            INSERT INTO fic (id, star_id, world_id, name)
            VALUES
                (1, 10, 1, 'Wolf 359'),
                (2, 3, 1, 'Alpha Canis Majoris'),
                (3, 2, 1, 'Alpha Centauri'),
                (4, 10, 2, 'Epsilon III System')
        """))

        await conn.execute(text("""
            INSERT INTO signals (id, name, type, time, ra, dec, frequency, notes, x, y, z, last_updated)
            VALUES
            (1, 'Wow! Signal', 'receive', '1977-08-15T22:16:00Z', 19.8, -27.0, 1420.4058, 'Detected by Big Ear telescope', -5.0, 12.0, 2.5, '2026-01-01T00:00:00Z'),
            (2, 'Arecibo Reply', 'transmit', '1974-11-16T00:00:00Z', 17.76, -28.74, 2380.0, 'Arecibo message broadcast', 8.0, -10.0, 1.0, '2026-01-01T00:00:00Z'),
            (3, 'Voyager Beacon', 'transmit', '1977-09-05T12:56:00Z', 17.0, 12.0, 8400.0, 'Simulated outbound probe message', 60.0, 40.0, 5.0, '2026-01-01T00:00:00Z')
        """))

    async with TestSessionLocal() as session:
        yield session


@pytest.fixture(scope="function")
async def client(db_session: AsyncSession) -> AsyncGenerator[AsyncClient, None]:
    """Create a test client with the test database"""
    app.dependency_overrides[get_db] = override_get_db

    async with AsyncClient(
        transport=ASGITransport(app=app),
        base_url="http://test",
        follow_redirects=True
    ) as client:
        yield client

    app.dependency_overrides.clear()
