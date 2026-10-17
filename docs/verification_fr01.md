# Daló — Manual Verification Procedure: Organizer Event Creation (FR-01)

## Overview

This procedure describes how an authorized person can manually verify that the
organizer sign-in and event creation feature works correctly. It does not
require automated tests. Follow each step in order and note the actual outcome
beside each expected outcome.

> **Note:** No tests in this document were run by the implementing agent during
> this session. All checks must be performed manually by an authorized person on
> the deployed instance. Record actual outcomes in the Reporting section at the
> end. Do not claim a test passed unless it was actually executed.

---

## Prerequisites

- `schema.sql` has been imported into the MySQL database (or
  `schema_update_throttle.sql` has been run on an existing installation).
- `config/db.php` exists, contains valid credentials, and contains a
  `throttle_hmac_secret` value that is a cryptographically random string of at
  least 64 hex characters (not the placeholder from `db.example.php`).
  See `docs/provisioning.md` Steps 1–2.
- At least one organizer account has been provisioned (see `docs/provisioning.md`).
- The web server serves `public/` as the document root over HTTPS.
- `session.cookie_secure = 1` is set in `php.ini` or `.user.ini`.

---

## Test 1 — HTTPS and Unauthenticated redirect

1. Open `http://your-domain.example/events/create.php` (explicitly using `http://`).
2. **Expected:** Redirected to HTTPS by the host configuration, or blocked if HTTP is disabled.
3. Open `https://your-domain.example/events/create.php` without signing in.
4. **Expected:** Redirected to `/login.php` (HTTP 302); event creation form is not shown.
5. **Expected:** No session cookie is set (or only an empty/regenerated one).

---

## Test 2 — Login with invalid credentials

1. Open `https://your-domain.example/login.php`.
2. Enter a non-existent email or a wrong password and submit.
3. **Expected:** Form stays on the login page. A generic error message
   "Incorrect email or password." is shown. No detail about whether the email
   exists is revealed.
4. **Expected:** Network inspector shows no redirect; status is 200.

---

## Test 3 — Login with valid organizer credentials

1. Open `https://your-domain.example/login.php`.
2. Enter the organizer email and correct password and submit.
3. **Expected:** HTTP 303 redirect to `/events/create.php`.
4. **Expected:** The event creation form is displayed with the organizer's name
   in the top bar.
5. **Expected:** The session cookie has `HttpOnly` and `SameSite=Lax` flags.
   On HTTPS, it must also have the `Secure` flag.

---

## Test 4 — Event creation validation (empty form)

1. While signed in as an organizer, open `/events/create.php`.
2. Submit the form without filling in any fields.
3. **Expected:** The page reloads with inline error messages beside each
   required field (title, date, time, location, capacity). No redirect.
4. **Expected:** The CSRF token field is present and non-empty in the HTML
   source (value is a hex string; do not publish it).

---

## Test 5 — Event creation validation (invalid capacity)

1. Fill in title, date, time, and location with valid values.
2. Enter `0` in the capacity field and submit.
3. **Expected:** Inline error: "Capacity must be a whole number greater than zero."
4. Repeat with a negative value (e.g. `-1`); same expected error.
5. Repeat with a non-numeric value (e.g. `abc`); same expected error.

---

## Test 6 — Successful event creation

1. Fill in all fields with valid values:
   - Title: `Test Event 2026`
   - Date: a future date, e.g. `2026-12-01`
   - Time: `10:00`
   - Location: `Room A-101`
   - Capacity: `50`
2. Submit the form.
3. **Expected:** HTTP 303 redirect back to `/events/create.php`.
4. **Expected:** A green success banner: "Event created successfully (ID N)."
   Note the event ID.
5. **Expected:** The form fields are cleared.

---

## Test 7 — Database verification

Run the following SQL query (substitute the ID from Test 6):

```sql
SELECT id, title, event_date, event_time, location, capacity, organizer_id, created_at
  FROM events
 WHERE id = <ID from Test 6>;
```

**Expected:**
- `title` = `Test Event 2026`
- `event_date` = `2026-12-01`
- `event_time` = `10:00:00`
- `location` = `Room A-101`
- `capacity` = `50`
- `organizer_id` = the `id` of the signed-in organizer (from `SELECT id FROM users WHERE email = 'organizer@…'`)
- `created_at` is a recent timestamp

---

## Test 8 — CSRF protection

