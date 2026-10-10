<?php
/**
 * public/logout.php
 *
 * Destroys the authenticated session and redirects to the login page.
 * Requires a POST request with a valid CSRF token to prevent prefetch/crawlers
 * or malicious sites from logging the user out unintentionally.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

// Require POST and validate CSRF token to prevent accidental/malicious logouts.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

csrf_verify();

// Unset all session variables, destroy the session, and clear the cookie.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();

header('Location: /login.php', true, 303);
exit;
