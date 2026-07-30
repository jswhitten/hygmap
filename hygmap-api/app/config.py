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
