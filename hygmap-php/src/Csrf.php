<?php
declare(strict_types=1);

/**
 * CSRF (Cross-Site Request Forgery) protection
 *
 * Generates and validates CSRF tokens stored in session.
 * Tokens should be embedded in forms and validated on submission.
 */
final class Csrf
{
    /** Session key for CSRF token storage */
    private const SESSION_KEY = 'csrf_token';

    /**
     * Initialize CSRF protection
     *
     * Generates a new token if one doesn't exist.
     * Should be called after session_start().
     */
    public static function init(): void
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
    }

    /**
     * Get the current CSRF token for embedding in forms
     *
     * @return string CSRF token
     */
    public static function token(): string
    {
        return $_SESSION[self::SESSION_KEY] ?? '';
    }

    /**
     * Validate a CSRF token from form submission
     *
     * Uses timing-safe comparison to prevent timing attacks.
     *
     * @param string $token Token from form submission
     * @return bool True if token is valid
     */
    public static function validate(string $token): bool
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? '';
        return $stored !== '' && hash_equals($stored, $token);
    }

    /**
     * Generate a new CSRF token (invalidates old token)
     *
     * Useful after successful form submission to prevent replay attacks.
     */
    public static function regenerate(): void
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }
}