1. Use a tool (e.g. `curl`) to POST to `/events/create.php` without a valid
   `_csrf_token` field.
2. **Expected:** HTTP 403 response; no event is created in the database.

---

## Test 9 — Non-organizer access denied

1. If a facilitator or student account exists, attempt to sign in at
   `/login.php` using their credentials.
2. **Expected:** Login fails with "Incorrect email or password." (Students are
   not permitted to log in through this interface. Facilitator login is also
   currently rejected here until their dashboard is implemented).

---

## Test 10 — Sign out (POST) and GET rejection

1. While signed in, navigate to `https://your-domain.example/logout.php` by
   typing the URL (a GET request).
2. **Expected:** HTTP 405 Method Not Allowed. The session is not destroyed.
3. Go back to `/events/create.php` and click the "Sign out" button (a POST
   request with CSRF token).
4. **Expected:** HTTP 303 redirect to `/login.php`.
5. **Expected:** Session is destroyed; navigating to `/events/create.php`
   immediately after redirects back to login.

---

## Test 11 — IP-based throttling (single IP)

This test verifies the per-IP hard lockout.

1. From a single IP address, submit the login form 50 times. These are counted
   login POSTs, whether credentials are valid or invalid.
2. **Expected:** The 50th POST and later POSTs in that fixed window receive
   HTTP 429 with the message
   "Too many login attempts from this network. Please wait 15 minutes and
   try again."
3. Attempt to log in with the correct credentials from the same IP while the
   window is active.
   **Expected:** HTTP 429.
4. Wait until at least 15 minutes have passed since `window_start` and submit a
   new login POST to trigger the fixed-window reset.
   **Expected:** The window resets: HTTP 200 (not 429), and the counter in
   `login_attempts` now shows `attempts = 1`.
5. Log in with correct credentials from the same IP.
   **Expected:** HTTP 303 redirect (success).

Database check after triggering IP throttling:

```sql
-- The id column stores an HMAC digest, not a raw IP address.
SELECT id, attempts, window_start, last_attempt
  FROM login_attempts;
```

**Expected:** At least one row with `attempts >= 50` (the limit may be
exceeded because every blocked POST is still counted). The `id`
column must contain a `ip:` prefix followed by a 64-character hex string — no
raw IP address visible.

---

## Test 12 — Simultaneous requests at and beyond the IP limit

This test requires the ability to send concurrent HTTP requests (e.g. using
`curl` with `&` in bash, Apache Bench, or a load-testing tool).

**Sub-test A — concurrent increments below the limit:**

1. Ensure the IP counter is at 0 (no rows for this IP) or reset it:

   ```sql
   DELETE FROM login_attempts;
   ```

2. Send **10 concurrent POST requests** to `/login.php` with incorrect
   credentials from the same IP.
3. After all requests complete, query `login_attempts`:

   ```sql
   SELECT id, attempts FROM login_attempts WHERE id LIKE 'ip:%';
   ```

   **Expected:** Exactly one row, with `attempts = 10`. No attempt should be
   double-counted (> 10) or dropped (< 10) due to races. The record-then-check
   design (INSERT/ODK inside a transaction with FOR UPDATE) serialises
   concurrent increments.

**Sub-test B — concurrent requests straddling the limit:**

1. Reset the counter and manually set attempts to 49:

   ```sql
   DELETE FROM login_attempts;
   ```

2. Send one request to establish a row, then update it:

   ```sql
   UPDATE login_attempts SET attempts = 49 WHERE id LIKE 'ip:%';
   ```

3. Send **5 concurrent POST requests** from the same IP.
4. **Expected:** Exactly one response is HTTP 429 for the request that pushes
   `attempts` to 50 (the limit). Subsequent concurrent requests in the same
   batch may also receive 429 once the limit is reached and visible to later
   transactions. No request should bypass the limit and reach password
   verification.

---

## Test 13 — Expired-window reset during continued failures

This test verifies that a row whose window_start has expired is correctly reset
on the next attempt, rather than accumulating unbounded attempts.

1. Create a row with a past `window_start`:

   ```sql
   DELETE FROM login_attempts;
   ```

2. Submit one failed login attempt to create a fresh row.
3. Manually roll back the window to simulate expiry:

   ```sql
   UPDATE login_attempts
      SET window_start = DATE_SUB(NOW(), INTERVAL 16 MINUTE),
          last_attempt  = DATE_SUB(NOW(), INTERVAL 16 MINUTE);
   ```

