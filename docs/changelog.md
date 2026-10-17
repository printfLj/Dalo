# Changelog

## 2026-10-10

- Added root `AGENTS.md` with project implementation, security, privacy, offline-cache, and session-record requirements based on the Daló SRS and the user's instructions.
- Validation: reviewed the generated guidance against the SRS requirements; no application code or automated checks were run.

## 2026-10-10 — Database Schema

- Created `schema.sql` in the project root with three InnoDB tables: `users`, `events`, and `registrations`.
- `users`: unsigned auto-increment PK; `role` ENUM restricted to `organizer`, `facilitator`, `student`; `full_name`, unique `email`, `password_hash` (bcrypt/Argon2 — plaintext never stored); nullable `student_id` with a UNIQUE index permitting multiple NULLs; `created_at` / `updated_at` timestamps.
- `events`: unsigned auto-increment PK; `title`, `event_date`, `event_time`, `location`, `capacity` (INT UNSIGNED — positive enforcement was not yet database-enforced at this stage; see the schema-fixes entry below); `organizer_id` FK → `users.id` (ON DELETE RESTRICT); `created_at` / `updated_at` timestamps; indexes on `organizer_id` and `event_date`.
- `registrations`: unsigned auto-increment PK; `event_id` and `user_id` FKs (both ON DELETE RESTRICT); `qr_token_hash BINARY(32) NOT NULL UNIQUE` to store only the SHA-256 hash of the PHP-generated bearer token (raw token must never be persisted); `registered_at` timestamp; nullable `checked_in_at` (NULL = not yet admitted); UNIQUE constraint on `(event_id, user_id)` preventing double-registration; indexes on `event_id`, `user_id`, and `(event_id, checked_in_at)` for attendance reporting.
- Collation: `utf8mb4_unicode_ci` throughout; compatible with MySQL 5.7 and 8.x.
- No DROP statements, sample data, credentials, or file-upload columns included.
- Security contract for QR token documented in SQL comments: `random_bytes(32)` → URL-safe encode → embed in QR; `hash('sha256', rawToken, true)` → store in `qr_token_hash`; comparison via `hash_equals()`; retry INSERT on collision.
- Validation: schema reviewed manually against AGENTS.md rules and the SRS requirements — all foreign-key types match (unsigned INT), QR hash column is UNIQUE and BINARY(32), no predictable credential values, no plaintext passwords, ON DELETE RESTRICT on both FKs. No automated MySQL execution was performed; a live syntax check requires a running MySQL instance.

## 2026-10-10 — Schema fixes

- **Removed** redundant `SET CHARACTER SET utf8mb4` statement (line 30). `SET NAMES utf8mb4` is sufficient and sets the connection character set independently of the selected database; the extra statement was harmless but misleading.
- **Shortened** `users.email` from `VARCHAR(320)` to `VARCHAR(191)`. With `utf8mb4` (up to 4 bytes per character), a `VARCHAR(320)` unique index requires 1,280 bytes, which exceeds MySQL 5.7 InnoDB's 767-byte key-prefix limit and would prevent creation of `uq_users_email`. At `VARCHAR(191)`, the index uses 764 bytes (191 × 4), safely within the limit. The application MUST reject email addresses longer than 191 characters during server-side validation.
- **Added** `BEFORE INSERT` and `BEFORE UPDATE` triggers on `events` (`trg_events_capacity_insert`, `trg_events_capacity_update`) that `SIGNAL SQLSTATE '45000'` when `NEW.capacity < 1`. MySQL 5.7 silently ignores `CHECK` constraints, so triggers are the correct MySQL 5.7-compatible mechanism. The triggers use `DELIMITER $$` / `DELIMITER ;` client-side syntax required for multi-statement blocks.
- **Updated** the `capacity` column comment to reference the triggers instead of an unenforced `CHECK` constraint. Corrected the prior changelog entry's claim that the schema enforced positive capacity, which was inaccurate before this fix.
- Validation: all three changes reviewed statically against the MySQL 5.7 and 8.x reference documentation. Foreign-key types confirmed to still match (unsigned INT). Email index byte cost recalculated (191 × 4 = 764 ≤ 767). Trigger `SIGNAL` syntax verified against MySQL 5.7 docs. No automated execution against a live MySQL server was performed.

