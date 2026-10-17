# Daló — First-Organizer Provisioning Procedure

## Purpose

Daló has no public self-registration or bootstrap endpoint. The first organizer
account must be created directly in the database by a trusted administrator,
using a one-time CLI script run on the server. This procedure keeps the
plaintext password off the network and out of committed code.

---

## Prerequisites

- You have shell access (SSH or the hosting control-panel terminal) to the
  server where MySQL is running.
- The schema has already been imported (`schema.sql`).
- You have MySQL credentials with INSERT permission on the Daló database.
- **HTTPS Enforcement:** The hosting environment is configured to block or redirect
  HTTP traffic to HTTPS *before* this system is made accessible. Do not provision
  accounts if the server is accessible over unencrypted HTTP.

---

## Step 1 — Generate a secure password hash locally

On any machine with PHP 8.x installed, run the following in a bash-compatible
shell. Using `read -s -r` ensures the password is not echoed to the terminal
(`-s`), and that backslashes in the password are preserved literally rather than
being consumed as escape characters (`-r`).

```bash
read -s -r -p "Enter secure password: " pass; echo
printf '%s' "$pass" | php -r '$pw = stream_get_contents(STDIN); if (mb_strlen($pw) < 12) { fwrite(STDERR, "Password too short\n"); exit(1); } echo password_hash($pw, PASSWORD_BCRYPT, ["cost" => 12]) . "\n";'
unset pass
```

> **Why `printf '%s'` instead of `echo`?** `echo "$pass"` appends a newline
> that would become part of the password PHP sees. `printf '%s'` does not.

> **Why `read -r`?** Without `-r`, a password such as `abc\def` would be read
> as `abcdef`; the backslash would be silently dropped. `-r` preserves it.

Copy the printed hash (begins with `$2y$12$`).

Do **not**:
- Save the plaintext password to any file.
- Pass it via command-line arguments (visible in `ps` output and shell history).
- Use `echo "$pass" | php …` (appends a newline to the password).
- Use `php -r '… echo password_hash("hardcoded", …)'` (visible in history).

> **Shared-hosting alternative** — if you cannot run PHP from the CLI, use a
> local tool (e.g. a secure PHP script on your own machine) to produce the hash,
> copy only the hash, and discard the tool immediately.

---

## Step 2 — Generate and set the throttle HMAC secret

Login throttling uses an HMAC-keyed digest so that raw IP addresses and email
addresses are never stored in the database. The HMAC secret must be generated
once per deployment and added to `config/db.php` (which is git-ignored).

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Copy the printed 64-character hex string. Open `config/db.php` and add:

```php
'throttle_hmac_secret' => 'PASTE_THE_64_HEX_STRING_HERE',
```

Rules:
- **Never commit** `config/db.php` (it is already listed in `.gitignore`).
- Set this value **once** per deployment and do not rotate it while the
  `login_attempts` table has active rows (rotating effectively clears all
  in-progress lockouts).
- There is **no fallback**. If this key is missing, not exactly 64 hexadecimal
  characters, or is still the placeholder string, the application returns a
  generic system error on every login attempt. This is by design: a predictable or absent
  secret would allow an attacker to reconstruct throttle keys.

---

## Step 3 — Insert the organizer row

Connect to MySQL and run:

```sql
INSERT INTO users (role, full_name, email, password_hash)
VALUES (
    'organizer',
    'Your Full Name',
    'organizer@your-domain.example',
    '$2y$12$REPLACE_WITH_ACTUAL_HASH_FROM_STEP_1'
);
```

