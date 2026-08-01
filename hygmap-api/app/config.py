"""
Application configuration using Pydantic settings
"""
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Application settings loaded from environment variables"""

    model_config = SettingsConfigDict(
        env_file=".env",
        case_sensitive=True,
    )

    # Database
    DATABASE_URL: str = "postgresql+asyncpg://hygmap_user:password@localhost:5432/hygmap"

    # API
    API_HOST: str = "0.0.0.0"
    API_PORT: int = 8000
    DEBUG: bool = False

    # CORS - comma-separated string, parsed into list by cors_origins_list property
    CORS_ORIGINS: str = "http://localhost:5173,http://localhost:3000"

    # Rate limiting - requests per minute per IP
    #
    # NOTE: this became a genuine PER-IP limit on 2026-07-29. Before that, uvicorn's
    # proxy-header handling did not trust the reverse proxy, so every caller presented the
    # same address and slowapi counted them as one client -- the limit was effectively
    # global. The value below was chosen under those conditions and is worth re-examining:
    # aggregate capacity is now this number times the number of distinct callers.
    RATE_LIMIT: str = "1000/minute"
    RATE_LIMIT_ENABLED: bool = True

    # Ceiling on a single search query, applied per-statement in Postgres.
    #
    # This is a backstop, not a tuning knob. Search is meant to answer in single-digit
    # milliseconds; when it does not, the cause is a bad plan, and a bad plan does not get
    # better by being given more time. FICTIONAL-SEARCH-PERFORMANCE (2026-07-31) is the
    # case this exists for: one disjunct in the WHERE turned a 2.4 ms query into a 60 s
    # one, and because PHP's ApiClient retries a timeout twice, a visitor waited up to 90
    # seconds and the database repeated a near-full-table scan three times over.
    #
    # 5 s is ~700x the healthy figure for the slowest measured search, so anything hitting
    # it is broken rather than merely busy. Failing fast turns "the site is hanging" into
    # one logged error with a query attached.
    SEARCH_STATEMENT_TIMEOUT_MS: int = 5000

    # The Docker network the services share, and its gateway address.
    #
    # These exist because the API cannot tell "a caller on the public internet" from
    # "another one of our own containers" without knowing what our own network looks
    # like. Both are pinned in docker-compose.yml (`networks.default.ipam`) precisely so
    # these defaults stay true; if you change the subnet there, change it here.
    INTERNAL_NETWORK: str = "172.20.0.0/16"
    INTERNAL_GATEWAY: str = "172.20.0.1"

    # Skip rate limiting for callers inside INTERNAL_NETWORK.
    #
    # The classic PHP UI calls this API server-side, so every one of its users arrives
    # from the single address of the hygmap-php container. Measured 2026-07-30: 5,010 of
    # 6,871 requests came from 172.20.0.3. Counting those against one per-IP bucket
    # throttles real visitors while protecting nothing -- their traffic is already
    # limited at the nginx hop, one layer out. First-party server-side callers are not
    # the threat this limiter exists for.
    #
    # INTERNAL_GATEWAY is deliberately NOT exempt even though it is inside the network.
    # It is the address every request collapses to when proxy-header trust is
    # misconfigured (see FORWARDED_ALLOW_IPS in docker-compose.prod.yml). Exempting it
    # would turn that misconfiguration from "one shared bucket" -- bad but bounded --
    # into "no rate limiting at all", silently. The failure mode must degrade toward
    # more limiting, never less.
    RATE_LIMIT_EXEMPT_INTERNAL: bool = True

    # Interactive API documentation (/docs, /redoc, /openapi.json).
    #
    # Deliberately ON, including in production. This is a public, read-only,
    # unauthenticated API whose routes are already published in docs/api.md, so the
    # schema reveals nothing that is not already documented, and the interactive page is
    # genuinely useful to anyone wanting to build against it. Recorded as a decision
    # because an audit found it was reachable in production by default rather than by
    # choice. Set ENABLE_DOCS=False to turn it off without a code change.
    ENABLE_DOCS: bool = True

    @property
    def cors_origins_list(self) -> list[str]:
        """Parse CORS_ORIGINS into a list"""
        return [origin.strip() for origin in self.CORS_ORIGINS.split(",")]


settings = Settings()