## 2026-10-10 — QR token comment clarity

- Expanded the **file-header security contract** bullet for scan-time comparison to make the decode step explicit: the scanned URL-safe token must be decoded back to raw bytes (e.g. `base64url_decode()` / `hex2bin()`) before SHA-256 is computed. Added a note that hashing the encoded string directly produces a different digest and silently breaks check-in.
- Replaced the **inline `qr_token_hash` column comment** with two annotated pseudocode flows — one for registration (generate → encode → hash raw bytes → store hash) and one for check-in (read token → decode to raw bytes → hash → look up → `hash_equals()`) — making the decode-before-hash requirement unambiguous at the point of use.
- No table definitions, triggers, foreign keys, or application files were modified.
- Validation: changes are comment-only; reviewed for accuracy against PHP `hash()` `binary=true` semantics and the base64url round-trip contract. No automated execution was performed.

## 2026-10-10 — FR-01 Organizer Event Creation (application foundation)

### Files created

| File | Purpose |
|---|---|
| `config/db.example.php` | Database credential template — real file (`config/db.php`) is git-ignored |
| `.gitignore` | Excludes `config/db.php`, `.env`, logs, OS/editor artefacts |
| `src/bootstrap.php` | Singleton PDO factory, secure session startup, CSRF helpers, output-encoding helper (`e()`), role-based authorization guard (`require_organizer()`) |
| `src/auth.php` | Login logic: CSRF check, field validation, `password_verify()`, session fixation prevention (`session_regenerate_id(true)`), rehash-on-upgrade, safe post-login redirect |
| `src/event_create.php` | FR-01 business logic: organizer guard, CSRF, per-field server validation, parameterized PDO INSERT into `events`, trigger-aware error handling, redirect-after-POST with flash message |
| `public/index.php` | Root entry point — routes to event creation or login |
| `public/login.php` | Login page: renders CSRF-protected form, repopulates email on failure |
| `public/logout.php` | Destroys session, clears cookie, redirects to login |
| `public/events/create.php` | Event creation page: form with per-field inline errors, accessible aria attributes, topbar with CSRF-protected sign-out |
| `public/css/app.css` | Mobile-first stylesheet with design tokens, auth/app layouts, form components, alert variants |
| `public/.htaccess` | Apache config: HTTP→HTTPS redirect, front-controller routing, security headers, hidden-file denial |
| `docs/provisioning.md` | First-organizer provisioning procedure (interactive CLI hash generation → SQL INSERT; no public bootstrap endpoint) |
| `docs/verification_fr01.md` | 10-step manual verification procedure for sign-in and FR-01 event creation |

### Security measures applied
- PDO with `ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES = false`, `utf8mb4` charset.
- All user input bound via prepared-statement parameters; no string concatenation in SQL.
- CSRF token validated on every POST before any processing.
- `password_verify()` with dummy verify on unknown email to prevent timing oracle.
- `session_regenerate_id(true)` after successful login.
- `password_needs_rehash()` auto-upgrade on login.
- Generic "Incorrect email or password." message — does not reveal whether email exists.
- Students cannot log in through this interface (role check rejects non-organizer/facilitator).
- Redirect-after-POST (PRG) pattern prevents duplicate event inserts on browser refresh.
- Open-redirect guard on post-login redirect target.
- Database trigger `SQLSTATE '45000'` caught separately from other PDO errors; generic message shown for all others.
- No file inputs, upload paths, or multipart handlers anywhere.
- Credentials kept outside committed files; `config/db.php` is git-ignored.

### Not implemented in this task (out of scope)
- FR-09 registration-time capacity closure — deferred to the student registration module.
- QR credential generation, scanning, check-in, attendance export, or student-facing pages.
- Public organizer self-registration or seeded/default credentials.

