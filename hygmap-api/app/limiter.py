"""
The application's single rate limiter.

There used to be three. `main.py` built one and attached it to `app.state`, while
`api/stars.py` and `api/signals.py` each constructed their own, and the route
decorators used those. So the limiter the application was configured through was not
the limiter doing the work:

  * The `default_limits` passed in `main.py` applied to no route at all.
  * Anything configured centrally -- like the internal-caller exemption below -- was
    silently inert. Verified on 2026-07-30: the exemption was correct, imported, and
    bound, and first-party callers were still being rate limited, because the object
    holding it was never consulted.

Note what this was NOT. slowapi keys each limit by (client, endpoint) with the default
`key_style="url"`, so every route has had its own bucket all along -- exhausting
`/api/stars/search` still leaves `/api/stars/worlds` at a full budget, one shared
limiter or three. That is slowapi's design, not a defect, but it does mean a caller's
total capacity is RATE_LIMIT multiplied by the number of endpoints it touches.

Every module that limits a route imports `limiter` from here. Do not construct a
`Limiter` anywhere else.
"""
import ipaddress
from typing import Any, Callable, Optional

from fastapi import Request
from slowapi import Limiter
from slowapi.util import get_remote_address

from app.config import settings


def is_internal_client(request: Request) -> bool:
    """
    True when this request came from another container on our own Docker network.

    By the time this runs, uvicorn's ProxyHeadersMiddleware has already replaced
    request.client with the real caller for anything arriving through nginx, so a
    genuine internet client presents its public address here and never matches. What
    still matches is first-party server-side traffic -- principally the PHP app, which
    calls this API on behalf of every one of its users from one container address.

    The gateway is excluded on purpose. See RATE_LIMIT_EXEMPT_INTERNAL in app/config.py:
    it is the address everything collapses to when proxy trust is misconfigured, and
    exempting it would convert that mistake into a total loss of rate limiting.
    """
    if not settings.RATE_LIMIT_EXEMPT_INTERNAL:
        return False
    if request.client is None:
        return False
    try:
        client = ipaddress.ip_address(request.client.host)
        network = ipaddress.ip_network(settings.INTERNAL_NETWORK)
        gateway = ipaddress.ip_address(settings.INTERNAL_GATEWAY)
    except ValueError:
        # Unparseable address (Starlette's TestClient uses the literal "testclient")
        # or misconfigured network. Fail closed: rate limit it.
        return False
    return client in network and client != gateway


class ProxyAwareLimiter(Limiter):
    """
    A Limiter that skips first-party callers on our own Docker network.

    slowapi has two exemption hooks and neither fits: `_request_filters` are called with
    no arguments so they cannot see who is calling, and `exempt_when` is per decorator,
    which would mean touching every route and silently failing open on any route added
    later. Overriding the one central check applies to every route automatically,
    including future ones.

    This reaches into a private method, so it is pinned by behaviour rather than by
    structure: tests/test_main_config.py asserts that an internal caller is exempt and
    an external one is not, through the real ASGI stack. If a slowapi upgrade moves
    this, those tests fail loudly rather than the limiter quietly ceasing to apply.
    """

    def _check_request_limit(
        self,
        request: Request,
        endpoint_func: Optional[Callable[..., Any]],
        in_middleware: bool = True,
    ) -> None:
        if is_internal_client(request):
            # slowapi's route wrapper unconditionally reads request.state.view_rate_limit
            # afterwards to build the X-RateLimit-* headers, so returning without setting
            # it raises AttributeError and the caller gets a 500 instead of its response.
            # None is the value _inject_headers already treats as "no limit applied".
            # Found the hard way: the first version of this exemption turned every
            # first-party request into a 500.
            request.state.view_rate_limit = None
            return
        return super()._check_request_limit(request, endpoint_func, in_middleware)


limiter = ProxyAwareLimiter(
    key_func=get_remote_address,
    enabled=settings.RATE_LIMIT_ENABLED,
    default_limits=[settings.RATE_LIMIT],
)