4. Submit another failed login attempt.
5. **Expected:** The row is reset — `attempts = 1` and `window_start` is
   approximately NOW(). The old window_start value is gone.

   ```sql
   SELECT id, attempts, window_start, last_attempt
     FROM login_attempts
    WHERE id LIKE 'ip:%';
   ```

6. **Expected:** `attempts = 1`, `window_start` within the last minute. The
   old expired window did not carry over.

**Bypass scenario (verify it does NOT work):**

An attacker tries to keep `last_attempt` fresh (to prevent cleanup) while
`window_start` stays old (to avoid hitting the limit). The ODK UPDATE resets
both `window_start` AND `attempts` when the window expires, so this strategy
does not work — the counter restarts at 1 whenever the window has expired.

---

## Test 14 — Account-denial resistance from one IP

This test verifies that an attacker from a single IP cannot trivially lock
a legitimate organizer out of their account.

1. From an attacker's IP (simulate with a different browser/tool), submit
   failed login attempts for the legitimate organizer's email address.
   Continue until the attacker's IP is blocked (HTTP 429 at 50 attempts).
2. From the legitimate organizer's IP (a separate connection), attempt to log
   in with the correct password.
3. **Expected:** The legitimate organizer's login succeeds (HTTP 303 redirect),
   because the per-IP limit applies to the attacker's IP, not the organizer's
   IP. There is no independent per-email hard lockout.

Database check:

```sql
SELECT id, attempts FROM login_attempts;
```

**Expected:** One row for the attacker's IP key (`ip:…`, attempts = 50). The
email key row (`email:…`) has attempts reflecting the attacker's submissions
but does NOT trigger a lockout.

---

## Test 15 — Account-denial resistance from multiple IPs

An attacker distributes attempts across N IPs, each submitting fewer than 50
requests, targeting the same organizer email address.

1. Simulate multiple IP addresses by modifying `REMOTE_ADDR` in a test harness
   or by using actual different source IPs.
2. From each IP, submit fewer than 50 failed login attempts for the organizer's
   email.
3. From the legitimate organizer's IP, attempt to log in with the correct
   password.
4. **Expected:** Login succeeds. No individual IP has hit the 50-attempt limit.
   The per-email audit row accumulates a high attempt count but does not
   cause a lockout.

**Note:** Distributed attacks that stay under the per-IP threshold cannot be
stopped by on-server throttling alone. This is a known, documented limitation.
External controls (WAF, hosting-provider rate limiting) are required for this
threat model.

---

## Test 16 — HMAC secret: missing, placeholder, and valid

**Sub-test A — secret is missing from config/db.php:**

1. Temporarily remove or comment out `throttle_hmac_secret` from `config/db.php`.
2. Submit a login attempt.
3. **Expected:** HTTP 200 with "A system error occurred. Please try again later."
   No 429 is returned; no throttle record is written.
4. **Expected:** The PHP error log contains an operator-only message about the
   missing secret (no raw IP, email, or secret value in the log message).
5. Restore the key.

**Sub-test B — secret is the placeholder value:**

1. Set `throttle_hmac_secret` to exactly
   `REPLACE_WITH_64_HEX_CHARS_FROM_bin2hex_random_bytes_32`.
2. Submit a login attempt.
3. **Expected:** Same as Sub-test A — system error, no throttle record, log
   message for the operator.
4. Restore the key.

**Sub-test C — secret is short or not hexadecimal:**

1. Set `throttle_hmac_secret` first to a 32-character string, then to a
   64-character string containing non-hex characters (for example, 64 `z`
   characters).
2. Submit a login attempt.
3. **Expected:** Both values produce the same result as Sub-test A — system
   error, no throttle record.
4. Restore the key.

**Sub-test D — secret is a valid 64-hex-char random value:**

1. Confirm `throttle_hmac_secret` in `config/db.php` is a properly generated
   64-hex-char string.
2. Submit a failed login attempt.
3. **Expected:** HTTP 200 with "Incorrect email or password." (normal flow).
   A row appears in `login_attempts` with an HMAC-keyed `id`.

---

## Test 17 — Malformed and empty email submissions

**Sub-test A — empty email:**

1. Submit the login form with the email field blank and a non-empty password.
2. **Expected:** HTTP 200, "Email address is required." error message.
3. **Expected:** No new email-keyed row (`email:…`) is created in
   `login_attempts`. The IP-keyed row (`ip:…`) IS incremented.

   ```sql
   SELECT id FROM login_attempts;
   ```

   **Expected:** Only an `ip:…` row. No `email:…` row for an invalid address.