### Validation
No automated tests or live server execution were performed during this session. A 10-step manual verification procedure has been documented in `docs/verification_fr01.md`. All code was reviewed statically against AGENTS.md security rules, the SRS (FR-01), and PHP 8.x / MySQL 5.7+ compatibility requirements.

### Setup requirements before verification
1. Import `schema.sql` into the MySQL database.
2. Copy `config/db.example.php` → `config/db.php` and fill in real credentials.
3. Provision the first organizer account per `docs/provisioning.md`.
4. Point the web server document root at `public/` and enable `mod_rewrite`.
5. Set `session.cookie_secure = 1` in `php.ini` or `.user.ini` on the HTTPS host.

## 2026-10-10 — FR-01 Implementation Fixes

- **`public/logout.php`**: Made logout strictly POST-only. Rejects GET requests with HTTP 405 Method Not Allowed to prevent accidental or crawler-induced logouts.
- **`src/auth.php`**: 
  - Deferred facilitator login: strictly requires the 'organizer' role to authenticate, preventing redirect loops since the facilitator dashboard is not yet implemented.
  - Replaced the malformed dummy bcrypt hash with a valid one (`$2y$12$4mLCkcc6xDzuxbkfkw9BveoUfjQyRCyy3l8YQaobcfJwNHVqS7S8e`) to properly prevent user-enumeration timing attacks.
  - Implemented IP-based login throttling (max 5 attempts, 15-minute lockout) to mitigate brute-force attacks on shared hosting.
- **`schema.sql`**: Added `login_attempts` table to support login throttling.
- **`src/event_create.php`, `src/auth.php`, `src/bootstrap.php`**: Fixed type safety by explicitly checking that `$_POST` inputs are strings before passing them to string functions (like `trim`, `preg_match`, `hash_equals`), avoiding uncaught TypeErrors from malformed requests.
- **`src/event_create.php`**: Strengthened `event_time` regex to only accept real clock values (e.g., hours 00-23, minutes 00-59) instead of just any two digits.
- **`src/bootstrap.php`**: Added `Cache-Control: no-store` and related headers for all authenticated responses to prevent browsers from caching sensitive private pages.
- **`public/.htaccess`**: 
  - Removed the unsafe app-level HTTP→HTTPS redirect that reflected `HTTP_HOST`. Documented that HTTPS redirection should be done via host configuration or a strict allowlist.
  - Updated `Permissions-Policy` to `camera=(self)` to permit same-origin camera usage for future QR scanning (FR-05).
- **`docs/provisioning.md`**: Corrected the PHP interactive `readline()` instruction which echoes input on most terminals. Replaced it with instructions to create, run, and immediately delete a temporary local PHP script to safely generate the password hash without polluting shell history.
- Validation: Ran PHP syntax checks on `public/logout.php`, `src/auth.php`, `src/bootstrap.php`, and `src/event_create.php` via PowerShell. All passed. Reviewed changes statically. No live MySQL execution or browser testing was performed.

## 2026-10-10 — Code Review Fixes (Throttling, Provisioning, Docs)

- **`src/auth.php` & `schema.sql`**: Reworked login throttling. `login_attempts` now tracks attempts by a polymorphic `id` (e.g., `ip:...` and `email:...`). Increased the IP-based limit to 50 (for shared networks) while setting the account-based email limit to 5. Throttling is evaluated atomically via the timestamp, and old records are randomly purged to prevent unrestricted growth. Fail-closed error handling is maintained if the database is unavailable during the throttle check.
- **`src/auth.php`**: Corrected dummy password verification to strictly use `PASSWORD_BCRYPT` with cost 12 instead of assuming `PASSWORD_DEFAULT`, aligning perfectly with the dummy hash generated during failed lookups to guarantee constant-time evaluation. Also explicitly specified this configuration for password rehashing.
- **`docs/provisioning.md`**: 
  - Overhauled the password provisioning procedure to use a `read -s` bash script via stdin to pipe the password securely into PHP, avoiding shell history, process list exposure, or echoing on screen.
  - Added explicit instructions requiring the hosting provider to configure HTTPS redirection before provisioning.
  - Added data privacy (RA 10173) documentation detailing the purpose, automatic purging, and access controls for the IP/email data logged during throttling.
