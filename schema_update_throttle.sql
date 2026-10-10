-- ---------------------------------------------------------------------------
-- Daló Schema Update: Login Throttling
-- Run this script to add the login_attempts table to an existing installation.
-- Fresh installations using schema.sql do not need to run this.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`           VARCHAR(191)     NOT NULL COMMENT 'Format: ip:<address> or email:<address>',
    `attempts`     INT UNSIGNED     NOT NULL DEFAULT 1,
    `last_attempt` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT `pk_login_attempts` PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
