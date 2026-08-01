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
        await conn.execute(text("DROP TABLE IF EXISTS athyg_v3_ids"))
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
                -- Nullable, like the real table: a star with no usable parallax has no
                -- 3D position. See the sentinel note in db/sql/03_import_data.sql.
                x REAL,
                y REAL,
                z REAL,
                hyg INTEGER,
                hip TEXT,
                hd TEXT,
                hr TEXT,
                gj TEXT,
                cns5 TEXT,
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

        # AT-HYG v3.3 -> current id mapping. A lookup table rather than a column on athyg
        # because the mapping is many-to-one: AT-HYG 4 merged some v3.3 rows, so 5 real
        # stars are each named by two v3 ids.
        await conn.execute(text("""
            CREATE TABLE athyg_v3_ids (
                v3_id INTEGER PRIMARY KEY,
                athyg_id INTEGER NOT NULL,
                match_method TEXT NOT NULL,
                FOREIGN KEY (athyg_id) REFERENCES athyg(id)
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
                (10, 'Wolf 359', 'CN', 'Leo', 'M6.5Ve', 16.55, -2.20, -0.61, 1.13, '54035', NULL),
                (11, NULL, NULL, NULL, NULL, 14.50, 3.00, 2.00, 1.50, NULL, NULL),
                -- Positionless: no usable parallax, so no distance and no coordinates.
                -- A real star (compare Polis) that cannot be drawn on a 3D map.
                (12, 'Positionless Star', NULL, 'Sgr', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
                -- Beyond the coordinate domain the API can express (MAX_COORDINATE_VALUE
                -- is 10000 pc). A broken Gaia parallax yields a huge distance and, with it,
                -- an absurdly bright absolute magnitude that sorts to the top.
                (13, NULL, NULL, 'Sgr', 'B2III', -13.50, 325046.0, -90701.0, -2810.0, NULL, NULL),
                -- Five stars sharing one absmag, isolated in a box no other fixture star
                -- occupies (25..35 on every axis). They exist so tie-breaking under LIMIT
                -- can be exercised end to end.
                --
                -- This is the real catalogue's dominant case, not a contrived one:
                -- 2,784,293 of 2,839,957 stars share an absmag with at least one other
                -- star. Before ORDER_CLAUSES gained its `a.id` tiebreaker, a LIMIT cutting
                -- through a tie group returned whichever rows the scan reached first, and
                -- three identical requests could return three different star sets.
                --
                -- Deliberately listed in descending id order: if the tiebreaker were
                -- removed, a scan returning rows in insertion order would produce
                -- 18, 17, 16 rather than the 14, 15, 16 the ordering promises.
                (18, NULL, NULL, 'Lyn', 'K0V', 9.99, 31.0, 31.0, 31.0, NULL, NULL),
                (17, NULL, NULL, 'Lyn', 'K0V', 9.99, 30.0, 30.0, 31.0, NULL, NULL),
                (16, NULL, NULL, 'Lyn', 'K0V', 9.99, 30.0, 31.0, 30.0, NULL, NULL),
                (15, NULL, NULL, 'Lyn', 'K0V', 9.99, 31.0, 30.0, 30.0, NULL, NULL),
                (14, NULL, NULL, 'Lyn', 'K0V', 9.99, 30.0, 30.0, 30.0, NULL, NULL)
        """))

        # Real dist/mag for a few stars.
        #
        # Set separately rather than in the INSERT above only to keep that column list
        # readable. It matters that these are non-NULL: /api/stars/search omitted `dist`
        # and `mag` from all eight of its SELECT branches for three audit cycles, and
        # StarBase's `Optional[float] = None` defaults filled them with nulls that were
        # indistinguishable from "this star has no parallax". A fixture where every row is
        # NULL cannot tell those two apart, so a test written against it passes either way.
        await conn.execute(text("""
            UPDATE athyg SET dist = CASE id
                    WHEN 3 THEN 2.6371 WHEN 4 THEN 7.6787 WHEN 9 THEN 1.8282 END,
                            mag  = CASE id
                    WHEN 3 THEN -1.44 WHEN 4 THEN 0.03 WHEN 9 THEN 9.511 END
            WHERE id IN (3, 4, 9)
        """))

        # Legacy v3.3 ids.
        #
        # Star 3 (Sirius) models the case the whole feature exists for: its v3 id (7301)
        # is ALSO a valid, different v4 id in the real catalogue, so a bare id is
        # ambiguous. 99.99% of v3 ids are in this state.
        #
        # Stars 4 and 5 share one current star deliberately -- two v3 rows merged into one
        # v4 star. That is why this is a table and not a column: a column could hold only
        # one of them, and UPDATE...FROM would pick which one nondeterministically.
        await conn.execute(text("""
            INSERT INTO athyg_v3_ids (v3_id, athyg_id, match_method)
            VALUES
                (7301, 3, 'gaia'),
                (2, 3, 'hd'),
                (9001, 4, 'gaia'),
                (9002, 4, 'tyc'),
                (5, 5, 'hip')
        """))

        # Set GJ and CNS5 IDs for test stars
        await conn.execute(text("""
            UPDATE athyg SET gj = '551' WHERE id = 2
        """))
        await conn.execute(text("""
            UPDATE athyg SET gj = '10999', cns5 = '5500' WHERE id = 11
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
                (4, 10, 2, 'Epsilon III System'),
                -- Fictional names on the two unmappable stars (12 has no position, 13 is
                -- beyond MAX_COORDINATE_VALUE). Search must exclude these for the same
                -- reason it excludes their real names: selecting one cannot be rendered.
                -- Without these rows nothing proves the fictional predicate sits inside
                -- the unmappable guard rather than beside it.
                (5, 12, 1, 'Unmappable Colony'),
                (6, 13, 1, 'Faraway Outpost')
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
