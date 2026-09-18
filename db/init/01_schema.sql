-- Base schema. Safe to run against a production database.
--
-- No USE statement: the target database is chosen by the connection, so the
-- same file works whatever the deployment calls it.

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    -- Wide enough for any bcrypt hash, with room for a future algorithm.
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM ('user', 'admin') NOT NULL DEFAULT 'user',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title       VARCHAR(160) NOT NULL,
    description TEXT NULL,
    status      ENUM ('todo', 'in-progress', 'done') NOT NULL DEFAULT 'todo',
    priority    ENUM ('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    due_date    DATE NULL,
    user_id     INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),

    -- Indexes are shaped around the list endpoint, which always filters and
    -- always orders. Measurements behind every choice are in docs/schema.md.
    --
    -- The regular user's request: owner, optionally status, newest first. The
    -- trailing created_at lets one index satisfy the filter and the sort
    -- together, and its user_id prefix is what the foreign key below needs, so
    -- no separate index on user_id is required.
    KEY idx_tasks_owner_status_created (user_id, status, created_at),

    -- The admin's request spans every owner, so it needs its own ordered
    -- entry points. Without these, a status filter reads and sorts every
    -- matching row just to return ten of them.
    KEY idx_tasks_status_created (status, created_at),
    KEY idx_tasks_priority_created (priority, created_at),
    KEY idx_tasks_created (created_at),

    -- Sorting by title is only survivable for an admin with an index: a
    -- filesort over 500k VARCHAR(160) values spills to disk and takes tens of
    -- seconds.
    KEY idx_tasks_title (title),

    -- Ordering puts undated tasks last via "due_date IS NULL, due_date", and
    -- an expression in ORDER BY can only be served by a matching functional
    -- index. Requires MySQL 8.0.13 or newer.
    KEY idx_tasks_due_nulls_last ((due_date IS NULL), due_date),

    CONSTRAINT fk_tasks_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Refresh tokens are stored as SHA-256 hashes, never in the clear, so a dump of
-- this table cannot be replayed as a live session. Rows sharing a family_id are
-- one login's rotation lineage: presenting an already-rotated token means a copy
-- is circulating, so the whole family is revoked at once.
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL,
    family_id   CHAR(32) NOT NULL,
    expires_at  DATETIME NOT NULL,
    revoked_at  DATETIME NULL,
    replaced_by BIGINT UNSIGNED NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_refresh_tokens_hash (token_hash),
    KEY idx_refresh_tokens_user (user_id),
    KEY idx_refresh_tokens_family (family_id),
    KEY idx_refresh_tokens_expires (expires_at),
    CONSTRAINT fk_refresh_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
