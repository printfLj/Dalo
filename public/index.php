<?php
/**
 * public/index.php
 *
 * Application root entry point.
 * Redirects authenticated organizers to event creation; others go to login.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$user = current_user();

if ($user !== null && $user['role'] === 'organizer') {
    header('Location: /events/create.php', true, 302);
} else {
    header('Location: /login.php', true, 302);
}
exit;
