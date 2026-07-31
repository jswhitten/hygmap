"""
Application-level configuration behaviour.

An audit on 2026-07-29 found two production controls that did not do what they appeared
to: the per-IP rate limiter counted every caller as one client (uvicorn was not trusting
the reverse proxy), and the interactive docs were public by default rather than by
decision. The proxy half is a deployment setting and is verified in
docker-compose.prod.yml plus the feature record; these tests cover what is checkable
in-process.

The proxy half stopped being merely a deployment setting on 2026-07-30, when
FORWARDED_ALLOW_IPS=* turned out to make the limiter spoofable rather than shared. The
parsing rule that made it spoofable is uvicorn's, so it is pinned here directly.
"""
import importlib
import os
from types import SimpleNamespace

from app.config import Settings, settings


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


class TestForwardedForParsing:
    """
    How uvicorn picks the client address out of X-Forwarded-For.

    This is the mechanism the 2026-07-30 spoof exploited, so it is asserted directly
    rather than trusted. nginx's $proxy_add_x_forwarded_for APPENDS the real peer to
    whatever the caller sent, so the header looks like "<caller-supplied>, <real peer>"
    and only the LAST entry is trustworthy.
    """

    @staticmethod
    def _middleware(trusted):
        from uvicorn.middleware.proxy_headers import ProxyHeadersMiddleware

        return ProxyHeadersMiddleware(app=None, trusted_hosts=trusted)

    def test_specific_trust_ignores_the_caller_supplied_prefix(self):
        # The fix. Trusting one named proxy makes uvicorn scan right-to-left for the
        # first entry that is not a trusted host -- the address nginx appended.
        mw = self._middleware(settings.INTERNAL_GATEWAY)
        forged_then_real = ["203.0.113.9", "198.51.100.7"]
        assert mw.get_trusted_client_host(forged_then_real) == "198.51.100.7"

    def test_a_caller_cannot_move_its_own_bucket_by_forging_a_prefix(self):
        # Two requests from one real peer with different forged prefixes must key to
        # the same address, or the limiter can be evaded by rotating a header.
        mw = self._middleware(settings.INTERNAL_GATEWAY)
        first = mw.get_trusted_client_host(["203.0.113.1", "198.51.100.7"])
        second = mw.get_trusted_client_host(["203.0.113.2", "198.51.100.7"])
        assert first == second == "198.51.100.7"

    def test_wildcard_trust_takes_the_forged_entry(self):
        # Not a wish -- a record of why FORWARDED_ALLOW_IPS must never be "*" again.
        # With "*", uvicorn returns entry [0], which is entirely caller-controlled, so
        # every forged prefix becomes its own rate-limit bucket.
        mw = self._middleware("*")
        assert mw.get_trusted_client_host(["203.0.113.9", "198.51.100.7"]) == "203.0.113.9"


class TestInternalClientExemption:
    """
    First-party server-side callers skip the limiter; everyone else does not.

    The PHP app calls this API on behalf of all its users from a single container
    address, so counting it against one per-IP bucket throttles real visitors.
    """

    @staticmethod
    def _request(host):
        # is_internal_client reads only request.client.host.
        return SimpleNamespace(client=SimpleNamespace(host=host))

    def test_php_container_is_exempt(self):
        from app.limiter import is_internal_client

        assert is_internal_client(self._request("172.20.0.3")) is True

    def test_public_client_is_not_exempt(self):
        from app.limiter import is_internal_client

        assert is_internal_client(self._request("198.51.100.7")) is False

    def test_gateway_is_never_exempt(self):
        """
        The most important assertion in this file.

        The gateway is inside the internal network, so the obvious implementation
        exempts it. It is also the address every caller collapses to if proxy-header
        trust breaks. Exempting it would turn a misconfiguration that costs us one
        shared bucket into one that removes rate limiting altogether, silently.
        """
        from app.limiter import is_internal_client

        assert is_internal_client(self._request(settings.INTERNAL_GATEWAY)) is False

    def test_unparseable_address_is_not_exempt(self):
        # Starlette's TestClient reports the literal "testclient". Fail closed.
        from app.limiter import is_internal_client

        assert is_internal_client(self._request("testclient")) is False

    def test_missing_client_is_not_exempt(self):
        from app.limiter import is_internal_client

        assert is_internal_client(SimpleNamespace(client=None)) is False

    def test_exemption_can_be_switched_off(self, monkeypatch):
        from app.limiter import is_internal_client

        monkeypatch.setattr(settings, "RATE_LIMIT_EXEMPT_INTERNAL", False)
        assert is_internal_client(self._request("172.20.0.3")) is False

    def test_limiter_consults_the_exemption(self):
        # Guards the override itself: slowapi's private hook could move on upgrade, and
        # the failure mode would be a limiter that quietly stops exempting -- or, worse,
        # a Limiter subclass that no longer overrides anything.
        from slowapi import Limiter

        from app.limiter import ProxyAwareLimiter, limiter

        assert isinstance(limiter, ProxyAwareLimiter)
        assert (
            ProxyAwareLimiter._check_request_limit is not Limiter._check_request_limit
        )


class TestSingleLimiterInstance:
    """
    Every route must share one limiter object.

    Until 2026-07-30 there were three: main.py built one and attached it to app.state,
    while api/stars.py and api/signals.py each built their own and the decorators used
    those. The limiter the app was configured through was not the one enforcing, so
    main.py's default_limits governed no route and the internal-caller exemption below
    was inert -- correct, imported, bound, and never consulted.

    This is not about per-endpoint buckets. slowapi keys limits by (client, endpoint)
    by default, so each route has always had its own budget regardless of how many
    Limiter objects exist.
    """

    def test_routers_and_app_share_one_limiter(self):
        import app.api.signals as signals
        import app.api.stars as stars
        import app.limiter as shared
        import app.main as main

        assert stars.limiter is shared.limiter
        assert signals.limiter is shared.limiter
        assert main.app.state.limiter is shared.limiter

    def test_no_module_constructs_its_own_limiter(self):
        # A new router copying the old pattern would silently reintroduce a private
        # bucket store and skip the internal-caller exemption.
        import inspect

        import app.api.signals as signals
        import app.api.stars as stars

        for module in (stars, signals):
            source = inspect.getsource(module)
            assert "Limiter(" not in source, (
                f"{module.__name__} constructs a Limiter; import it from app.limiter"
            )


class TestProdComposeTrustSetting:
    """
    Guards the deployed value itself, not just the parsing rule.

    This used to skip itself when docker-compose.prod.yml was not visible, which meant it
    skipped under `make test-api` -- the only way the suite is ever run, including in CI.
    So the guard against the exact misconfiguration PROXY-TRUST fixed had never executed.
    The Makefile now mounts the file, and a missing file is a failure rather than a skip:
    silently passing when the thing under test is absent is what went wrong the first time.
    """

    @staticmethod
    def _prod_compose():
        path = os.path.join(
            os.path.dirname(__file__), "..", "..", "docker-compose.prod.yml"
        )
        assert os.path.exists(path), (
            f"docker-compose.prod.yml not found at {path}. This test must not be skipped: "
            "mount it into the container (see the test-api target in the Makefile) or run "
            "pytest from a full checkout."
        )
        with open(path) as fh:
            return fh.read()

    def test_prod_does_not_trust_every_proxy(self):
        assert "FORWARDED_ALLOW_IPS=*" not in self._prod_compose()

    def test_prod_trusts_exactly_the_pinned_gateway(self):
        expected = f"FORWARDED_ALLOW_IPS={settings.INTERNAL_GATEWAY}"
        assert expected in self._prod_compose()
