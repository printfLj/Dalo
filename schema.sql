-- =============================================================================
-- Daló — Event Registration & QR Attendance Schema
-- Compatible with: MySQL 5.7+ and MySQL 8.x (InnoDB, utf8mb4)
-- Character set: utf8mb4 / utf8mb4_unicode_ci
--
-- SECURITY CONTRACT — QR credential column (registrations.qr_token_hash):
--   • The PHP application MUST generate a bearer token using random_bytes(32)
--     and encode it with a URL-safe scheme (e.g. base64url or bin2hex) before
--     embedding it in the QR code.
--   • Only the SHA-256 hash of that raw token is stored here as BINARY(32).
--     Raw tokens MUST NOT be persisted, logged, or returned in responses.
--   • Comparison at scan time MUST decode the scanned URL-safe value back
--     to its original raw bytes first (e.g. base64url_decode() or hex2bin()),
--     then compute SHA-256 of those raw bytes, and finally compare with
--     hash_equals() (constant-time). Hashing the encoded string directly
--     will never match the stored hash and will silently break check-in.
--   • Never store an auto-increment ID, student ID, timestamp, event ID, or
--     any other predictable value as the credential — this column is hashed
--     bearer-token only.
--   • The UNIQUE constraint enforces token-hash uniqueness at the database
--     level. On the (astronomically rare) collision, the application MUST
--     regenerate a fresh token and retry the INSERT.
--
-- FOREIGN-KEY DELETE BEHAVIOR:
--   • Deleting a user or event is RESTRICTED while dependent rows exist.
--     Registrations and attendance history are preserved until explicitly
--     removed through an authorized data-management operation.
--
-- NOT INCLUDED: DROP/RESET statements, sample data, credentials, or uploads.
-- =============================================================================

