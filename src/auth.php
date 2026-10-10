<?php

/**
 * src/auth.php
 *
 * Handles organizer (and facilitator) login and logout.
 * Included by public/login.php and public/logout.php — never accessed directly.
 *
 * Security rules applied:
 *  • Passwords are verified with password_verify() against the bcrypt/Argon2
 *    hash stored in users.password_hash. Plaintext passwords are never stored.
 *  • Session ID is regenerated after successful login to prevent fixation.
 *  • CSRF token is validated on POST before any database access.
 *  • Generic error messages are shown on failure — do not reveal whether the
 *    email exists or whether the password was wrong.
 *  • No self-registration endpoint is provided. Organizer accounts must be
 *    provisioned manually; see docs/provisioning.md.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$errors  = [];
$oldEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. CSRF check first — before touching any user input.
    csrf_verify();

    $email    = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!is_string($email)) {
        $email = '';
    }
    if (!is_string($password)) {
        $password = '';
    }

    $email = trim($email);
    $oldEmail = $email; // repopulate form on error (safe to echo after e())

    $ipId = 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $emailId = 'email:' . $email;
    $ipMaxAttempts = 50;
    $emailMaxAttempts = 5;
    $lockoutMinutes = 15;

    try {
        // Cleanup expired records probabilistically (e.g., 5% of requests)
        if (random_int(1, 20) === 1) {
            db()->exec('DELETE FROM login_attempts WHERE last_attempt < DATE_SUB(NOW(), INTERVAL ' . $lockoutMinutes . ' MINUTE)');
        }

        $stmt = db()->prepare('SELECT id, attempts, last_attempt FROM login_attempts WHERE id IN (?, ?)');
        $stmt->execute([$ipId, $emailId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $throttle) {
            $lastAttemptTime = strtotime($throttle['last_attempt']);
            if (time() - $lastAttemptTime < $lockoutMinutes * 60) {
                if (str_starts_with($throttle['id'], 'ip:') && $throttle['attempts'] >= $ipMaxAttempts) {
                    http_response_code(429);
                    exit('Too many login attempts from this network. Please try again later.');
                }
                if (str_starts_with($throttle['id'], 'email:') && $throttle['attempts'] >= $emailMaxAttempts) {
                    http_response_code(429);
                    exit('Too many login attempts for this account. Please try again later.');
                }
            }
        }
    } catch (PDOException $e) {
        error_log('Throttling check error: ' . $e->getMessage());
        $errors[] = 'A system error occurred. Please try again later.';
    }

    // 2. Server-side field validation.
    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (mb_strlen($email) > 191) {
        $errors[] = 'Email address is too long.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    // 3. Database lookup — only when basic validation passes.
    if (empty($errors)) {
        try {
            $stmt = db()->prepare(
                'SELECT id, role, full_name, password_hash
                   FROM users
                  WHERE email = ?
                  LIMIT 1'
            );
            $stmt->execute([$email]);
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('Login query error: ' . $e->getMessage());
            $errors[] = 'A system error occurred. Please try again later.';
            $row      = false;
        }

        if ($row === false || !isset($row['id'])) {
            // User not found — use a valid dummy verify to prevent timing oracle.
            password_verify($password, '$2y$12$4mLCkcc6xDzuxbkfkw9BveoUfjQyRCyy3l8YQaobcfJwNHVqS7S8e');
            $errors[] = 'Incorrect email or password.';
        } else {
            if (!password_verify($password, $row['password_hash'])) {
                $errors[] = 'Incorrect email or password.';
            } elseif ($row['role'] !== 'organizer') {
                // Facilitator login is deferred until their dashboard exists.
                // Students do not log in through this interface.
                $errors[] = 'Incorrect email or password.';
            }
        }
    }

    // 4. On success, start an authenticated session.
    if (empty($errors) && isset($row) && $row !== false && $row['role'] === 'organizer') {
        try {
            // Clear attempts for both this IP and email
            $stmt = db()->prepare('DELETE FROM login_attempts WHERE id IN (?, ?)');
            $stmt->execute([$ipId, $emailId]);
        } catch (PDOException $e) {
            error_log('Throttling delete error: ' . $e->getMessage());
        }

        // Prevent session fixation.
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id'        => (int) $row['id'],
            'role'      => $row['role'],
            'full_name' => $row['full_name'],
        ];

        // Rehash if needed (e.g. algorithm upgrade).
        $algo = PASSWORD_BCRYPT;
        $options = ['cost' => 12];
        if (password_needs_rehash($row['password_hash'], $algo, $options)) {
            try {
                $hash = password_hash($password, $algo, $options);
                $upd  = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $upd->execute([$hash, (int) $row['id']]);
            } catch (PDOException $e) {
                error_log('Password rehash failed for user ' . $row['id'] . ': ' . $e->getMessage());
            }
        }

        // Redirect to the saved destination or the organizer dashboard.
        $redirect = $_SESSION['_redirect_after_login'] ?? '/events/create.php';
        unset($_SESSION['_redirect_after_login']);

        // Validate the redirect to prevent open-redirect attacks.
        if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
            $redirect = '/events/create.php';
        }

        header('Location: ' . $redirect, true, 303);
        exit;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Record failed attempt (only if the request was actually processed, bypassing if system error occurred early)
        if (empty($errors) || $errors[0] !== 'A system error occurred. Please try again later.') {
            try {
                $stmt = db()->prepare('
                    INSERT INTO login_attempts (id, attempts, last_attempt)
                    VALUES (?, 1, NOW()), (?, 1, NOW())
                    ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()
                ');
                $stmt->execute([$ipId, $emailId]);
            } catch (PDOException $e) {
                error_log('Throttling insert error: ' . $e->getMessage());
            }
        }
    }
}
