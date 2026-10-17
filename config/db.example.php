<?php

/**
 * Database configuration template.
 *
 * IMPORTANT — DO NOT commit a filled-in copy of this file.
 * Copy this file to config/db.php (which is git-ignored), fill in the real
 * values, and ensure the production copy lives outside the web root or is
 * protected by the server configuration.
 *
 * Use a least-privilege MySQL account that can only SELECT, INSERT, UPDATE,
 * and DELETE on the Daló database. Do not use a root or admin account.
 */
return [
    'dsn'      => 'mysql:host=127.0.0.1;dbname=YOUR_DB_NAME;charset=utf8mb4',
    'username' => 'YOUR_DB_USER',
    'password' => 'YOUR_DB_PASSWORD',
    'options'  => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],

    // ---------------------------------------------------------------------------
    // HMAC secret for login-throttle keys (REQUIRED — no fallback).
    //
    // This secret is used to HMAC the raw IP address and email address before
    // storing them as throttle keys. Raw IPs and email addresses are never
    // written to the database; only the HMAC digest is stored, satisfying the
    // RA 10173 data-minimisation requirement.
    //
    // Generate a value once per deployment with:
    //   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    //
    // Rules:
    //  • Must be exactly 64 hexadecimal characters generated from 32 random
    //    bytes. Other formats and lengths are rejected by the application.
    //  • Must NOT be committed to version control.
    //  • Set once per deployment. Rotating the secret resets all active
    //    in-progress lockouts, because existing digests no longer match.
    //  • There is NO fallback. If this key is missing, empty, not exactly 64
    //    hex characters, or is the placeholder string below, the application
    //    returns a generic configuration error on every login attempt.
    //    The placeholder string is EXPLICITLY REJECTED by the application.
    // ---------------------------------------------------------------------------
    'throttle_hmac_secret' => 'REPLACE_WITH_64_HEX_CHARS_FROM_bin2hex_random_bytes_32',

    // ---------------------------------------------------------------------------
    // Student registration: approved privacy notice and confirmation email.
    // Registration remains unavailable until all privacy placeholders below
    // are replaced with university-approved values. Do not guess legal terms.
    // ---------------------------------------------------------------------------
    'registration_privacy_controller' => 'REPLACE_WITH_APPROVED_DATA_CONTROLLER',
    'registration_privacy_contact' => 'REPLACE_WITH_PRIVACY_CONTACT_EMAIL',
    'registration_privacy_lawful_basis' => 'REPLACE_WITH_PRIVACY_OFFICER_APPROVED_LAWFUL_BASIS',
    'registration_privacy_retention' => 'REPLACE_WITH_APPROVED_RETENTION_AND_DELETION_PERIOD',
    'registration_privacy_rights' => 'REPLACE_WITH_APPROVED_DATA_SUBJECT_RIGHTS_AND_REQUEST_PROCESS',
    'registration_privacy_sharing' => 'REPLACE_WITH_APPROVED_RECIPIENTS_AND_SERVICE_PROVIDERS',
    // Integer days, approved by the university privacy officer. The scheduled
    // scripts/purge_event_data.php task enforces this retention period.
    'registration_retention_days' => 0,
    'registration_mail_from_address' => 'REPLACE_WITH_VERIFIED_SENDER_EMAIL',
    'registration_mail_from_name' => 'Dalo Event Registration',
];