-- SET NAMES sets the connection character set independently of the selected
-- database. SET CHARACTER SET is redundant here and has been removed.
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Table: users
-- Stores organizers, facilitators, and students.
-- Personal data (name, student_id) is subject to RA 10173 (Data Privacy Act).
-- ---------------------------------------------------------------------------
CREATE TABLE `users` (
    `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `role`         ENUM('organizer','facilitator','student')
                                    NOT NULL,
    -- Full name: personal data — access restricted to authorized roles only.
    `full_name`    VARCHAR(255)     NOT NULL,
    -- Email used for login; must be unique across all roles.
    -- VARCHAR(191): utf8mb4 uses up to 4 bytes per character.
    -- 191 × 4 = 764 bytes, which fits within MySQL 5.7 InnoDB's 767-byte
    -- key-prefix limit on the unique index. The application MUST reject
    -- email addresses longer than 191 characters during validation.
    `email`        VARCHAR(191)     NOT NULL,
    -- bcrypt/Argon2 hash produced by PHP password_hash(). Plaintext MUST
    -- never be stored here.
    `password_hash` VARCHAR(255)    NOT NULL,
    -- Optional institutional student ID. Personal data per RA 10173.
    -- NULL is permitted for organizers/facilitators who have no student ID;
    -- a non-NULL value must be unique across the table.
    -- (NULL values do not violate a UNIQUE index in MySQL.)
    `student_id`   VARCHAR(50)      NULL DEFAULT NULL,
    `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT `pk_users` PRIMARY KEY (`id`),
    CONSTRAINT `uq_users_email`      UNIQUE (`email`),
    -- Partial-unique index that allows many NULLs but no duplicate non-NULL IDs.
    CONSTRAINT `uq_users_student_id` UNIQUE (`student_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Table: login_attempts
-- Tracks login POST attempts by IP address and (for valid addresses) email to
-- mitigate brute-force attacks. The `id` column stores a domain-prefixed
-- HMAC-SHA-256 hex digest; raw IP/email values are never persisted here.
--
-- Throttling uses fixed 15-minute windows. At most 50 expired rows are deleted
-- during each login POST using the last_attempt index. This bounds deletions
-- per request, but does not guarantee wall-clock erasure during inactivity;
-- configure scheduled cleanup when a strict retention deadline is required.
-- ---------------------------------------------------------------------------
CREATE TABLE `login_attempts` (
    `id`           VARCHAR(77)      NOT NULL
                   COMMENT 'ip:/email: prefix plus domain-separated HMAC-SHA-256 hex; no raw IP/email.',
    `attempts`     INT UNSIGNED     NOT NULL DEFAULT 1,
    `window_start` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                   COMMENT 'Timestamp of the first counted login POST in the current fixed window.',
    `last_attempt` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT `pk_login_attempts` PRIMARY KEY (`id`),

    -- Supports the bounded-retention DELETE that runs on every login request.
    -- Without this index, the cleanup DELETE would scan the entire table.
    INDEX `idx_login_attempts_last` (`last_attempt`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Short-lived login throttling counters using fixed windows.';

-- ---------------------------------------------------------------------------
-- Table: events
-- Stores events created and owned by organizers.
-- ---------------------------------------------------------------------------
CREATE TABLE `events` (
    `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `title`        VARCHAR(255)     NOT NULL,
    `event_date`   DATE             NOT NULL,
    `event_time`   TIME             NOT NULL,
    `location`     VARCHAR(500)     NOT NULL,
    -- Maximum number of attendees. INT UNSIGNED permits zero, and MySQL 5.7
    -- does not enforce CHECK constraints. Positive-capacity enforcement is
    -- provided by the trg_events_capacity_insert and trg_events_capacity_update
    -- triggers defined below, which SIGNAL an error if capacity < 1.
    -- The application MUST also validate capacity > 0 before any INSERT/UPDATE.
    `capacity`     INT UNSIGNED     NOT NULL,
    -- Organizer who created the event. RESTRICT prevents silent cascade
    -- deletion of the event when the organizer account is removed.
    `organizer_id` INT UNSIGNED     NOT NULL,
    `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT `pk_events`              PRIMARY KEY (`id`),
    CONSTRAINT `fk_events_organizer_id` FOREIGN KEY (`organizer_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    -- Index for querying events by organizer (dashboard, management screens).
    INDEX `idx_events_organizer_id` (`organizer_id`),
    -- Index for chronological event listings.
    INDEX `idx_events_event_date`   (`event_date`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Triggers: enforce positive capacity on the events table.
--
-- MySQL 5.7 silently ignores CHECK constraints, so BEFORE INSERT and
-- BEFORE UPDATE triggers are used instead. Each trigger raises
-- SQLSTATE '45000' (generic user-defined error) if the supplied capacity
-- is zero or would become zero after an update.
--
-- Run this file with DELIMITER $$ or pipe it through a client that supports
-- multi-statement blocks (e.g. mysql CLI, phpMyAdmin, Adminer). The
-- DELIMITER directive is a client-side instruction and must not be placed
-- inside a stored program body.
-- ---------------------------------------------------------------------------

DELIMITER $$

CREATE TRIGGER `trg_events_capacity_insert`
BEFORE INSERT ON `events`
FOR EACH ROW
BEGIN
    IF NEW.capacity < 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'events.capacity must be greater than zero.';
    END IF;
END$$

CREATE TRIGGER `trg_events_capacity_update`
BEFORE UPDATE ON `events`
FOR EACH ROW
BEGIN
    IF NEW.capacity < 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'events.capacity must be greater than zero.';
    END IF;
END$$

DELIMITER ;

-- ---------------------------------------------------------------------------
-- Table: registrations
-- One row per (event, student) pair. Holds the QR credential hash and the
-- check-in timestamp for attendance recording.
-- ---------------------------------------------------------------------------
CREATE TABLE `registrations` (
    `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `event_id`        INT UNSIGNED     NOT NULL,
    `user_id`         INT UNSIGNED     NOT NULL,

    -- -------------------------------------------------------------------------
    -- QR CREDENTIAL HASH — read the security contract at the top of this file.
    --
    -- Registration flow:
    --   $raw   = random_bytes(32);              // 32 cryptographically random bytes
    --   $token = base64url_encode($raw);        // URL-safe string embedded in the QR
    --   $hash  = hash('sha256', $raw, true);    // binary=true → raw 32-byte digest
    --   INSERT … qr_token_hash = $hash          // only the hash is stored here
    --
    -- Scan / check-in flow:
    --   $token = /* value read from QR code */;
    --   $raw   = base64url_decode($token);      // decode back to raw bytes FIRST
    --   $hash  = hash('sha256', $raw, true);    // compute SHA-256 of the raw bytes
    --   SELECT … WHERE qr_token_hash = $hash;  // look up in the database
    --   hash_equals($stored, $hash);            // constant-time comparison
    --   Hashing the encoded string instead of the raw bytes will produce a
    --   different digest and will never match the stored hash.
    --
    -- Do NOT populate this column with an auto-increment ID, user_id,
    -- event_id, or any other predictable or personally-identifying value.
    -- -------------------------------------------------------------------------
    `qr_token_hash`   BINARY(32)       NOT NULL,

    `registered_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- NULL until the facilitator or QR scanner records a successful check-in.
    -- A non-NULL value means the student has already been admitted; any
    -- subsequent scan for this registration MUST return "already checked in"
    -- and MUST NOT update this timestamp again.
    `checked_in_at`   DATETIME         NULL DEFAULT NULL,

    CONSTRAINT `pk_registrations` PRIMARY KEY (`id`),

    -- Enforce one registration per student per event at the database level.
    -- The application must also enforce capacity before issuing this INSERT.
    CONSTRAINT `uq_registrations_event_user`
        UNIQUE (`event_id`, `user_id`),

    -- Enforce uniqueness of QR credential hashes across all registrations.
    -- See the security contract above regarding token generation and storage.
    CONSTRAINT `uq_registrations_qr_token_hash`
        UNIQUE (`qr_token_hash`),

    -- Foreign key to events. RESTRICT prevents silent deletion of an event
    -- that still has registrations or attendance records attached.
    CONSTRAINT `fk_registrations_event_id` FOREIGN KEY (`event_id`)
        REFERENCES `events` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    -- Foreign key to users (the registered student). RESTRICT preserves the
    -- attendance history if the user account is deactivated rather than
    -- deleted; the application should use a soft-delete or anonymisation
    -- path for RA 10173 erasure requests.
    CONSTRAINT `fk_registrations_user_id` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    -- Index supporting per-event attendance reporting and the capacity check.
    INDEX `idx_registrations_event_id`    (`event_id`),

    -- Index supporting per-student registration history lookups.
    INDEX `idx_registrations_user_id`     (`user_id`),

    -- Index supporting attendance queries filtered by check-in status.
    INDEX `idx_registrations_checked_in`  (`event_id`, `checked_in_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
