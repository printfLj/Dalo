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
];
