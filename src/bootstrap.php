<?php

/**
 * src/bootstrap.php
 *
 * Application bootstrap — database connection and secure session startup.
 * Included by every entry-point script before any output is sent.
 *
 * Security rules applied here:
 *  • PDO with ERRMODE_EXCEPTION and emulated prepares disabled.
 *  • Session cookie flags: HttpOnly, SameSite=Lax.
 *    The Secure flag is enforced via php.ini / server config on HTTPS hosts;
 *    shared-hosting deployments must set session.cookie_secure = 1 in
 *    their php.ini or .user.ini.
 *  • No credentials are hard-coded; they are read from config/db.php which
 *    must never be committed to version control.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Error display — never show PHP errors to end users in production.
// Set DALO_DEV=1 in the environment only on local development machines.
// ---------------------------------------------------------------------------
$isDev = (getenv('DALO_DEV') === '1');
ini_set('display_errors', $isDev ? '1' : '0');
ini_set('display_startup_errors', $isDev ? '1' : '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// Session security
// ---------------------------------------------------------------------------
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
// session.cookie_secure must be set to 1 via php.ini on HTTPS hosts.
// Reminder: rotate the session ID with session_regenerate_id(true) after
// every successful authentication (done in src/auth.php).

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set no-store for authenticated responses to prevent browser caching of sensitive pages.
if (isset($_SESSION['user'])) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

// ---------------------------------------------------------------------------
// Database connection
// ---------------------------------------------------------------------------

/**
 * Returns a singleton PDO connection.
 * The config file must NOT be committed; see config/db.example.php.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $configPath = dirname(__DIR__) . '/config/db.php';
    if (!file_exists($configPath)) {
        // Generic message — do not reveal the file path in production output.
        http_response_code(500);
        exit('Application configuration error. Contact the administrator.');
    }

    $cfg = require $configPath;

    try {
        $pdo = new PDO($cfg['dsn'], $cfg['username'], $cfg['password'], $cfg['options']);
    } catch (PDOException $e) {
        // Log the real error server-side; show nothing sensitive to the user.
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Database connection error. Contact the administrator.');
    }

    return $pdo;
}

// ---------------------------------------------------------------------------
// Output encoding helper
// ---------------------------------------------------------------------------

/**
 * Escapes a value for safe HTML output.
 * Always use this when printing user-supplied or database-sourced data.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// CSRF helpers
// ---------------------------------------------------------------------------

/**
 * Returns the CSRF token for the current session, creating one if absent.
 */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/**
 * Validates the CSRF token submitted with a POST request.
 * Terminates with 403 on mismatch.
 */
function csrf_verify(): void
{
    $submitted = $_POST['_csrf_token'] ?? '';
    $expected  = $_SESSION['_csrf_token'] ?? '';

    if (!is_string($submitted) || !is_string($expected) || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        exit('Invalid or missing CSRF token.');
    }
}

// ---------------------------------------------------------------------------
// Throttle HMAC secret helper
// ---------------------------------------------------------------------------

/**
 * Returns the HMAC secret used to key throttle identifiers, or false if the
 * secret is not configured, is still the placeholder value from db.example.php,
 * or does not meet the minimum strength requirement.
 *
 * The secret MUST be set in config/db.php under the key 'throttle_hmac_secret'.
 * It must be exactly 64 hexadecimal characters generated from 32 random bytes.
 * Generate it with:
 *
 *   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 *
 * If this function returns false, the caller MUST fail closed — no login
 * attempt should be processed and no throttle record should be written.
 *
 * There is NO fallback. A missing, empty, or incorrectly formatted secret
 * causes the login page to return a generic system error until the operator
 * sets a valid secret. This is intentional: silently falling back to a
 * predictable value (e.g. derived from the DSN) would allow an attacker who
 * can read the DSN to reconstruct all throttle keys and bypass rate limiting.
 *
 * The placeholder string 'REPLACE_WITH_64_HEX_CHARS_FROM_bin2hex_random_bytes_32'
 * from config/db.example.php is explicitly rejected so that an operator who
 * copies the file without changing the placeholder cannot silently run with a
 * known value.
 *
 * The raw IP address and raw email address are not persisted in
 * login_attempts; only the HMAC digest appears in that table.
 *
 * @return string|false  The validated secret string, or false on misconfiguration.
 */
function throttle_hmac_secret(): string|false
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }

    $configPath = dirname(__DIR__) . '/config/db.php';
    $cfg = file_exists($configPath) ? require $configPath : [];

    $candidate = $cfg['throttle_hmac_secret'] ?? null;

    // Reject missing, non-string, or empty values.
    if (!is_string($candidate) || $candidate === '') {
        $secret = false;
        return false;
    }

    // Reject the literal placeholder from db.example.php.
    if ($candidate === 'REPLACE_WITH_64_HEX_CHARS_FROM_bin2hex_random_bytes_32') {
        $secret = false;
        return false;
    }

    // Provisioning generates exactly 64 hexadecimal characters from
    // random_bytes(32). Reject arbitrary strings even when they are long.
    if (preg_match('/\A[a-f0-9]{64}\z/i', $candidate) !== 1) {
        $secret = false;
        return false;
    }

    $secret = $candidate;
    return $secret;
}

// ---------------------------------------------------------------------------
// Authorization helpers
// ---------------------------------------------------------------------------

/**
 * Returns the authenticated user's session data, or null if not logged in.
 *
 * @return array{id:int,role:string,full_name:string}|null
 */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Requires that the current visitor is an authenticated organizer.
 * Redirects to the login page otherwise; does not reveal which role is needed.
 *
 * @param string $loginUrl  Path to redirect unauthenticated visitors to.
 */
function require_organizer(string $loginUrl = '/login.php'): void
{
    $user = current_user();
    if ($user === null || $user['role'] !== 'organizer') {
        // Store the intended destination so login can redirect back.
        if ($user === null) {
            $_SESSION['_redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/';
        }
        header('Location: ' . $loginUrl, true, 302);
        exit;
    }
}
