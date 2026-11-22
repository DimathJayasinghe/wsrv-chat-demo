-- The tables of the chat. Loaded automatically the first time the database container starts
-- (docker-entrypoint-initdb.d), and again by the tests into their own database.
-- Times are unix seconds (BIGINT): no time zone surprises, easy to fake in tests.

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(20)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,             -- password_hash() output, never the password
    failed_logins TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until  BIGINT       NOT NULL DEFAULT 0,   -- too many wrong passwords: no login before this time
    created_at    BIGINT       NOT NULL,
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
    token_hash CHAR(64)     NOT NULL PRIMARY KEY,    -- SHA-256 of the cookie value, never the value
    user_id    INT UNSIGNED NOT NULL,
    expires_at BIGINT       NOT NULL,
    created_at BIGINT       NOT NULL,
    KEY ix_sessions_expires (expires_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED    NOT NULL,
    body       VARCHAR(2000)   NOT NULL,
    created_at BIGINT          NOT NULL,
    CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