- **`public/logout.php`**: Corrected the docblock comment that mistakenly stated GET was accepted.
- **`docs/verification_fr01.md`**: Updated the manual test procedure to verify HTTPS redirection (Test 1), explicitly test HTTP 405 rejection of a GET logout (Test 10), verify that facilitators are rejected (Test 9), and test the new login throttling behavior (Test 11).
- **`schema_update_throttle.sql`**: Added a separate, safe migration file with `CREATE TABLE IF NOT EXISTS` for existing deployments that missed the initial `schema.sql` update.
- Validation: Ran PHP syntax checks on `public/logout.php`, `src/auth.php`, `src/bootstrap.php`, and `src/event_create.php`. All passed. Evaluated diff for personal data or exposed secrets. No live database connection or browser tests were executed.

## 2026-10-11 — Login Configuration Diagnosis

- Reviewed the project structure, `docs/`, `config/`, `schema.sql`, and the login/authentication path to diagnose the runtime message "Application configuration error. Contact the administrator."
- Confirmed the message is emitted by `src/bootstrap.php` when `config/db.php` is missing, before any email/password verification occurs.
- Reconfirmed from the provisioning documentation that Daló intentionally has no default, seeded, or public self-registration organizer account; an organizer must be provisioned directly in the database after importing the schema.
- Validation: static inspection only. No live MySQL connection, browser login attempt, or PHP syntax test was run during this diagnostic session. Reviewed the touched changelog entry for secrets, personal data, upload behavior, and SRS/AGENTS conflicts; none were introduced.

## 2026-10-17 — Corrective Revision: Throttling, Provisioning, and Privacy

> **Correction notice:** Two claims in the 2026-10-10 "Code Review Fixes" entry were
> inaccurate and are corrected here. Previous entry stated throttling was "evaluated
> atomically via the timestamp" — in fact the SELECT and the later INSERT/UPDATE were
> separate statements with no transaction or row lock, leaving a TOCTOU race window
> under concurrent requests. It also stated old records were "randomly purged" in a
> way that prevents unrestricted growth — in fact the 1-in-20 probabilistic DELETE gave
> no bounded-time guarantee on low-traffic installations. Both issues are resolved
> below. Historical entries are preserved unchanged.

### Changes

**`schema_update_throttle.sql`** — Complete rewrite:
- Old schema used `CREATE TABLE IF NOT EXISTS` with `VARCHAR(191) id`, which silently
  fails to upgrade an existing table with a different structure (e.g., old ip_address
  column). Fixed by using `DROP TABLE IF EXISTS` before `CREATE TABLE`, with detailed
  migration-path commentary explaining all three cases (no table / old table / current
  table) and why discarding old plain-text-keyed rows is correct.
- Added `window_start DATETIME` column to record when the current window began.
- Added `INDEX idx_login_attempts_last (last_attempt)` to support bounded cleanup
  without a full-table scan.

**`schema.sql`** — Updated `login_attempts` table definition to match:
- `id` narrowed from `VARCHAR(191)` to `VARCHAR(77)` (domain prefix + 64-char HMAC hex).
- `window_start` column added.
- `idx_login_attempts_last` index added.
- Comments updated to describe HMAC-keyed storage and deterministic retention.

**`src/auth.php`** — Substantial rewrite of throttle logic:
- **Concurrency safety:** The cleanup DELETE and SELECT now run inside a transaction
  with `SELECT … FOR UPDATE`, serialising concurrent requests for the same throttle
  key. The INSERT/ON DUPLICATE KEY UPDATE record step runs in a separate transaction
  after `password_verify()` to avoid holding the lock through a slow hash operation.
- **Bounded retention:** The probabilistic 1-in-20 cleanup is replaced by a
  deterministic `DELETE WHERE last_attempt < DATE_SUB(NOW(), INTERVAL ? SECOND)` that
  runs at the start of every login POST. The `idx_login_attempts_last` index ensures
  this never scans the full table. Rows are guaranteed to be deleted within 15 minutes
  of their last failed attempt regardless of traffic volume.