Replace every placeholder value. The `$` characters in the hash must be
included exactly as printed; use a MySQL client that handles them correctly
(e.g. the `mysql` CLI or phpMyAdmin's SQL editor).

---

## Step 4 — Verify the account

```sql
SELECT id, role, full_name, email, created_at
  FROM users
 WHERE email = 'organizer@your-domain.example';
```

Confirm the row exists with `role = 'organizer'` and that `password_hash`
starts with `$2y$` or `$argon2`. Do **not** print the hash in any report.

---

## Step 5 — Sign in to the application

Open `https://your-domain.example/login.php` and sign in with the email and
password you chose. The application will redirect you to the event creation
page on success.

---

## Security reminders

- **Do not** create a public `/provision.php`, `/install.php`, or `/setup.php`
  endpoint. There is no such file in this project, and none should be added.
- **Do not** embed a default password or a seeded user in `schema.sql` or any
  committed file.
- **Do not** email or paste the plaintext password. Share only temporary access
  to the MySQL command line if another person needs to complete this step.
- After provisioning, revoke or remove any elevated MySQL credentials used
  solely for this step; the application's runtime account needs only SELECT,
  INSERT, UPDATE, and DELETE on the Daló database.
- Additional organizer or facilitator accounts can be added by repeating
  Steps 1 and 3–4 with the appropriate role value.

---

## RA 10173 (Data Privacy) notes

Organizer accounts are considered personal data. Record the lawful basis for
creating this account, obtain any required consent or institutional approval,
and apply the project's retention and deletion policy when the account is no
longer needed.

### Login Throttling and RA 10173

**What is stored:** The `login_attempts` table stores domain-separated
HMAC-SHA-256 digests of the raw IP address and normalized email address, a
login-POST count, and timestamps. **Raw IP addresses and raw email addresses
are never written to the database.** The HMAC secret (stored only in
`config/db.php`, outside version control) does not make HMAC digests reversible;
it would allow a party with both the database and secret to test candidate
addresses. Protect both accordingly.

**Purpose:** Strictly to mitigate brute-force and credential-stuffing attacks
on the login form. The data is not used for tracking, analytics, or any other
purpose.

**Retention:** Each row uses a 15-minute fixed window. The
application deletes up to 50 expired rows (measured from `last_attempt`) at
the start of every login POST request, using an indexed DELETE bounded by
`LIMIT 50` so cleanup work per request is predictable. Because cleanup only
runs during login requests, rows are **not** guaranteed to be deleted within
15 minutes under zero-traffic or very-low-traffic conditions; they persist
until the next login POST arrives. For a wall-clock cleanup target, configure
a scheduled task to run every five minutes and repeat the following batch until
fewer than 200 rows are deleted:

```sql
DELETE FROM login_attempts
 WHERE last_attempt < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
 LIMIT 200;
```

**Access:** Direct database access is restricted to the database account
configured in `config/db.php` (least-privilege: SELECT, INSERT, UPDATE, DELETE
on Daló tables only). Administrators with direct MySQL access can see the HMAC
digests but not the raw IP or email values without the HMAC secret.

**Lockout policy:** Only the per-IP counter triggers a hard lockout (HTTP 429):
the 50th login POST in a 15-minute fixed window is blocked. Every POST is counted
before credentials are checked, so the threshold includes correct credentials.
There is **no independent
per-email hard lockout**, because any fixed threshold allows an unauthenticated
attacker on any number of IPs to deny a legitimate organizer access by
deliberately sending N+1 failed requests for that account. The IP limit stops
automated credential-stuffing from a single source. Distributed attacks that
stay below the per-IP threshold are not addressable by on-server throttling
alone and require external controls (WAF, hosting-provider rate limiting).

Per-account (email-keyed) attempts are still recorded in `login_attempts` for
short-lived security analysis but do not trigger a lockout. A successful login
does not clear the IP counter; this prevents one user's successful login from
resetting the shared-network limit for other users. Counters reset when the
fixed window expires.

The lockout window resets atomically when the first login POST arrives after
the previous window has expired — the counter restarts at 1 without requiring
a separate cleanup or manual intervention.

**Data-subject rights:** Because raw email addresses are not stored, the system
cannot directly look up a specific individual's throttle record. Throttle rows
expire on a 15-minute fixed window (practical retention depends on traffic;
see Retention above).

## Student registration configuration and retention

Student registration is deliberately unavailable until the deployment's
approved privacy notice and verified email sender are configured in the
git-ignored `config/db.php`. Copy the corresponding keys from
`config/db.example.php` and replace every `REPLACE_WITH_...` value. The
controller, privacy contact, lawful basis, recipients/service providers,
retention period, and data-subject request process must be approved by the
university privacy officer; do not infer these values from the sample. The
privacy contact and sender fields must be valid email addresses. Set
`registration_retention_days` to a positive integer (1–36,500) that exactly
matches the approved notice's retention period. The notice must also describe
how long the student-held confirmation email/QR copy is retained; Daló cannot
delete messages from a student's mailbox.

PHP's `mail()` must be configured by the host to deliver the confirmation with
the QR attachment. A false return is shown to the student; the registration
remains saved and the no-cache success page still offers the QR download. Verify
mail delivery on the deployed host before inviting students.

Schedule `scripts/purge_event_data.php` through the hosting control panel's
trusted PHP CLI scheduler using the approved retention interval. The script
removes registrations and attendance timestamps for events older than the
configured period, then removes orphan student identity rows older than the
same cutoff. It outputs counts only and never outputs personal data or QR
tokens. Configure hosting-provider backup retention and deletion separately;
this application script cannot erase provider-managed backups. Test the task
against disposable data before scheduling it against production records.
