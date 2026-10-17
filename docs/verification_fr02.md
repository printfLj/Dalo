# FR-02 Student Registration and QR Delivery Verification

Use a disposable database or disposable test records. Never use real student
personal data for these checks. Do not run the purge task against production
data as a test.

## Prerequisites

- Import `schema.sql` only on a fresh database. Existing deployments already
  have the required `users`, `events`, and `registrations` structures; this
  feature does not require a schema migration.
- Configure the approved privacy-notice values, positive
  `registration_retention_days`, and verified sender in ignored
  `config/db.php`, as described in `docs/provisioning.md`.
- Configure the host's PHP `mail()` transport and test delivery.
- Create an event using the organizer interface and share the event-specific
  `/register.php?event_id=<id>` link.

## Checks

1. Open the registration link. Confirm the event title/date/time/location,
   open/full status, and configured privacy notice appear before the form.
2. Confirm the form requests only full name, student ID, and email. Confirm
   there is no file input and the form does not use `multipart/form-data`.
3. Submit missing, malformed, overlong, and array-valued fields. Confirm no
   user or registration row is created and errors contain no SQL or personal
   data belonging to another student.
4. Submit an unexpected file payload and a multipart request. Confirm HTTP 400
   and confirm no database writes occur.
5. Submit valid disposable student details. Confirm the page immediately shows
   a downloadable QR, the email includes the QR attachment, and the QR contains
   only a URL-safe random token with no personal/event data.
6. Confirm the database stores only a 32-byte SHA-256 digest in
   `registrations.qr_token_hash`; confirm `users` has the student identity and
   only a password hash for an undisclosed random secret.
7. Register the same student for the same event again. Confirm HTTP 409, no
   second registration, and no new QR credential.
8. Register the same student for a different event. Confirm exactly one
   registration per event and the existing student record is reused only when
   all submitted identity fields match.
9. Set an event's registrations to capacity using disposable rows. Confirm its
   page says registration is closed and concurrent submissions cannot exceed
   capacity.
10. Submit requests with an invalid CSRF token and confirm HTTP 403 with no
    writes. Confirm successful responses use `Cache-Control: no-store` and
    `Referrer-Policy: no-referrer`.
11. Verify email success and mail-failure behavior. A mail failure must be
    clearly reported while the saved registration and downloadable QR remain
    available on the immediate response.
12. Run `scripts/purge_event_data.php` only against disposable records after
    setting an approved retention period. Confirm old event registrations and
    eligible orphan student identity rows are deleted in one transaction, and
    recent records remain.

Record actual outcomes, date/time, and tester role. Do not report checks as
passed unless they were actually executed.