- **HMAC throttle keys:** `ip:<addr>` and `email:<addr>` plain-text prefixed keys
  replaced by domain-prefixed HMAC-SHA-256 digests. Raw IP addresses and email
  addresses are no longer stored in `login_attempts`. The HMAC secret is read from
  `config/db.php['throttle_hmac_secret']` (outside version control) with a
  logged-warning fallback.
- **Email normalisation:** The email key is HMAC'd of `mb_strtolower(trim($email))`,
  matching `utf8mb4_unicode_ci` case-insensitive collation used by `users.email`.
  All case variants of the same address produce the same throttle key.
- **Email lockout DoS resistance:** The email-based limit is raised from 5 to 10
  attempts per 15-minute window. A strict 5-attempt hard lockout allowed an
  unauthenticated attacker to deny organizer access with minimal effort. At 10,
  the limit still blocks automated credential-stuffing while making a targeted DoS
  significantly harder. The IP limit remains 50 and provides the primary protection
  against automated attacks from a single source. See `docs/provisioning.md` for
  full policy rationale.
- **Fail-closed:** A database error during the throttle check causes the request to
  return a system-error message instead of proceeding — consistent with the existing
  fail-closed design.
- **Empty/malformed email:** The HMAC is computed even for empty/invalid emails
  (output is always a fixed-length hex string), so malformed submissions cannot
  create arbitrarily long `login_attempts` rows. Field validation rejects empty and
  overlong emails before the database lookup.
- **429 message:** Unified to "Too many login attempts. Please wait 15 minutes and
  try again." (does not distinguish IP vs. email limit, avoiding information leakage).

**`src/bootstrap.php`** — Added `throttle_hmac_secret()` helper:
- Reads `throttle_hmac_secret` from `config/db.php` via a static singleton.
- Falls back to a DSN-derived HMAC with a logged warning if the key is missing.
- Documents the configuration requirement inline.

**`config/db.example.php`** — Added `throttle_hmac_secret` key:
- Documents purpose, generation command (`php -r "echo bin2hex(random_bytes(32))…"`),
  rotation warning, and fallback behaviour.

**`docs/provisioning.md`** — Revised:
- Step 1 (password hash): Changed `read -s` to `read -s -r` (preserves backslashes
  in passwords); changed `echo -n "$pass"` to `printf '%s' "$pass"` (avoids newline
  appended to the password). Added inline notes explaining both changes.
- Added Step 2: Generate and set `throttle_hmac_secret` in `config/db.php`.
- Updated RA 10173 section: accurately states that raw IPs and emails are not stored,
  describes HMAC digest storage, corrects retention to a guaranteed 15-minute maximum
  (not "typically within 15 to 24 hours"), explains the revised lockout limits and
  their DoS-resistance rationale, and describes access controls.

**`docs/verification_fr01.md`** — Added six new test cases:
- Test 12: Email-based throttling and lockout DoS resistance check.
- Test 13: Case-insensitive email throttling (all case variants hit the same counter).
- Test 14: Malformed or empty email submissions (bounded key, field validation).
- Test 15: Migration from old plain-text-keyed schema (schema structure before/after,
  confirmation that old rows are replaced not preserved).
- Test 16: Simultaneous login attempts / concurrency safety (`attempts` count must be
  exact, not drifted by races).
- Test 17: Cleanup and retention guarantee (manual `last_attempt` rollback, confirm
  row deleted on next request, confirm index presence).
- Updated prerequisites to include `throttle_hmac_secret` in config.

### Validation performed

- PHP syntax checks run on `src/auth.php`, `src/bootstrap.php`, and
  `config/db.example.php` via `C:\php\php.exe -l`. All three passed with no errors.
- `schema_update_throttle.sql` and `schema.sql` reviewed statically for consistency:
  column names, types, and index names match between the two files and the PHP code.
- Diff reviewed for accidental secrets, personal data, upload handling, or AGENTS.md
  violations. None found.
