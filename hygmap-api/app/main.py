"""
FastAPI main application entry point for HYGMap star visualization
"""
import time
import uuid
from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.responses import Response
from slowapi import _rate_limit_exceeded_handler
from slowapi.errors import RateLimitExceeded
from app.config import settings
from app.limiter import limiter
from app.api import stars
from app.api import signals
from app.logger import logger


class LoggingMiddleware(BaseHTTPMiddleware):
    """Middleware to log all requests and responses with timing"""

    async def dispatch(self, request: Request, call_next) -> Response:
        # Generate unique request ID for correlation
        request_id = str(uuid.uuid4())
        request.state.request_id = request_id

        # Log incoming request
        start_time = time.time()
        logger.info(
            "Request started",
            extra={
                "request_id": request_id,
                "method": request.method,
                "path": request.url.path,
                "query_params": str(request.query_params),
                "client_ip": request.client.host if request.client else None,
            }
        )

        # Process request
        try:
            response = await call_next(request)
            duration_ms = (time.time() - start_time) * 1000

            # Log successful response
            logger.info(
                "Request completed",
                extra={
                    "request_id": request_id,
                    "method": request.method,
                    "path": request.url.path,
                    "status_code": response.status_code,
                    "duration_ms": round(duration_ms, 2),
                }
            )
            return response
        except Exception as e:
            duration_ms = (time.time() - start_time) * 1000
            # Log error
            logger.error(
                "Request failed",
                extra={
                    "request_id": request_id,
                    "method": request.method,
                    "path": request.url.path,
                    "duration_ms": round(duration_ms, 2),
                    "error": str(e),
                    "error_type": type(e).__name__,
                },
                exc_info=True
            )
            raise


class SecurityHeadersMiddleware(BaseHTTPMiddleware):
    """Middleware to add security headers to all responses"""

    async def dispatch(self, request: Request, call_next) -> Response:
        response = await call_next(request)
        response.headers["X-Content-Type-Options"] = "nosniff"
        response.headers["X-Frame-Options"] = "DENY"
        response.headers["X-XSS-Protection"] = "1; mode=block"
        response.headers["Referrer-Policy"] = "strict-origin-when-cross-origin"
        if not settings.DEBUG:
            # HSTS only in production (requires HTTPS)
            response.headers["Strict-Transport-Security"] = "max-age=31536000; includeSubDomains"
        return response

# Rate limiter: the one shared instance. See app/limiter.py for why it lives there
# rather than being constructed here.

app = FastAPI(
    title="HYGMap API",
    description="Backend API for interactive 3D star mapping with AT-HYG database",
    version="2.0.0",
    # Explicit rather than relying on FastAPI's defaults, so exposing the interactive
    # docs is a recorded decision. See ENABLE_DOCS in app/config.py for the reasoning.
    docs_url="/docs" if settings.ENABLE_DOCS else None,
    redoc_url="/redoc" if settings.ENABLE_DOCS else None,
    openapi_url="/openapi.json" if settings.ENABLE_DOCS else None,
)

# Attach limiter to app state and add exception handler
app.state.limiter = limiter
app.add_exception_handler(RateLimitExceeded, _rate_limit_exceeded_handler)

# CORS middleware for frontend access
app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.cors_origins_list,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Security headers middleware
app.add_middleware(SecurityHeadersMiddleware)

# Logging middleware (add last so it wraps everything)
app.add_middleware(LoggingMiddleware)

# Include API routers
app.include_router(stars.router, prefix="/api/stars", tags=["stars"])
app.include_router(signals.router, prefix="/api/signals", tags=["signals"])


@app.get("/")
async def root():
    """Health check endpoint"""
    return {
        "service": "HYGMap API",
        "version": "2.0.0",
        "status": "running"
    }


@app.get("/health")
async def health():
    """Health check for monitoring"""
    return {"status": "healthy"}
