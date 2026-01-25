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
    RATE_LIMIT: str = "100/minute"
    RATE_LIMIT_ENABLED: bool = True

    @property
    def cors_origins_list(self) -> list[str]:
        """Parse CORS_ORIGINS into a list"""
        return [origin.strip() for origin in self.CORS_ORIGINS.split(",")]


settings = Settings()
