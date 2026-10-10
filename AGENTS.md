# Daló Project Instructions

These instructions apply to the entire repository. Treat the approved Software Requirements Specification (SRS) as the product authority. Preserve its browser-based, mobile-first workflow, PHP/MySQL deployment constraints, and defined user roles. Do not invent product behavior that conflicts with it.

## Required stack and database access

- Implement the application in PHP 8.x with MySQL 5.7+ or MySQL 8.x, compatible with ordinary shared hosting and without requiring SSH or a Composer build on the production host.
- Use PDO for every application-to-MySQL connection. Configure exception error mode, disable emulated prepares where supported, and set the connection character set to `utf8mb4`.
- Use parameterized PDO prepared statements for every query containing runtime values. Never concatenate user input into SQL, including identifiers, sort expressions, or `LIMIT` clauses. For dynamic SQL structure, select from a fixed allowlist; bind values separately.
- Keep database credentials outside committed source control. Use least-privilege database credentials and never expose credentials, tokens, or personal data in error responses, logs, or source examples.
- Validate input server-side and encode output for its HTML/JSON context. Apply authorization checks on every protected server endpoint; client-side checks do not grant access.
- Protect state-changing browser requests against CSRF. Use secure session settings appropriate to HTTPS (`Secure`, `HttpOnly`, and an appropriate `SameSite` policy), rotate session IDs after authentication, and enforce role-based access for organizers and facilitators.
- Use database transactions and appropriate unique constraints/locking for capacity enforcement and check-in. Concurrent requests must not overbook capacity or record the same registration as present more than once.

## Registration and file handling

- Registration must never request, accept, process, or store uploaded files. Do not add file inputs, multipart registration handlers, screenshot/photo proof, or upload storage paths.
- Accept only the registration fields necessary for the documented purpose. Reject unexpected file payloads rather than silently storing or forwarding them.
- A successful registration must create its QR credential server-side and make the QR view/download available without requiring a file upload.

## QR credential and attendance rules

- QR codes must contain a cryptographically random, non-sequential, unguessable bearer token. Generate tokens with PHP's `random_bytes()` (at least 32 random bytes before encoding); never use an auto-increment ID, student ID, timestamp, event ID, or other predictable value as the credential.
- Encode tokens using a URL-safe representation. Enforce uniqueness with a database unique constraint and safely retry on the extremely unlikely collision.
- Store only a cryptographic hash of the raw QR token when feasible. Compare submitted tokens using a constant-time comparison after lookup; never log or expose raw tokens. Do not put personal information in the QR payload.
- Validate each scan on the server against the correct event and registration. A successful check-in is authoritative only after a database transaction commits. Enforce one check-in per registration with a unique constraint or atomic conditional update; duplicates must return an explicit “already checked in” result and must not increment counts.
- Treat manual name-search check-in as a privileged facilitator action and run it through the same server-side validation, authorization, and duplicate-prevention path as QR check-in.

## Facilitator offline and cache policy

- Serve the application only over HTTPS. A service worker may cache versioned static application assets needed to render the facilitator interface; use a deliberate cache version and remove obsolete versions on activation.
- Do not cache authenticated pages, API responses, attendee rosters, student names/IDs, QR tokens, attendance records, exports, or other personal/sensitive data in the Cache API, browser history, local storage, or service-worker storage. Set appropriate `Cache-Control: no-store` headers on sensitive responses.
- The browser may decode a QR locally, but the server is the source of truth. If the server cannot be reached, do not display a check-in as successful, update the authoritative attendance count, or claim the code was validated. Clearly mark the scan as pending/unconfirmed and require a successful online retry before entry is recorded.
- Do not persist a queue of raw QR tokens or identifiable check-in requests for later synchronization. This avoids replay, duplicate, and privacy risks. When connectivity returns, the facilitator must rescan or retry through the normal authenticated endpoint; the server must still reject duplicates atomically.
- Keep the documented manual fallback available when the server is reachable but camera/QR display is unavailable. Do not imply that name search works offline when the attendee roster is not cached.

## Data privacy and security (Republic Act No. 10173)

- Handle student names and student ID numbers as personal data and comply with the Republic Act No. 10173 (Data Privacy Act of 2012), its applicable implementing rules, and university policy. Minimize collection and use each field only for a stated, necessary event-registration or attendance purpose.
- Before collecting personal data, provide a clear privacy notice describing the purpose, data collected, responsible organization/contact, access or sharing, retention/deletion period, and applicable data-subject rights. Record the appropriate lawful basis and any required consent; do not assume that consent is always the only lawful basis.
- Restrict personal data to authorized roles and the minimum access needed. Require HTTPS in transit; protect data at rest through hosting/database safeguards; keep secrets out of the repository; redact personal data from logs; and avoid exposing it in URLs, QR payloads, analytics, or debug output.
- Define and enforce retention and secure deletion for registrations, attendance records, exports, backups, and facilitator device data. Do not retain event data indefinitely by default. Restrict attendance exports to authorized organizers and deliver them over authenticated, non-public routes.
- Provide a process for access/correction and other applicable data-subject requests, and for incident escalation and breach handling under university policy and applicable law. Do not claim legal compliance solely because these technical rules are present; escalate uncertain legal or institutional requirements to the project owner/university privacy officer.

## Product behavior and implementation constraints

- Keep student and staff interactions mobile-first and browser-based; do not require a native app. Use the browser camera through the approved client-side scanning approach, while keeping token validation and attendance writes on the server.
- Preserve the SRS behaviors: event capacity closes registration when full; duplicate scans are rejected; organizers can see registered-versus-present counts; facilitators have a manual check-in fallback; and attendance can be exported after an event by an authorized organizer.
- Keep schema and static assets lightweight for free-tier shared hosting. Use explicit foreign keys, indexes for lookup paths, and constraints that enforce event/registration relationships.
- Do not add dependencies that require a production Composer/SSH build unless the project owner explicitly approves a compatible deployment plan. Prefer the SRS's PHP QR-generation and browser-scanning constraints where those libraries are used.

## Change and session records

- At the end of every work session, update `docs/changelog.md` with the date, a concise description of the changes made, and any validation performed or reason validation was not performed. Include documentation/configuration changes as well as code changes. Never claim checks passed unless they were actually run.
- If `docs/changelog.md` does not exist, create it with a `# Changelog` heading and start an entry for the current session. Keep entries append-only; do not rewrite or remove prior history without an explicit request.
- Before finishing a session, review the diff for accidental secrets, personal data, uploads, or violations of these rules. Report any unresolved risk or unverified behavior to the user.