- No live MySQL connection, browser session, or concurrent-request test was executed
  during this session. Manual verification per `docs/verification_fr01.md` Tests
  11–17 is required before claiming the throttling system is production-ready.

## 2026-10-17 — Second Corrective Revision: Throttling Defects (Second Pass)

> **Correction notice for the 2026-10-17 first-pass entry:** Several claims in
> the "Corrective Revision: Throttling, Provisioning, and Privacy" entry above
> did not match the code that was actually committed. The specific inaccuracies
> are listed below and corrected in this entry.

### Inaccuracies in the previous (first-pass) implementation

1. **Atomicity claim was false.** The first-pass described record-then-check
   atomicity, but the code committed a Transaction A (cleanup + SELECT FOR UPDATE)
   and then recorded the attempt in a *separate* bare `INSERT … ON DUPLICATE KEY
   UPDATE` outside any transaction. Concurrent requests all read the counter
   before any of them had recorded an attempt, so N concurrent requests could all
   pass the limit check simultaneously.

2. **Window reset was never implemented.** The first-pass ODK UPDATE only
   incremented `attempts` and set `last_attempt = NOW()`. It never reset
   `window_start`. A row whose window had expired (window_start > 15 min ago)
   continued accumulating attempts without ever resetting, but the check skipped
   it (windowAge >= lockoutSeconds). An attacker who kept `last_attempt` current
   (preventing cleanup deletion) while `window_start` aged out could bypass the
   limit indefinitely.

3. **Email hard-lockout DoS risk was not resolved.** Raising the email limit
   from 5 to 10 was claimed to "reduce the effectiveness of a DoS attack." A
   threshold of 10 is still a fixed hard lockout that any unauthenticated attacker
   can trigger by sending 11 failed requests for an organizer's email from any
   IP address or combination of IPs.

4. **HMAC secret had a silently-degrading fallback.** The first-pass kept the
   DSN-derived fallback with a logged warning. A missing or placeholder secret
   produced a weaker but functional key, meaning throttling appeared to work
   while actually using a potentially predictable key.

5. **Cleanup LIMIT was missing.** The DELETE ran without LIMIT, potentially
   deleting an unbounded number of expired rows on a single request. The comment
   claimed the index "bounds" the delete, but an index only speeds lookup; it does
   not limit how many rows are removed.

6. **Retention claim was inaccurate.** The first-pass stated "rows are guaranteed
   to be deleted within 15 minutes … regardless of traffic volume." Cleanup runs
   only on login POST requests; on zero-traffic installations, rows persist
   indefinitely until the next request.

7. **Email key was derived even for invalid inputs.** The first-pass derived and
   stored an email throttle key for any input, including empty strings, syntactically
   invalid addresses, and addresses longer than 191 characters.

### Changes in this revision

**`src/auth.php`** — Rewritten to correct all six code defects above:
- **Record-then-check atomicity:** The attempt is now recorded via
  `INSERT … ON DUPLICATE KEY UPDATE` inside a transaction, and the resulting
  counter is read back with `SELECT … FOR UPDATE` before deciding to block.
  password_verify() runs after the transaction commits.
- **Atomic window reset in ODK UPDATE:** The ODK UPDATE expression uses
  `IF(TIMESTAMPDIFF(SECOND, window_start, NOW()) >= lockoutSeconds, 1, attempts+1)`
  to atomically reset `attempts` and `window_start` when the window has expired,
  in a single SQL statement. No separate check-then-write race is possible.
- **IP-only hard lockout; email key is audit-only:** The independent per-email
  hard lockout is removed. Only the IP counter (50 attempts per 15-minute window)
  triggers HTTP 429. Email keys are still recorded in `login_attempts` for audit,
  but they do not cause a lockout. This eliminates the account-denial DoS vector.
- **Mandatory HMAC secret — no fallback:** `throttle_hmac_secret()` now returns
  `false` (not a string) when the key is missing, empty, shorter than 64
  characters, or is still the placeholder. `auth.php` checks for `false` and
  fails closed with a logged operator message and a generic user error. No
  login is processed and no throttle record is written until the secret is set.
