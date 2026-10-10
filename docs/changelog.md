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
