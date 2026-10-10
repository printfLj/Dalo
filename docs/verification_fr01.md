# Daló — Manual Verification Procedure: Organizer Event Creation (FR-01)

## Overview

This procedure describes how an authorized person can manually verify that the
organizer sign-in and event creation feature works correctly. It does not
require automated tests. Follow each step in order and note the actual outcome
beside each expected outcome.

---

## Prerequisites

- `schema.sql` has been imported into the MySQL database.
- `config/db.php` exists and contains valid credentials (see `config/db.example.php`).
- At least one organizer account has been provisioned (see `docs/provisioning.md`).
- The web server serves `public/` as the document root over HTTPS.
- `session.cookie_secure = 1` is set in `php.ini` or `.user.ini`.

---

## Test 1 — HTTPS and Unauthenticated redirect

1. Open `http://your-domain.example/events/create.php` (explicitly using `http://`).
2. **Expected:** Redirected to HTTPS by the host configuration, or blocked if HTTP is disabled.
3. Open `https://your-domain.example/events/create.php` without signing in.
4. **Expected:** Redirected to `/login.php` (HTTP 302); event creation form is
   not shown.
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

1. While signed in, navigate to `https://your-domain.example/logout.php` by typing the URL (a GET request).
2. **Expected:** HTTP 405 Method Not Allowed. The session is not destroyed.
3. Go back to `/events/create.php` and click the "Sign out" button (a POST request with CSRF token).
4. **Expected:** HTTP 303 redirect to `/login.php`.
5. **Expected:** Session is destroyed; navigating to `/events/create.php`
   immediately after redirects back to login.

---

## Test 11 — Login Throttling

1. From a single IP address, submit the login form 6 times with an incorrect password for an existing account.
2. **Expected:** On the 6th attempt, the response is HTTP 429 "Too many login attempts for this account. Please try again later."
3. **Expected:** Wait 15 minutes, then attempt to log in with the correct password. It should succeed.

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