- **Email key only for syntactically valid emails:** The email HMAC key is
  derived and stored only when the normalized email passes FILTER_VALIDATE_EMAIL
  and is within the 191-character limit. Invalid, empty, or overlong submissions
  are throttled only by the IP counter.
- **Bounded cleanup (LIMIT 50):** The cleanup DELETE now includes `LIMIT 50` to
  bound the number of rows removed per request, making cleanup cost predictable.

**`src/bootstrap.php`** — `throttle_hmac_secret()`:
- Return type changed to `string|false`.
- DSN-derived fallback removed entirely.
- Explicit rejection of the placeholder string from `db.example.php`.
- Length check: must be ≥ 64 characters.

**`config/db.example.php`** — Comment block updated:
- States "REQUIRED — no fallback."
- States the placeholder is explicitly rejected by the application.
- Clarifies minimum strength (64 hex chars = 32 random bytes).

**`docs/provisioning.md`** — Updated:
- Step 2: Fallback reference removed; states the secret is mandatory with no fallback.
- RA 10173 Retention section: corrected to state cleanup runs on login requests only,
  rows are NOT guaranteed to be deleted within 15 minutes under zero traffic, and
  a cron job is required for a wall-clock guarantee (with example SQL provided).
- RA 10173 Lockout policy section: rewritten to describe IP-only hard lock (50/window);
  email key is audit-only; documents that distributed attacks below the per-IP
  threshold require external controls (WAF).

**`docs/verification_fr01.md`** — Rewritten:
- Tests 11–19 (replacing the previous 11–17) with concrete database queries and
  exact expected outcomes for:
  - Test 11: IP throttling, window expiry reset.
  - Test 12: Simultaneous requests at and beyond the configured limit (Sub-A and B).
  - Test 13: Expired-window reset and bypass-attempt verification.
  - Test 14: Account-denial resistance from one IP.
  - Test 15: Account-denial resistance from multiple IPs.
  - Test 16: HMAC secret missing / placeholder / too short / valid (Sub-A through D).
  - Test 17: Malformed/empty/overlong/case-variant email (Sub-A through D).
  - Test 18: Cleanup behavior and accurate retention guarantee statement.
  - Test 19: Migration from old schema.
- Removed incorrect "mechanism" note claiming `ON DUPLICATE KEY UPDATE …` is
  atomic "even without the transaction wrapper" (the wrapper IS required for
  the record-then-check design).
- Added explicit note that the retention guarantee is request-driven, not
  wall-clock.

### Validation performed in this session

- PHP syntax checks via `C:\php\php.exe -l` on `src/auth.php`,
  `src/bootstrap.php`, `config/db.example.php`, `src/event_create.php`, and
  `public/login.php`. All five passed with no errors.
- Schema consistency review: `window_start` column, `VARCHAR(77)` id width, and
  `idx_login_attempts_last` index confirmed consistent across `schema.sql`,
  `schema_update_throttle.sql`, and the PHP ODK INSERT statement.
- Diff reviewed for secrets, personal data, upload paths, and AGENTS.md
  violations. None found. The error log message in auth.php and bootstrap.php
  contains no secret value, no raw IP, and no email address.
- No live MySQL connection, concurrent-request test, or browser session was run.
  Manual verification per `docs/verification_fr01.md` Tests 11–19 is required
  before claiming the throttling system is production-ready.

## 2026-10-17 — Review Corrections: Throttle Semantics and Migration Safety

- **`src/auth.php`**: Corrected terminology to describe the implementation as a
  fixed 15-minute window and clarify that every login POST is counted before
  credential verification. Added the `ip\0` / `email\0` domain labels to HMAC
  input as documented. Successful logins no longer clear the shared IP counter,
  preventing one user's login from resetting the network bucket for others.
- **`src/bootstrap.php`, `config/db.example.php`**: The configured HMAC key must
  now be exactly 64 hexadecimal characters. Arbitrary long strings and the
  example placeholder are rejected; there is no fallback.
