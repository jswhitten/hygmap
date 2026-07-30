<?php
declare(strict_types=1);

/**
 * Typed API failures.
 *
 * ApiClient used to throw a bare RuntimeException for every failure, so callers could
 * not tell "there is no such star" from "the star database is down". Both surfaced as
 * the same generic error page with an HTTP 200, which meant a user who mistyped an id was
 * told the system was broken, and a monitor watching for failures saw success.
 *
 * Both extend RuntimeException so existing `catch (RuntimeException $e)` blocks keep
 * working; callers that care can catch the specific type.
 */

/**
 * The API answered, and the answer was "that does not exist" (HTTP 404).
 *
 * This is a normal outcome for a bad id in a URL, not a fault. The right response is a
 * 404 and a message telling the user the star was not found.
 */
class ApiNotFoundException extends RuntimeException
{
}

/**
 * The API could not be reached, or failed persistently (connection error, or 5xx after
 * exhausting retries).
 *
 * This is a genuine outage. The right response is a 5xx so that monitoring, crawlers and
 * caches all treat the page as failed rather than as content.
 */
class ApiUnavailableException extends RuntimeException
{
}
