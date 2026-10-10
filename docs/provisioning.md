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
- **HTTPS Enforcement:** The hosting environment is configured to block or redirect HTTP traffic to HTTPS *before* this system is made accessible. Do not provision accounts if the server is accessible over unencrypted HTTP.

---

## Step 1 — Generate a secure password hash locally

On any machine with PHP 8.x installed, run the following in a bash-compatible shell
to generate the hash securely without echoing the password or saving it to your shell history:

```bash
read -s -p "Enter secure password: " pass; echo
echo -n "$pass" | php -r '$pw = stream_get_contents(STDIN); if(strlen($pw) < 12) { echo "Password too short\n"; exit(1); } echo password_hash($pw, PASSWORD_BCRYPT, ["cost" => 12]) . "\n";'
unset pass
```

Copy the printed hash (begins with `$2y$12$`).
Do **not** save the plaintext password to any file, and do not pass it via command-line arguments.

> **Shared-hosting alternative** — if you cannot run PHP from the CLI, use a
> local tool (e.g. a secure PHP script on your own machine) to produce the hash,
> copy only the hash, and discard the tool immediately.

---

## Step 2 — Insert the organizer row

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

## Step 3 — Verify the account

```sql
SELECT id, role, full_name, email, created_at
  FROM users
 WHERE email = 'organizer@your-domain.example';
```

Confirm the row exists with `role = 'organizer'` and that `password_hash`
starts with `$2y$` or `$argon2`. Do **not** print the hash in any report.

---

## Step 4 — Sign in to the application

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
  Steps 1–3 with the appropriate role value.

---

## RA 10173 (Data Privacy) notes

Organizer accounts are considered personal data. Record the lawful basis for
creating this account, obtain any required consent or institutional approval,
and apply the project's retention and deletion policy when the account is no
longer needed.

**Login Throttling Data:** To mitigate brute-force attacks, the application temporarily logs IP addresses and email addresses in the `login_attempts` table. This data is strictly for security purposes, is automatically purged (typically within 15 to 24 hours), and must not be used for tracking or analytics. Limit direct database access to prevent unauthorized viewing of these security logs.