**Sub-test B — syntactically invalid email (`notanemail`):**

1. Submit with `email = notanemail` and any password.
2. **Expected:** HTTP 200, "Enter a valid email address." No email-keyed row.
   IP-keyed row is incremented.

**Sub-test C — overlong email (> 191 characters):**

1. Submit with an email exceeding 191 characters.
2. **Expected:** HTTP 200, "Email address is too long." No email-keyed row.
   IP-keyed row is incremented.

**Sub-test D — case variants of a valid email:**

1. Submit three failed attempts with `user@example.com`,
   `User@Example.COM`, and `USER@EXAMPLE.COM`.
2. **Expected:** All three are normalized to `user@example.com` before HMAC
   keying, so they all update the **same** `email:…` row (attempts = 3).
   This matches the `utf8mb4_unicode_ci` case-insensitive collation on
   `users.email`.

   ```sql
   SELECT id, attempts FROM login_attempts WHERE id LIKE 'email:%';
   ```

   **Expected:** Exactly one `email:…` row with `attempts = 3`.

---

## Test 18 — Cleanup behavior and actual retention guarantee

1. Trigger at least one failed login attempt to create a row in `login_attempts`.

2. Manually set `last_attempt` to simulate an expired row:

   ```sql
   UPDATE login_attempts
      SET last_attempt = DATE_SUB(NOW(), INTERVAL 16 MINUTE);
   ```

3. Submit a new login attempt (any credentials).

4. **Expected:** The expired row created in step 1 is gone after the request:

   ```sql
   SELECT * FROM login_attempts WHERE last_attempt < DATE_SUB(NOW(), INTERVAL 15 MINUTE);
   ```

   **Expected:** No rows returned.

5. Confirm the index used by cleanup exists:

   ```sql
   SHOW INDEX FROM login_attempts WHERE Key_name = 'idx_login_attempts_last';
   ```

   **Expected:** One row for `idx_login_attempts_last` on column `last_attempt`.

**Retention guarantee note:** Cleanup runs only on login POST requests. On a
zero-traffic or very-low-traffic installation, expired rows persist until the
next login attempt. The retention guarantee is: "rows are deleted on the next
login attempt after expiry." A wall-clock guarantee (e.g. "deleted within 15
minutes regardless of traffic") requires a scheduled cron job; see
`docs/provisioning.md`.

---

## Test 19 — Migration from old login_attempts schema

**Applicable when upgrading from a throttle schema that does not contain
`window_start`.** The migration deliberately discards old throttle counters,
including any still-active ones; schedule the migration accordingly.

1. Before running the migration, confirm the old schema:

   ```sql
   DESCRIBE login_attempts;
   ```

   **Expected (old schema):** `id` is `VARCHAR(191)` with no `window_start` column.

2. Back up the database.

3. Run the migration:

   ```bash
   mysql -u USER -p DBNAME < schema_update_throttle.sql
   ```

4. Verify the new schema:

   ```sql
   DESCRIBE login_attempts;
   ```

   **Expected (new schema):**

   | Field        | Type         | Null | Key | Default           |
   |--------------|--------------|------|-----|-------------------|
   | id           | varchar(77)  | NO   | PRI | NULL              |
   | attempts     | int unsigned | NO   |     | 1                 |
   | window_start | datetime     | NO   |     | CURRENT_TIMESTAMP |
   | last_attempt | datetime     | NO   | MUL | CURRENT_TIMESTAMP |

5. **Expected:** For a legacy table, its rows are discarded and the current
   schema is created. The migration only drops the table when `window_start`
   is absent; active counters are reset as part of this one-time upgrade.

6. Submit a failed login attempt and confirm a new row appears with an
   HMAC-keyed `id` (format: `ip:` followed by 64 hex chars; no raw IP text).

7. Run the migration again after creating a current throttle row.
   **Expected:** The current table and row remain intact; the migration does
   not drop tables that already have `window_start`.

---

## Reporting

For each test, record:
- **Date/time** of the test
- **Actual outcome** (matched / did not match expected)
- **Any error message** observed in the browser or server error log
- **Tester name / role**

No tests were run by the implementing agent during this session; all checks
above must be performed manually by an authorized person on the deployed
instance.
