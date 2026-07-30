"""
Application-level configuration behaviour.

An audit on 2026-07-29 found two production controls that did not do what they appeared
to: the per-IP rate limiter counted every caller as one client (uvicorn was not trusting
the reverse proxy), and the interactive docs were public by default rather than by
decision. The proxy half is a deployment setting and is verified in
docker-compose.prod.yml plus the feature record; these tests cover what is checkable
in-process.
"""
import importlib

from app.config import Settings


class TestDocsExposure:
    """The interactive docs are ON deliberately, and can be turned off without code."""

    def test_docs_enabled_by_default(self):
        assert Settings().ENABLE_DOCS is True

    def test_docs_can_be_disabled_by_environment(self, monkeypatch):
        monkeypatch.setenv("ENABLE_DOCS", "False")
        assert Settings().ENABLE_DOCS is False

    def test_app_wires_doc_urls_from_the_setting(self):
        # Guards against the URLs being hardcoded back to FastAPI's defaults, which is
        # how they came to be public without anyone deciding.
        import app.main as main
        importlib.reload(main)
        assert main.app.docs_url == "/docs"
        assert main.app.openapi_url == "/openapi.json"


class TestRateLimitSettings:
    """Rate limiting is on by default and expressed per minute."""

    def test_rate_limiting_enabled_by_default(self):
        assert Settings().RATE_LIMIT_ENABLED is True

    def test_rate_limit_is_a_per_minute_budget(self):
        assert Settings().RATE_LIMIT.endswith("/minute")

    def test_rate_limit_can_be_lowered_by_environment(self, monkeypatch):
        # The current value was chosen while the limiter was effectively global; being
        # able to lower it without a rebuild matters now that it is genuinely per-IP.
        monkeypatch.setenv("RATE_LIMIT", "60/minute")
        assert Settings().RATE_LIMIT == "60/minute"