- **`schema_update_throttle.sql`**: Made reruns preserve the current table and
  its counters. The migration drops only a legacy table without `window_start`,
  creates the current schema if absent, and adds the cleanup index if needed.
  Documentation now states that migrating a legacy table discards its counters,
  including active ones, so operators can plan a maintenance window.
- **`schema.sql`, `docs/provisioning.md`, `docs/verification_fr01.md`**: Aligned
  schema comments and manual checks with fixed-window counting, login-POST
  semantics, request-driven bounded cleanup, scheduled cleanup instructions,
  and safe migration reruns. Corrected the description of HMAC digests: the
  secret permits candidate testing but does not reverse the digest.
- Validation: Reviewed the modified PHP, SQL, and documentation for consistency.
  Runtime syntax checks and database/concurrency verification have not yet been
  run in this session; no live MySQL instance was used.

### Validation update

- Ran `C:\php\php.exe -l` on `src/auth.php`, `src/bootstrap.php`, and
  `config/db.example.php`; all three reported no syntax errors.
- `git diff --check` reported no whitespace errors. Git emitted only its
  configured LF-to-CRLF conversion warnings.
- A MySQL client/server was not available, so the migration SQL and concurrent
  request behavior remain unverified against a live database.

## 2026-10-17 — Local MariaDB and Login-Throttle Verification

- Started a disposable database (`dalo_throttle_review_20261017_a6f42d8e`) and used a temporary copy of the application with test-only credentials and a test-only IP override route. No production database or credentials were used.
- Migration checks passed for a fresh install, cleanup index creation, idempotent rerun with counters preserved, and upgrades from both legacy throttle-table layouts.
- HTTP checks passed for HMAC-only IP/email keys, normalized email keys, fixed-window expiry, invalid-secret fail-closed behavior, the 50th same-IP POST returning HTTP 429, valid login from a different IP, and no account-wide lockout from distributed attempts.
- A 20-worker concurrent database test preserved all 20 increments. This exercised the same atomic MySQL upsert pattern under MariaDB 10.4.32; XAMPP's local server is MariaDB, so Oracle MySQL-specific compatibility was not separately tested.
- PHP lint passed for `src/auth.php`, `src/bootstrap.php`, and `public/login.php`. `git diff --check` found no whitespace errors; Git displayed configured LF-to-CRLF warnings only.
- These checks do not replace the documented full manual verification procedure or a production HTTPS/deployment review.
- Final documentation review corrected Test 11's skipped step number and its
  expected counter value: blocked login POSTs are still counted, so attempts can
  exceed the threshold after additional blocked requests.

## 2026-10-18 — Student Registration and QR Delivery

- Added the public student registration route and service for SRS FR-02/03/04/09/11. It collects only full name, student ID, and email; rejects multipart/file payloads and unexpected fields; validates CSRF; and displays the configured privacy notice before data collection.
- Reuses the existing `users` and `registrations` schema; no SQL migration is required. New students receive an undisclosed random credential hash because student login is not defined in the SRS. Existing student identities are reused only when submitted identity fields match.
- Serializes registration requests on the event row and checks capacity and duplicate registrations in the same transaction. QR credentials use `random_bytes(32)`, a URL-safe token, and a unique SHA-256 binary hash. The raw token is not stored or put in a URL; the no-cache response and confirmation email contain the QR image.
- Added an event-specific registration link after organizer event creation, a single-file QR SVG encoder with its MIT license and pinned upstream commit, email configuration, and a CLI retention task for expired event registrations and orphan student identities.
- Added privacy/mail/retention configuration placeholders and deployment instructions. Registration returns HTTP 503 until university-approved controller/contact, lawful-basis, sharing, rights, retention, and sender settings are configured. Hosting-provider backups and student mailbox copies require separate retention controls.
- Added `docs/verification_fr02.md` with manual checks for validation, uploads, duplicate/capacity concurrency, QR storage, mail delivery, cache headers, and retention.
- Validation: PHP syntax checks passed for the new registration service/route, event-creation files, retention task, QR library, and example config. `git diff --check` found no whitespace errors; Git emitted its configured LF-to-CRLF conversion warnings. No browser, mail-delivery, or database integration checks were run.
