-- =============================================================================
-- Daló Schema Update: Login Throttling
-- Compatible with MySQL 5.7+.
--
-- Select the Daló database before running this script. Do not run schema.sql
-- against an existing installation.
--
-- Legacy login_attempts rows are intentionally discarded when upgrading from
-- a schema without window_start; this clears active counters as well as
-- obsolete plaintext keys. Plan this security-counter reset during a
-- maintenance window. If the current table already has window_start, it is
-- retained; rerunning this migration will not erase current throttle counters.
-- =============================================================================

SET @dalo_db_name = DATABASE();
SET @dalo_table_exists = (
    SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = @dalo_db_name
       AND table_name = 'login_attempts'
);
SET @dalo_window_start_exists = (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = @dalo_db_name
       AND table_name = 'login_attempts'
       AND column_name = 'window_start'
);

-- Drop only a legacy table. The current schema includes window_start; keeping
-- it makes reruns safe and preserves active rate-limit counters.
SET @dalo_migration_sql = IF(
    @dalo_table_exists > 0 AND @dalo_window_start_exists = 0,
    'DROP TABLE `login_attempts`',
    'SELECT 1'
);
PREPARE dalo_migration_stmt FROM @dalo_migration_sql;
EXECUTE dalo_migration_stmt;
DEALLOCATE PREPARE dalo_migration_stmt;

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`           VARCHAR(77)      NOT NULL
                   COMMENT 'ip:/email: prefix plus domain-separated HMAC-SHA-256 hex, no raw IP/email.',
    `attempts`     INT UNSIGNED     NOT NULL DEFAULT 1,
    `window_start` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                   COMMENT 'Start of this fixed throttling window.',
    `last_attempt` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT `pk_login_attempts` PRIMARY KEY (`id`),
    INDEX `idx_login_attempts_last` (`last_attempt`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Short-lived login throttling counters.';

-- Ensure the cleanup index exists when a partially upgraded current table is
-- encountered. This also makes rerunning the migration safe.
SET @dalo_cleanup_index_exists = (
    SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = @dalo_db_name
       AND table_name = 'login_attempts'
       AND index_name = 'idx_login_attempts_last'
);
SET @dalo_migration_sql = IF(
    @dalo_cleanup_index_exists = 0,
    'ALTER TABLE `login_attempts` ADD INDEX `idx_login_attempts_last` (`last_attempt`)',
    'SELECT 1'
);
PREPARE dalo_migration_stmt FROM @dalo_migration_sql;
EXECUTE dalo_migration_stmt;
DEALLOCATE PREPARE dalo_migration_stmt;

-- Verify: DESCRIBE login_attempts; SHOW INDEX FROM login_attempts;
