<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Http.php';

/**
 * Small, self-contained security helpers:
 *   - sensible response headers
 *   - the client IP (used only for rate limiting)
 *   - a simple per-IP login throttle to blunt brute-force / basic DoS attempts
 *
 * The throttle counts recent FAILED logins from one IP. Normal use never hits
 * it; it only slows down someone hammering the login endpoint.
 */
final class Security
{

    private const MAX_FAILURES = 10;
    private const WINDOW_MIN   = 15;

    public static function headers(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
    }

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    // Stop the request with 429 if this IP has failed to log in too often.
    public static function throttleLogin(string $ip): void
    {
        $count = (int) Database::run(
            "SELECT COUNT(*) AS c FROM login_attempts
             WHERE ip = ? AND created_at > (NOW() - INTERVAL ? MINUTE)",
            [$ip, self::WINDOW_MIN]
        )->fetch()['c'];

        if ($count >= self::MAX_FAILURES) 
        {
            Http::fail('Too many login attempts. Please wait a few minutes and try again.', 429);
        }
    }

    // Record a failed login for this IP (and opportunistically clean old rows). 
    public static function recordLoginFailure(string $ip): void
    {
        Database::run("INSERT INTO login_attempts (ip) VALUES (?)", [$ip]);
        Database::run("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    }

    // Clear an IP's failures after a successful login. 
    public static function clearLoginFailures(string $ip): void
    {
        Database::run("DELETE FROM login_attempts WHERE ip = ?", [$ip]);
    }
}
