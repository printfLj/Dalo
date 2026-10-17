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
 *
 * Login throttling design:
 *
 *  HMAC keys
 *  ---------
 *  Throttle keys are HMAC-SHA-256 digests of a domain label plus the raw IP address (for the IP
 *  counter) and of the case-folded, trimmed email address (for a short-lived
 *  per-account counter). The HMAC secret is read from config/db.php under the key
 *  'throttle_hmac_secret'. The key MUST be present and exactly 64 hex chars
 *  generated from 32 random bytes; a missing or placeholder value causes the login page
 *  to return a system error rather than silently degrading. Raw IPs and email
 *  addresses are never written to login_attempts.
 *
 *  Limits and lockout policy
 *  -------------------------
 *  Only the per-IP counter triggers a hard lockout (429): 50 login POSTs
 *  within a 15-minute fixed window. There is no independent per-email hard
 *  lockout, because any fixed email-keyed lockout allows an unauthenticated
 *  attacker to deny a legitimate organizer access simply by sending N+1 failed
 *  requests for that account from any number of IPs. The IP limit still stops
 *  automated credential-stuffing from a single source; distributed attacks that
 *  stay below the per-IP limit are not addressable by on-server throttling alone
 *  and require external controls (WAF, hosting-provider rate limiting).
 *
 *  Per-account (email-keyed) attempts are still recorded in login_attempts for
 *  short-lived security analysis, but they do NOT cause a hard lockout. The
 *  application checks only the IP row for the 429 decision.
 *
 *  Window policy
 *  -------------
 *  Fixed-window per key: the window_start is set on the first login POST
 *  and is NOT updated on subsequent attempts within the same window. When a
 *  later attempt arrives after the window has expired (NOW() - window_start >=
 *  lockoutSeconds), the row is reset atomically: window_start = NOW(),
 *  attempts = 1, last_attempt = NOW(). This is implemented inside the ODK
 *  UPDATE expression so that reset and increment happen in a single statement,
 *  preventing races between the expiry check and the write.
 *
 *  Atomicity: record-then-check
 *  ----------------------------
 *  The attempt is recorded first (INSERT … ON DUPLICATE KEY UPDATE), then the
 *  resulting attempt count is read back and checked — all within a single
 *  database transaction with SELECT … FOR UPDATE. This prevents TOCTOU: any
 *  concurrent request that also passes a pre-check must still contend for the
 *  same row lock, and each increment is serialised. The lock is not held
 *  through password_verify() (slow bcrypt), which runs after the transaction
 *  commits.
 *
 *  Cleanup
 *  -------
 *  A bounded DELETE (LIMIT 50) removes up to 50 expired rows at the start of
 *  every login POST using the idx_login_attempts_last index. Bounding the
 *  DELETE prevents a single request from scanning or deleting an arbitrarily
 *  large set of rows on a busy installation. Because cleanup runs only during
 *  login requests, rows are not guaranteed to be deleted within 15 minutes of
 *  expiry under zero-traffic conditions; operators on very low-traffic
 *  installations should schedule a periodic SQL DELETE (e.g. a cron job or
 *  hosting control-panel scheduled task) if a stricter wall-clock guarantee is
 *  required. See docs/provisioning.md for the actual retention guarantee.
 *
 *  Fail-safe
 *  ---------
 *  A database error during throttle record-and-check causes the login request
 *  to return a generic system error without proceeding to authentication. This
 *  avoids silently bypassing throttling under degraded conditions.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$errors   = [];
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

    $email    = trim($email);
    $oldEmail = $email; // repopulate form on error (safe to echo after e())

    // -------------------------------------------------------------------------
    // Throttle configuration
    // -------------------------------------------------------------------------
    $lockoutSeconds = 15 * 60; // 15-minute fixed window
    $ipMaxAttempts  = 50;      // per-IP hard limit; email limit is not a hard lock

    // -------------------------------------------------------------------------
    // Validate the HMAC secret before proceeding.
    //
    // throttle_hmac_secret() returns false when the key is missing or is still
    // the placeholder string from db.example.php. In that case we fail closed:
    // the login cannot proceed, which also means throttling cannot be bypassed
    // by leaving the secret unconfigured.
    // -------------------------------------------------------------------------
    $hmacSecret = throttle_hmac_secret();
    if ($hmacSecret === false) {
        error_log(
            'Daló: throttle_hmac_secret is missing or is still the placeholder value in config/db.php. ' .
            'Set a cryptographically random 64-hex-char secret before deploying.'
        );
        $errors[] = 'A system error occurred. Please try again later.';
    }

    // -------------------------------------------------------------------------
    // Derive HMAC throttle keys.
    //
    // Key format stored in login_attempts.id:
    //   'ip:'    . hash_hmac('sha256', rawIp,           secret)  — 67 chars
    //   'email:' . hash_hmac('sha256', normalizedEmail, secret)  — 70 chars
    //   Maximum: 70 chars, within VARCHAR(77).
    //
    // Domain prefix ('ip:' / 'email:') is included in the HMAC input and stored key to
    // prevent cross-domain collisions between an IP and an email that happen to
    // produce the same raw string after normalization.
    //
    // Email normalization: mb_strtolower(trim()) matches the case-insensitive
    // utf8mb4_unicode_ci collation on users.email so that 'User@Example.COM'
    // and 'user@example.com' always map to the same throttle key.
    //
    // An email key is only derived and stored when the email is syntactically
    // valid (passes FILTER_VALIDATE_EMAIL after normalization). An invalid or
    // empty email submission is throttled only by the IP counter, which is
    // always recorded.
    // -------------------------------------------------------------------------
    $rawIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Always derive the IP key (HMAC secret is confirmed non-false above).
    $ipKey = ($hmacSecret !== false)
        ? 'ip:' . hash_hmac('sha256', "ip\0" . $rawIp, $hmacSecret)
        : null;

    // Derive the email key only for syntactically valid emails.
    $normalizedEmail = mb_strtolower(trim($email));
    $emailKeyValid   = ($hmacSecret !== false)
                       && ($normalizedEmail !== '')
                       && (mb_strlen($normalizedEmail) <= 191)
                       && (bool) filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL);
    $emailKey = $emailKeyValid
        ? 'email:' . hash_hmac('sha256', "email\0" . $normalizedEmail, $hmacSecret)
        : null;

    // -------------------------------------------------------------------------
    // Throttle: record-then-check (atomic, no TOCTOU).
    //
    // Design rationale:
    //   Checking before recording (check-then-act) is vulnerable to races:
    //   N concurrent requests all read attempts < limit and all proceed. Instead,
    //   we record the attempt first inside a transaction with a row lock, then
    //   read back the current counter and decide whether to block.
    //
    //   Steps within a single serialised transaction:
    //     1. Bounded cleanup: DELETE up to CLEANUP_LIMIT expired rows (indexed).
    //     2. INSERT … ODK UPDATE: atomically increment (or reset+start) counters.
    //        The ODK UPDATE resets window_start and attempts when the window has
    //        expired, ensuring a new window starts cleanly.
    //     3. SELECT … FOR UPDATE: lock the rows we just wrote and read back the
    //        current counts to decide whether to block.
    //     4. COMMIT.
    //
    //   password_verify() (slow bcrypt) runs AFTER the transaction commits, so
    //   the row lock is never held during the hash comparison.
    //
    // Note on concurrent first inserts:
    //   If two requests arrive simultaneously for a key that does not exist, one
    //   INSERT succeeds and the other triggers the ODK UPDATE path — both result
    //   in the counter being incremented without phantom rows, because InnoDB
    //   gap-locks on the primary key prevent duplicate inserts from two
    //   concurrent transactions within the same gap.
    // -------------------------------------------------------------------------
    $throttleBlocked = false;

    if (empty($errors) && $ipKey !== null) {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            // Step 1: Bounded cleanup — delete up to 50 expired rows per request.
            // The idx_login_attempts_last index makes this seek fast; the LIMIT
            // bounds the number of rows deleted in one request.
            // This does NOT guarantee wall-clock deletion within 15 minutes under
            // zero traffic; see docs/provisioning.md for the actual guarantee.
            $pdo->prepare(
                'DELETE FROM login_attempts
                  WHERE last_attempt < DATE_SUB(NOW(), INTERVAL ? SECOND)
                  LIMIT 50'
            )->execute([$lockoutSeconds]);

            // Step 2: Record every login POST for the IP key (always) and for the
            // email key (only for syntactically valid emails).
            //
            // ODK UPDATE logic:
            //   • If the window has NOT expired (window_start within lockout
            //     period): increment attempts and update last_attempt.
            //   • If the window HAS expired: reset window_start = NOW(),
            //     attempts = 1, last_attempt = NOW().
            //   This reset is done atomically in a single UPDATE expression —
            //   no separate check-then-write race is possible.
            //
            // The IF() expression evaluates window expiry using database NOW()
            // so that PHP and MySQL clock skew does not affect the decision.
            $odkSql = '
                INSERT INTO login_attempts (id, attempts, window_start, last_attempt)
                VALUES (?, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    attempts     = IF(TIMESTAMPDIFF(SECOND, window_start, NOW()) >= ?,
                                      1,
                                      attempts + 1),
                    window_start = IF(TIMESTAMPDIFF(SECOND, window_start, NOW()) >= ?,
                                      NOW(),
                                      window_start),
                    last_attempt = NOW()
            ';

            // Insert/update the IP key.
            $pdo->prepare($odkSql)->execute([$ipKey, $lockoutSeconds, $lockoutSeconds]);

            // Insert/update the email key if it is valid.
            if ($emailKey !== null) {
                $pdo->prepare($odkSql)->execute([$emailKey, $lockoutSeconds, $lockoutSeconds]);
            }

            // Step 3: Read back the current IP counter under a row lock.
            // FOR UPDATE ensures this read and the commit are serialised with
            // any concurrent transaction that is also writing to this row.
            // Only the IP row is checked for the hard-limit decision.
            $stmt = $pdo->prepare(
                'SELECT attempts
                   FROM login_attempts
                  WHERE id = ?
                  FOR UPDATE'
            );
            $stmt->execute([$ipKey]);
            $ipRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($ipRow !== false && (int) $ipRow['attempts'] >= $ipMaxAttempts) {
                $throttleBlocked = true;
            }

            // Step 4: Commit — releases the row lock before password_verify().
            $pdo->commit();
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Fail closed: do not proceed to authentication.
            error_log('Throttle error: ' . $e->getMessage());
            $errors[] = 'A system error occurred. Please try again later.';
        }
    }

    if ($throttleBlocked) {
        http_response_code(429);
        exit('Too many login attempts from this network. Please wait 15 minutes and try again.');
    }

    // 2. Server-side field validation.
    if (empty($errors)) {
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
    }

    // 3. Database lookup — only when basic validation passes.
    $row = false;
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
        // Keep the IP counter through a successful login. A successful login
        // from one person on a shared network must not reset the rate-limit
        // bucket for every other user behind that IP.

        // Prevent session fixation.
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id'        => (int) $row['id'],
            'role'      => $row['role'],
            'full_name' => $row['full_name'],
        ];

        // Rehash if needed (e.g. algorithm upgrade).
        $algo    = PASSWORD_BCRYPT;
        $options = ['cost' => 12];
        if (password_needs_rehash($row['password_hash'], $algo, $options)) {
            try {
                $hash = password_hash($password, $algo, $options);
                $upd  = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $upd->execute([$hash, (int) $row['id']]);
            } catch (PDOException $e) {
                error_log('Password rehash failed for user ' . (int) $row['id'] . ': ' . $e->getMessage());
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
    }
    // No separate failed-attempt record step: the attempt was already recorded
    // atomically in the throttle transaction above before password_verify().
}
